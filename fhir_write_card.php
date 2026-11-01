<?php

/**
 * fhir_write_card.php — the FHIR write workbench panel for the API Explorer.
 *
 * Included from oeApiExplorer.php alongside the Bulk $export card. All traffic goes
 * through fhir_write.php, which holds the session's bearer token, so nothing here
 * ever sees or stores a credential.
 *
 * Two modes:
 *   Single    pick a resource, edit the body, POST it, then PUT the result back and
 *             read it again -- the exploratory loop
 *   Suite     walk every resource POST -> PUT -> verify and print a pass/fail table
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

?>
<!-- FHIR writes — integrated; shares this Explorer's session token -->
<?php explorer_panel_open('fhirWrite', 'FHIR writes', ['subtitle' => 'POST / PUT workbench']); ?>

        <div class="form-row align-items-end">
            <div class="form-group col-md-3">
                <label class="small mb-1" for="fwResource">Resource</label>
                <select id="fwResource" class="form-control form-control-sm">
                    <option value="">— load the catalog first —</option>
                </select>
            </div>
            <div class="form-group col-md-2">
                <label class="small mb-1" for="fwVerb">Verb</label>
                <select id="fwVerb" class="form-control form-control-sm">
                    <option value="POST">POST</option>
                    <option value="PUT">PUT</option>
                </select>
            </div>
            <div class="form-group col-md-3">
                <label class="small mb-1" for="fwId">Resource id (PUT / verify)</label>
                <input id="fwId" class="form-control form-control-sm" placeholder="filled in after a POST">
            </div>
            <div class="form-group col-md-2">
                <label class="small mb-1" for="fwIfMatch" title="Sent on PUT. Task answers 412 when the version is stale; filled from the ETag of the last response.">If-Match</label>
                <input id="fwIfMatch" class="form-control form-control-sm" placeholder='W/"1" (optional)'>
            </div>
            <div class="form-group col-md-2">
                <button type="button" id="fwLoadBtn" class="btn btn-outline-secondary btn-sm btn-block">Load</button>
            </div>
        </div>

        <div id="fwMeta" class="small text-muted mb-2" style="display:none;"></div>

        <div class="form-row">
            <div class="form-group col-12">
                <label class="small mb-1 d-flex align-items-center" for="fwBody">
                    Request body
                    <button type="button" id="fwResetBody" class="btn btn-link btn-sm py-0 ml-2">reset to template</button>
                    <select id="fwVariant" class="form-control form-control-sm ml-2" style="display:none; width:auto;" title="Alternative bodies for this resource"></select>
                </label>
                <textarea id="fwBody" class="form-control form-control-sm text-monospace" rows="12"
                          spellcheck="false" style="font-size:12px;"></textarea>
            </div>
        </div>

        <button type="button" id="fwSendBtn" class="btn btn-primary btn-sm" disabled>Send</button>
        <button type="button" id="fwRoundTripBtn" class="btn btn-outline-primary btn-sm" disabled>POST &rarr; PUT &rarr; verify</button>
        <button type="button" id="fwSuiteBtn" class="btn btn-outline-dark btn-sm" disabled>Run all resources</button>
        <button type="button" id="fwContextBtn" class="btn btn-outline-secondary btn-sm">Refresh context</button>
        <button type="button" id="fwSeedBtn" class="btn btn-outline-success btn-sm">Create missing fixtures</button>
        <span id="fwSpinner" class="ml-2 small text-muted" style="display:none;">working…</span>

        <div id="fwContextOut" class="small mt-2" style="display:none;"></div>
        <pre id="fwOut" class="explorer-log mt-3 mb-0"></pre>
<?php explorer_panel_close(); ?>
<script>
(function () {
    const resourceSel = document.getElementById('fwResource');
    const verbSel = document.getElementById('fwVerb');
    const idIn = document.getElementById('fwId');
    const ifMatchIn = document.getElementById('fwIfMatch');
    const bodyIn = document.getElementById('fwBody');
    const meta = document.getElementById('fwMeta');
    const out = document.getElementById('fwOut');
    const ctxOut = document.getElementById('fwContextOut');
    const spin = document.getElementById('fwSpinner');
    const loadBtn = document.getElementById('fwLoadBtn');
    const sendBtn = document.getElementById('fwSendBtn');
    const roundTripBtn = document.getElementById('fwRoundTripBtn');
    const suiteBtn = document.getElementById('fwSuiteBtn');
    const contextBtn = document.getElementById('fwContextBtn');
    const seedBtn = document.getElementById('fwSeedBtn');
    const resetBodyBtn = document.getElementById('fwResetBody');
    const variantSel = document.getElementById('fwVariant');

    let catalog = {};
    let tokenScopes = [];
    let context = {};
    let contextReasons = {};

    function log (msg, reset) {
        out.style.display = 'block';
        out.textContent = (reset ? '' : out.textContent + '\n') + msg;
        out.scrollTop = out.scrollHeight;
    }

    async function api (params, init) {
        const res = await fetch('fhir_write.php?' + new URLSearchParams(params), Object.assign({
            credentials: 'same-origin'
        }, init || {}));
        const data = await res.json().catch(() => ({ error: 'non-JSON response (is your session still alive?)' }));
        return { ok: res.ok, data };
    }

    // A past timestamp no earlier run can have used: Observation vitals coalesce on
    // encounter + effectiveDateTime and refuse a POST for a reading that already exists, so
    // every body gets its own. Second precision, UTC -- what the server stores back.
    function freshEffective () {
        const past = Date.now() - (3600 + Math.floor(Math.random() * 90 * 86400)) * 1000;
        return new Date(past).toISOString().replace(/\.\d{3}Z$/, 'Z');
    }

    // Substitute {{placeholders}} anywhere in the template, at any depth. {{effective}} is
    // generated once per call, so one body carries one timestamp wherever it appears.
    function fill (value, effective) {
        // One object per body, so every placeholder in it sees the same generated values.
        const stamp = effective || {
            effective: freshEffective(),
            // Any day in the last ~25 years: Coverage refuses a second policy of the same type
            // with the same start date for a patient.
            uniqueDate: new Date(Date.now() - (1 + Math.floor(Math.random() * 9000)) * 86400000).toISOString().slice(0, 10)
        };
        if (typeof value === 'string') {
            return value.replace(/{{(\w+)}}/g, (whole, key) => {
                if (Object.prototype.hasOwnProperty.call(stamp, key)) {
                    return stamp[key];
                }
                return context[key] != null ? context[key] : whole;
            });
        }
        if (Array.isArray(value)) {
            return value.map(item => fill(item, stamp));
        }
        if (value && typeof value === 'object') {
            const copy = {};
            Object.keys(value).forEach(k => { copy[k] = fill(value[k], stamp); });
            return copy;
        }
        return value;
    }

    // The body currently chosen for a resource: the default, or the selected variant.
    function chosenTemplate (resource) {
        const entry = catalog[resource];
        if (!entry) {
            return null;
        }
        const variant = entry.variants && variantSel.value ? entry.variants[variantSel.value] : null;
        return variant ? variant.body : entry.body;
    }

    function templateFor (resource) {
        const entry = catalog[resource];
        return entry ? JSON.stringify(fill(chosenTemplate(resource)), null, 2) : '';
    }

    // A context key resolves to null for three very different reasons -- a refused read, an
    // empty database, or data of the wrong kind. The server says which; never drop it.
    function explainMissing (key) {
        return contextReasons[key] ? key + ' (' + contextReasons[key] + ')' : key;
    }

    function describe (resource) {
        const entry = catalog[resource];
        if (!entry) {
            meta.style.display = 'none';
            return;
        }
        if (entry.status === 'not-implemented') {
            meta.innerHTML = '⛔ route answers <strong>405</strong> — not implemented: ' + entry.reason
                + ' &nbsp;·&nbsp; this client asks for no write scope here, so the scope check usually answers 401/403 first';
            meta.style.display = 'block';
            return;
        }
        const hasScope = !!entry.hasScope;
        const unresolved = (entry.needs || []).filter(n => !context[n]);
        const bits = [
            'scope <code>' + entry.scope + '</code> ' + (hasScope ? '✓ in token' : '⚠ NOT in your token'),
            'ACL <code>' + entry.acl + '</code>',
            'verbs ' + entry.verbs.join(' + '),
            entry.readById ? 'read-by-id ✓' : 'no read-by-id route — verify uses <code>?_id=</code>'
        ];
        if (unresolved.length) {
            bits.push('⚠ unresolved context: ' + unresolved.map(explainMissing).join('; '));
        }
        meta.innerHTML = bits.join(' &nbsp;·&nbsp; ');
        meta.style.display = 'block';
    }

    function syncVerbs (resource) {
        const entry = catalog[resource];
        verbSel.innerHTML = '';
        ((entry && entry.verbs) || ['POST']).forEach(v => {
            const o = document.createElement('option');
            o.value = v;
            o.textContent = v;
            verbSel.appendChild(o);
        });
    }

    async function loadContext (quiet, action) {
        const { ok, data } = await api({ action: action || 'context' });
        if (!ok) {
            ctxOut.style.display = 'block';
            ctxOut.innerHTML = '<span class="text-danger">Context failed: ' + (data.error || '') + '</span>';
            return false;
        }
        renderContext(data, quiet);
        return data;
    }

    function renderContext (data, quiet) {
        context = data.context || {};
        contextReasons = data.reasons || {};
        const rows = Object.keys(context).map(k => {
            const v = context[k];
            if (v) {
                return '<code>' + k + '</code>: ' + v;
            }
            const why = contextReasons[k] ? ' — ' + contextReasons[k] : '';
            return '<code>' + k + '</code>: <span class="text-danger">not found' + why + '</span>';
        });
        ctxOut.style.display = 'block';
        ctxOut.innerHTML = 'Resolved context<br>' + rows.join('<br>');
        if (!quiet && (data.missing || []).length) {
            log('Context is missing:\n  ' + data.missing.map(explainMissing).join('\n  ')
                + '\nUse "Create missing fixtures" to POST them through the API, or load demo data.');
        }
    }

    loadBtn.addEventListener('click', async () => {
        spin.style.display = 'inline';
        log('Loading write catalog…', true);
        const { ok, data } = await api({ action: 'catalog' });
        if (!ok) {
            spin.style.display = 'none';
            log('Error: ' + (data.error || ''));
            return;
        }
        catalog = data.catalog || {};
        tokenScopes = data.tokenScopes || [];
        await loadContext(true);
        spin.style.display = 'none';

        resourceSel.innerHTML = '<option value="">— choose a resource —</option>';
        // Writable resources alphabetically, then the routes that only answer 405.
        const ordered = Object.keys(catalog).sort((a, b) => {
            const sa = catalog[a].status === 'not-implemented' ? 1 : 0;
            const sb = catalog[b].status === 'not-implemented' ? 1 : 0;
            return sa - sb || a.localeCompare(b);
        });
        ordered.forEach(r => {
            const o = document.createElement('option');
            o.value = r;
            o.textContent = r + (catalog[r].status === 'not-implemented'
                ? '  (405 — not implemented)'
                : (catalog[r].hasScope ? '' : '  (scope missing)'));
            resourceSel.appendChild(o);
        });
        suiteBtn.disabled = false;
        const stubs = Object.keys(catalog).filter(r => catalog[r].status === 'not-implemented');
        log((Object.keys(catalog).length - stubs.length) + ' writable resource(s) + ' + stubs.length
            + ' route(s) that answer 405 loaded against ' + data.fhirBase + '.');
        const missingScopes = Object.keys(catalog).filter(r => catalog[r].status !== 'not-implemented' && !catalog[r].hasScope);
        if (missingScopes.length) {
            log('⚠ Your token carries no write scope for: ' + missingScopes.join(', ')
                + '\n  Add them to the client scopes in config.php and re-register / re-authorize.');
        }
    });

    resourceSel.addEventListener('change', () => {
        const resource = resourceSel.value;
        sendBtn.disabled = !resource;
        roundTripBtn.disabled = !resource;
        idIn.value = '';
        ifMatchIn.value = '';
        syncVerbs(resource);
        syncVariants(resource);
        describe(resource);
        bodyIn.value = templateFor(resource);
    });

    function syncVariants (resource) {
        const entry = catalog[resource];
        variantSel.innerHTML = '';
        if (!entry || !entry.variants) {
            variantSel.style.display = 'none';
            return;
        }
        const base = document.createElement('option');
        base.value = '';
        base.textContent = 'default body';
        variantSel.appendChild(base);
        Object.keys(entry.variants).forEach(name => {
            const v = entry.variants[name];
            const o = document.createElement('option');
            o.value = name;
            o.textContent = (v.label || name) + (v.expect && v.expect !== 201 ? '  → expect ' + v.expect : '');
            variantSel.appendChild(o);
        });
        variantSel.style.display = 'inline-block';
    }

    variantSel.addEventListener('change', () => {
        bodyIn.value = templateFor(resourceSel.value);
    });

    resetBodyBtn.addEventListener('click', () => {
        bodyIn.value = templateFor(resourceSel.value);
    });

    seedBtn.addEventListener('click', async () => {
        spin.style.display = 'inline';
        log('Creating the prerequisites the write bodies reference…', true);
        const data = await loadContext(true, 'seed');
        spin.style.display = 'none';
        if (!data) {
            return;
        }
        const created = data.created || {};
        const keys = Object.keys(created);
        if (!keys.length) {
            log('Nothing to create — every referenced resource already resolves.');
        }
        keys.forEach(k => {
            const r = created[k];
            log(r.ok
                ? '  ✓ ' + k.padEnd(14) + 'created ' + r.resource + ' ' + r.id
                : '  ✗ ' + k.padEnd(14) + r.resource + ' — ' + (r.error || '')
                    + (r.body ? '\n      ' + JSON.stringify(r.body) : ''));
        });
        if ((data.missing || []).length) {
            log('Still unresolved: ' + data.missing.map(explainMissing).join('; '));
        } else {
            log('All context resolved. Every resource can now be exercised.');
        }
        if (resourceSel.value) {
            describe(resourceSel.value);
            bodyIn.value = templateFor(resourceSel.value);
        }
    });

    contextBtn.addEventListener('click', async () => {
        spin.style.display = 'inline';
        await loadContext(false);
        spin.style.display = 'none';
        if (resourceSel.value) {
            describe(resourceSel.value);
            bodyIn.value = templateFor(resourceSel.value);
        }
    });

    function parseBody () {
        try {
            return { body: JSON.parse(bodyIn.value) };
        } catch (e) {
            return { error: 'Body is not valid JSON: ' + e.message };
        }
    }

    async function send (resource, verb, id, body, ifMatch) {
        return api({ action: 'send' }, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ resource, verb, id, body, ifMatch: ifMatch || '' })
        });
    }

    function report (label, result) {
        const d = result.data || {};
        log(label + '  →  HTTP ' + d.code
            + (d.request ? '   ' + d.request.method + ' ' + d.request.url : ''));
        if (d.hint) {
            log('    ⚠ ' + d.hint);
        }
        if (d.etag) {
            log('    ETag ' + d.etag);
        }
        if (d.body !== undefined) {
            const text = typeof d.body === 'string' ? d.body : JSON.stringify(d.body, null, 2);
            log('    ' + text.split('\n').join('\n    '));
        }
    }

    sendBtn.addEventListener('click', async () => {
        const parsed = parseBody();
        if (parsed.error) { log(parsed.error, true); return; }
        const resource = resourceSel.value;
        const verb = verbSel.value;
        spin.style.display = 'inline';
        log(verb + ' ' + resource + '…', true);
        const result = await send(resource, verb, idIn.value.trim(), parsed.body, verb === 'PUT' ? ifMatchIn.value.trim() : '');
        spin.style.display = 'none';
        report(verb + ' ' + resource, result);
        if (result.data && result.data.id) {
            idIn.value = result.data.id;
            log('    id captured: ' + result.data.id);
        }
        // The next PUT then carries the version just returned, as a client working a Task would.
        if (result.data && result.data.etag) {
            ifMatchIn.value = result.data.etag;
        }
    });

    function responseText (result) {
        const d = (result && result.data) || {};
        return (typeof d.body === 'string' ? d.body : JSON.stringify(d.body || '')).toLowerCase();
    }

    // One expectation about a response: the status, and optionally a phrase in the body.
    function judge (label, result, expectCode, contains) {
        const code = result.data && result.data.code;
        const hasText = !contains || responseText(result).indexOf(String(contains).toLowerCase()) !== -1;
        return {
            label,
            ok: code === expectCode && hasText,
            code,
            why: code !== expectCode
                ? 'expected HTTP ' + expectCode + ', got ' + code
                : (hasText ? '' : 'HTTP ' + code + ' but the response does not mention "' + contains + '"'),
            detail: result.data
        };
    }

    // Variants and follow-up checks for a resource that declares them (Observation). Runs
    // after the default round trip; `created` is that round trip's body and id.
    async function runExtras (resource, created) {
        const entry = catalog[resource];
        const results = [];
        const checks = entry.checks || {};
        if (created && checks.repostConflict) {
            results.push(judge('re-POST of the same reading is refused',
                await send(resource, 'POST', '', created.body), 400, checks.repostConflict));
        }
        if (created && checks.putDateChange) {
            const moved = Object.assign({}, created.body, { id: created.id, effectiveDateTime: freshEffective() });
            results.push(judge('PUT that moves effectiveDateTime is refused',
                await send(resource, 'PUT', created.id, moved), 400, checks.putDateChange));
        }
        if (created && checks.lifecycle) {
            results.push(...await runLifecycle(resource, created, checks.lifecycle));
        }
        for (const name of Object.keys(entry.variants || {})) {
            const variant = entry.variants[name];
            const label = 'variant ' + name + ' — ' + (variant.label || '');
            if (variant.expect && variant.expect !== 201) {
                results.push(judge(label, await send(resource, 'POST', '', fill(variant.body)), variant.expect, variant.contains));
                continue;
            }
            const trip = await roundTrip(resource, variant.body);
            results.push({
                label,
                ok: !!trip.ok,
                code: trip.code,
                why: trip.ok ? 'id ' + trip.id : (trip.skipped ? 'skipped: ' + trip.reason : 'failed at ' + trip.step + ' (HTTP ' + trip.code + ')'),
                detail: trip.detail
            });
        }
        return results;
    }

    // Ordered steps on the resource the round trip made (Task: the referral loop). A PUT step
    // lays its patch over the body as it stands after the previous step that succeeded; a
    // search step must find the resource's id. A step whose `needs` are unresolved uses its
    // `fallback` patch, or is skipped.
    async function runLifecycle (resource, created, steps) {
        const results = [];
        let current = Object.assign({}, created.body, { id: created.id });
        for (const step of steps) {
            const label = 'loop: ' + step.label;
            if (step.search) {
                const query = fill(step.search);
                const found = await api({ action: 'search', resource, query });
                const ids = (found.data && found.data.ids) || [];
                const code = found.data && found.data.code;
                results.push({
                    label,
                    ok: code === 200 && ids.indexOf(created.id) !== -1,
                    code,
                    why: code !== 200 ? 'GET ?' + query + ' answered HTTP ' + code
                        : (ids.indexOf(created.id) !== -1 ? ids.length + ' match(es) for ?' + query : 'GET ?' + query + ' did not return ' + created.id),
                    detail: code !== 200 ? found.data : null
                });
                continue;
            }
            const unresolved = (step.needs || []).filter(n => !context[n]);
            const patch = unresolved.length ? step.fallback : step.patch;
            if (!patch) {
                results.push({ label, ok: true, why: 'skipped: unresolved ' + unresolved.map(explainMissing).join('; ') });
                continue;
            }
            const next = Object.assign({}, current, fill(patch), { id: created.id });
            const expect = step.expect || 200;
            const verdict = judge(label + (unresolved.length ? ' (fallback: no ' + unresolved.join(', ') + ')' : ''),
                await send(resource, 'PUT', created.id, next, step.ifMatch || ''), expect, step.contains);
            results.push(verdict);
            if (verdict.ok && expect === 200) {
                current = next;
            }
        }
        return results;
    }

    function logExtras (results) {
        results.forEach(r => {
            log('      ' + (r.ok ? '✓ ' : '✗ ') + r.label + (r.why ? '  [' + r.why + ']' : (r.ok ? '  [HTTP ' + r.code + ']' : '')));
            if (!r.ok && r.detail) {
                log('          ' + JSON.stringify(r.detail.body !== undefined ? r.detail.body : r.detail).slice(0, 600));
            }
        });
    }

    async function roundTrip (resource, template) {
        const entry = catalog[resource];

        // A not-implemented route passes when it refuses with 405. A 401/403 means the scope
        // check stopped it first (the Explorer's clients ask for no write scope on these), which proves nothing
        // about the route either way -- report it as skipped rather than as a pass or a failure.
        if (entry.status === 'not-implemented') {
            const probe = await send(resource, 'POST', '', fill(entry.body));
            const code = probe.data && probe.data.code;
            if (code === 405) {
                return { resource, ok: true, notImplemented: true, id: '405 as expected (not implemented)' };
            }
            if (code === 401 || code === 403) {
                return { resource, skipped: true, reason: 'HTTP ' + code + ' at the scope check — the token has no write scope for this route, so the 405 was not reached' };
            }
            return { resource, step: 'POST (expected 405)', code, detail: probe.data };
        }

        // Sending a body that still contains {{placeholders}} produces an unhelpful 400
        // about a malformed reference. Say what is actually missing instead.
        const unresolved = (entry.needs || []).filter(n => !context[n]);
        if (unresolved.length) {
            return {
                resource,
                skipped: true,
                reason: 'unresolved context: ' + unresolved.map(explainMissing).join('; ')
            };
        }

        const body = fill(template || entry.body);
        const created = await send(resource, 'POST', '', body);
        const createdCode = created.data && created.data.code;
        if (createdCode !== 201) {
            return { resource, step: 'POST', code: createdCode, detail: created.data };
        }
        const id = created.data.id;
        if (!id) {
            return {
                resource,
                step: 'POST',
                code: createdCode,
                detail: {
                    error: 'create returned 201 but no id under "' + (entry.idKey || 'uuid') + '"',
                    body: created.data.body
                }
            };
        }

        if (entry.verbs.indexOf('PUT') !== -1) {
            const updateBody = Object.assign({}, body, { id });
            const updated = await send(resource, 'PUT', id, updateBody);
            if (updated.data && updated.data.code !== 200) {
                return { resource, step: 'PUT', code: updated.data.code, id, detail: updated.data };
            }
            // A PUT that answers 200 with no body means the service never re-shaped the
            // stored row -- worth catching here, it is invisible from the status code.
            if (updated.data && (updated.data.body === null || updated.data.body === '')) {
                return { resource, step: 'PUT', code: 200, id, detail: { error: 'PUT returned a null body' } };
            }
        }

        const verified = await api({ action: 'verify', resource, id });
        if (!verified.data || !verified.data.found) {
            return { resource, step: 'verify', code: verified.data && verified.data.code, id, detail: verified.data };
        }

        return { resource, ok: true, id, body, skippedPut: entry.verbs.indexOf('PUT') === -1 };
    }

    roundTripBtn.addEventListener('click', async () => {
        const resource = resourceSel.value;
        spin.style.display = 'inline';
        log('Round trip for ' + resource + '…', true);
        const result = await roundTrip(resource, chosenTemplate(resource));
        spin.style.display = 'none';
        if (result.skipped) {
            log('— ' + resource + ' skipped: ' + result.reason);
        } else if (result.ok && result.notImplemented) {
            log('✓ ' + resource + '  ' + result.id);
        } else if (result.ok) {
            idIn.value = result.id;
            log('✓ ' + resource + '  id ' + result.id + (result.skippedPut ? '  (no PUT route — create only)' : ''));
            // With the default body selected, also run the resource's variants and checks.
            if (!variantSel.value && (catalog[resource].variants || catalog[resource].checks)) {
                spin.style.display = 'inline';
                const extras = await runExtras(resource, result);
                spin.style.display = 'none';
                logExtras(extras);
                const bad = extras.filter(e => !e.ok).length;
                log('  ' + (extras.length - bad) + ' of ' + extras.length + ' variant/check(s) passed.');
            }
        } else {
            log('✗ ' + resource + ' failed at ' + result.step + ' (HTTP ' + result.code + ')');
            log('    ' + JSON.stringify(result.detail, null, 2).split('\n').join('\n    '));
        }
    });

    suiteBtn.addEventListener('click', async () => {
        suiteBtn.disabled = true;
        spin.style.display = 'inline';
        log('Running POST → PUT → verify across every write resource…', true);
        await loadContext(true);

        // Questionnaire first: QuestionnaireResponse needs one to point at, and a fresh
        // one is more reliable than whatever happens to be in the repository.
        const names = Object.keys(catalog).sort((a, b) => {
            if (a === 'Questionnaire') return -1;
            if (b === 'Questionnaire') return 1;
            return a.localeCompare(b);
        });

        const passed = [];
        const failed = [];
        const skipped = [];
        for (const resource of names) {
            const result = await roundTrip(resource);
            if (result.skipped) {
                skipped.push(result);
                log('  — ' + resource.padEnd(24) + 'skipped: ' + result.reason);
            } else if (result.ok) {
                passed.push(result);
                log('  ✓ ' + resource.padEnd(24) + result.id
                    + (result.skippedPut ? '   (create only)' : ''));
                if (resource === 'Questionnaire') {
                    context.questionnaire = result.id;
                }
                if (!result.notImplemented && (catalog[resource].variants || catalog[resource].checks)) {
                    const extras = await runExtras(resource, result);
                    logExtras(extras);
                    extras.forEach(extra => {
                        if (extra.ok) {
                            passed.push({ resource: resource + ' / ' + extra.label });
                        } else {
                            failed.push({ resource: resource + ' / ' + extra.label, step: extra.why, code: extra.code, detail: extra.detail });
                        }
                    });
                }
            } else {
                failed.push(result);
                const why = (result.detail && result.detail.body
                    && result.detail.body.validationErrors)
                    ? '  ' + JSON.stringify(result.detail.body.validationErrors)
                    : '';
                log('  ✗ ' + resource.padEnd(24) + result.step + ' HTTP ' + result.code + why);
            }
        }

        spin.style.display = 'none';
        suiteBtn.disabled = false;
        log('\n' + passed.length + ' passed, ' + failed.length + ' failed, ' + skipped.length + ' skipped.');
        failed.forEach(f => {
            log('\n✗ ' + f.resource + ' — failed at ' + f.step + ' (HTTP ' + f.code + ')');
            log('  ' + JSON.stringify(f.detail, null, 2).split('\n').join('\n  '));
        });
    });
})();
</script>
