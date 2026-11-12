<?php

/**
 * patient_app_write_card.php — the Patient app writes panel (openemr #14999, #15162).
 *
 * Writes QuestionnaireResponse the way a patient's SMART app would, with the Public client's
 * patient token. OpenEMR holds each write for staff review (Miscellaneous > Patient App
 * Submissions) and keeps it out of the chart until it is approved. The panel checks the hold
 * contract: 201 with the held-for-review tag, read and search by the same app, revise, withdraw,
 * 410 after, and the refusals (another patient, an unknown Questionnaire, an id mismatch). It
 * then lists the SQL for the rows an EHI export of the patient must carry.
 *
 * All traffic goes through patient_app_write.php, which holds the session's bearer token.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

?>
<!-- Patient app writes — QuestionnaireResponse held for staff review; shares this Explorer's session token -->
<?php explorer_panel_open('patientAppWrite', 'Patient app writes', ['subtitle' => 'held for review → EHI']); ?>

        <p class="small text-muted mb-2">
            In OpenEMR, set <strong>Admin → Config → Connectors → Patient App FHIR Writes</strong> to
            <em>Confidential and public apps</em> and turn on <strong>QuestionnaireResponse</strong>. Then
            <strong>Register Clients</strong>, <strong>Clear Session</strong>, and Fetch with the <strong>Public</strong>
            client, logging in as a patient. Each write is held for staff review under
            <strong>Miscellaneous → Patient App Submissions</strong>; nothing reaches the chart until it is approved.
        </p>

        <div class="mb-2">
            <button type="button" id="pawStatusBtn" class="btn btn-outline-primary btn-sm">Check token</button>
            <span id="pawStatus" class="small ml-2"></span>
        </div>

        <div class="form-row align-items-end">
            <div class="form-group col-md-5">
                <label class="small mb-1" for="pawQuestionnaire">Questionnaire</label>
                <select id="pawQuestionnaire" class="form-control form-control-sm">
                    <option value="">Check token to list them</option>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label class="small mb-1" for="pawQuestionnaireId">or Questionnaire id</label>
                <input id="pawQuestionnaireId" class="form-control form-control-sm" placeholder="when the token can't search Questionnaire">
            </div>
            <div class="form-group col-md-3">
                <label class="small mb-1" for="pawId">Held write id</label>
                <input id="pawId" class="form-control form-control-sm" placeholder="filled in after a POST">
            </div>
        </div>

        <div class="form-group mb-2">
            <label class="small mb-1" for="pawBody">Request body <a href="#" id="pawBuild" class="ml-2">build from the Questionnaire</a></label>
            <textarea id="pawBody" class="form-control form-control-sm" rows="8" style="font-family: monospace;" spellcheck="false"></textarea>
        </div>

        <div class="mb-2">
            <button type="button" id="pawPostBtn" class="btn btn-outline-primary btn-sm">POST</button>
            <button type="button" id="pawPutBtn" class="btn btn-outline-primary btn-sm" title="Replace the held write with this body">PUT</button>
            <button type="button" id="pawWithdrawBtn" class="btn btn-outline-danger btn-sm" title="PUT status entered-in-error: cancels the request">Withdraw</button>
            <button type="button" id="pawReadBtn" class="btn btn-outline-secondary btn-sm">Read</button>
            <button type="button" id="pawSearchBtn" class="btn btn-outline-secondary btn-sm">Search patient</button>
            <button type="button" id="pawChecksBtn" class="btn btn-primary btn-sm" title="Hold, read, search, revise, refusals, withdraw, 410, then one write left for staff">Run checks</button>
            <button type="button" id="pawEhiBtn" class="btn btn-outline-dark btn-sm" title="SQL for the rows an EHI export of this patient must carry">EHI rows</button>
            <span id="pawSpinner" class="ml-2 small text-muted" style="display:none;">working…</span>
        </div>

        <pre id="pawOut" class="explorer-log mt-2 mb-0" style="display:none;"></pre>
<?php explorer_panel_close(); ?>
<script>
(function () {
    const statusBtn = document.getElementById('pawStatusBtn');
    const statusOut = document.getElementById('pawStatus');
    const qSel = document.getElementById('pawQuestionnaire');
    const qIdIn = document.getElementById('pawQuestionnaireId');
    const idIn = document.getElementById('pawId');
    const bodyIn = document.getElementById('pawBody');
    const out = document.getElementById('pawOut');
    const spin = document.getElementById('pawSpinner');

    // A uuid no OpenEMR install has: the other patient and the unknown Questionnaire checks.
    const NOBODY = '00000000-0000-4000-8000-00000000e1e1';
    let state = { patient: null, questionnaires: [] };

    function log (msg, reset) {
        out.style.display = 'block';
        out.textContent = (reset ? '' : out.textContent + '\n') + msg;
        out.scrollTop = out.scrollHeight;
    }

    async function api (params, init) {
        const res = await fetch('patient_app_write.php?' + new URLSearchParams(params), Object.assign({
            credentials: 'same-origin'
        }, init || {}));
        const data = await res.json().catch(() => ({ error: 'non-JSON response (is your session still alive?)' }));
        return data;
    }

    function send (verb, id, body) {
        return api({ action: 'send' }, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ verb, id: id || '', body })
        });
    }

    function report (label, d) {
        log(label + '  →  HTTP ' + d.code + (d.request ? '   ' + d.request.method + ' ' + d.request.url : '')
            + (d.held ? '   [held-for-review]' : ''));
        if (d.error) {
            log('    ' + d.error);
        }
        if (d.hint) {
            log('    ⚠ ' + d.hint);
        }
        const issue = d.body && d.body.resourceType === 'OperationOutcome' && d.body.issue && d.body.issue[0];
        if (issue) {
            log('    ' + issue.code + ': ' + ((issue.details && issue.details.text) || issue.diagnostics || ''));
        }
    }

    function questionnaireId () {
        return qIdIn.value.trim() || qSel.value;
    }

    // One answer for the first item an app could answer without asking the patient anything.
    function answerFor (item) {
        switch (item.type) {
            case 'boolean': return { valueBoolean: true };
            case 'integer': return { valueInteger: 1 };
            case 'decimal': return { valueDecimal: 1.5 };
            case 'date': return { valueDate: new Date().toISOString().slice(0, 10) };
            case 'choice':
            case 'open-choice': {
                const opt = (item.answerOption || [])[0];
                return opt && opt.valueCoding ? { valueCoding: opt.valueCoding } : (opt && opt.valueString ? { valueString: opt.valueString } : null);
            }
            case 'string':
            case 'text': return { valueString: 'explorer patient app ' + Date.now() };
            default: return null;
        }
    }

    function firstAnswerable (items) {
        for (const item of items || []) {
            if (item.type === 'group' || item.type === 'display') {
                const inner = firstAnswerable(item.item);
                if (inner) { return inner; }
                continue;
            }
            const answer = answerFor(item);
            if (answer) { return { item, answer }; }
        }
        return null;
    }

    function build () {
        const qid = questionnaireId();
        const q = state.questionnaires.find(x => x.id === qid);
        const picked = q ? firstAnswerable(q.item) : null;
        const body = {
            resourceType: 'QuestionnaireResponse',
            status: 'completed',
            questionnaire: 'Questionnaire/' + (qid || 'PICK-ONE'),
            subject: { reference: 'Patient/' + (state.patient || 'TOKEN-PATIENT') },
            authored: new Date().toISOString().replace(/\.\d+Z$/, 'Z'),
            item: picked
                ? [{ linkId: picked.item.linkId, text: picked.item.text, answer: [picked.answer] }]
                : [{ linkId: '1', answer: [{ valueString: 'explorer patient app ' + Date.now() }] }]
        };
        bodyIn.value = JSON.stringify(body, null, 2);
        return body;
    }

    function parsed () {
        try { return JSON.parse(bodyIn.value); } catch (e) { log('Body is not valid JSON: ' + e.message, true); return null; }
    }

    async function checkToken () {
        spin.style.display = 'inline';
        const d = await api({ action: 'status' });
        spin.style.display = 'none';
        if (d.error) {
            statusOut.textContent = d.error;
            statusOut.className = 'small ml-2 text-danger';
            return d;
        }
        state.patient = d.patient;
        state.questionnaires = d.questionnaires || [];
        const parts = [
            'client ' + (d.clientType || '?'),
            d.patient ? 'patient ' + d.patient : 'no patient in the token',
            d.canWrite ? 'token can write QuestionnaireResponse' : 'token has no patient/QuestionnaireResponse.cu',
            d.advertised === null ? 'discovery unreadable' : (d.advertised ? 'server advertises .cu' : 'server does not advertise .cu (setting off)')
        ];
        statusOut.textContent = parts.join(' · ');
        statusOut.className = 'small ml-2 ' + (d.patient && d.canWrite ? 'text-success' : 'text-warning');
        qSel.innerHTML = '';
        if (!state.questionnaires.length) {
            qSel.add(new Option('none readable (HTTP ' + d.questionnaireSearch.code + '): enter an id', ''));
        }
        state.questionnaires.forEach(q => qSel.add(new Option(q.title + ' — ' + q.id, q.id)));
        build();
        return d;
    }

    statusBtn.addEventListener('click', checkToken);
    qSel.addEventListener('change', build);
    qIdIn.addEventListener('change', build);
    document.getElementById('pawBuild').addEventListener('click', e => { e.preventDefault(); build(); });

    document.getElementById('pawPostBtn').addEventListener('click', async () => {
        const body = parsed();
        if (!body) { return; }
        spin.style.display = 'inline';
        const d = await send('POST', '', body);
        spin.style.display = 'none';
        report('POST QuestionnaireResponse', d);
        if (d.code === 201 && d.id) {
            idIn.value = d.id;
            log('    held as ' + d.id + '. Approve or deny it in Miscellaneous → Patient App Submissions.');
        }
    });

    document.getElementById('pawPutBtn').addEventListener('click', async () => {
        const body = parsed();
        const id = idIn.value.trim();
        if (!body || !id) { log('PUT needs a held write id and a body.', true); return; }
        spin.style.display = 'inline';
        const d = await send('PUT', id, Object.assign({}, body, { id }));
        spin.style.display = 'none';
        report('PUT QuestionnaireResponse/' + id, d);
    });

    document.getElementById('pawWithdrawBtn').addEventListener('click', async () => {
        const body = parsed();
        const id = idIn.value.trim();
        if (!body || !id) { log('Withdraw needs a held write id and a body.', true); return; }
        if (!window.confirm('Withdraw ' + id + '? The request is cancelled and can no longer be changed.')) { return; }
        spin.style.display = 'inline';
        const d = await send('PUT', id, Object.assign({}, body, { id, status: 'entered-in-error' }));
        spin.style.display = 'none';
        report('withdraw QuestionnaireResponse/' + id, d);
    });

    document.getElementById('pawReadBtn').addEventListener('click', async () => {
        const id = idIn.value.trim();
        if (!id) { log('Read needs an id.', true); return; }
        spin.style.display = 'inline';
        const d = await api({ action: 'read', id });
        spin.style.display = 'none';
        report('GET QuestionnaireResponse/' + id, d);
        log('    ' + JSON.stringify(d.body, null, 2).split('\n').join('\n    '));
        if (d.code === 200 && !d.held) {
            log('    No held-for-review tag: this is the chart record (approved), or another app\'s view of the chart.');
        }
    });

    document.getElementById('pawSearchBtn').addEventListener('click', async () => {
        spin.style.display = 'inline';
        const d = await api({ action: 'search' });
        spin.style.display = 'none';
        report('search', d);
        (d.found || []).forEach(r => log('    ' + r.id + '  ' + (r.status || '') + (r.held ? '  [held]' : '')));
    });

    document.getElementById('pawEhiBtn').addEventListener('click', async () => {
        const d = await api({ action: 'ehi' });
        if (d.error) { log(d.error, true); return; }
        log('Rows an EHI export of patient ' + d.patient + ' must carry (tables: ' + d.tables.join(', ') + ').', true);
        log('Run this SQL, then export the patient in Modules → EHI Exporter and find the same rows in the zip:\n');
        log(d.sql);
    });

    // The hold contract, in order. Each step: what is sent, and the status it must get.
    document.getElementById('pawChecksBtn').addEventListener('click', async () => {
        log('Patient app write checks', true);
        const status = await checkToken();
        if (status.error || !state.patient) {
            log('✗ No patient token. Fetch with the Public client and log in as a patient.');
            return;
        }
        if (!questionnaireId()) {
            log('✗ Pick a Questionnaire, or enter its id.');
            return;
        }
        if (!status.canWrite) {
            log('⚠ The token has no patient/QuestionnaireResponse.cu: every write below should get 403.');
        }
        const base = build();
        let pass = 0;
        let fail = 0;
        function expect (label, d, code, extra) {
            const ok = d.code === code && (!extra || extra(d));
            ok ? pass++ : fail++;
            log((ok ? '✓ ' : '✗ ') + label + ': HTTP ' + d.code + (ok ? '' : ' (expected ' + code + ')') + (d.held ? ' [held]' : ''));
            if (!ok && d.hint) { log('    ⚠ ' + d.hint); }
            return ok;
        }
        spin.style.display = 'inline';
        const created = await send('POST', '', base);
        if (!expect('POST is held', created, 201, d => d.held && !!d.id)) {
            spin.style.display = 'none';
            report('POST', created);
            return;
        }
        const id = created.id;
        idIn.value = id;
        expect('read it back', await api({ action: 'read', id }), 200, d => d.held && d.body.id === id);
        expect('search by patient finds it', await api({ action: 'search' }), 200, d => (d.found || []).some(r => r.id === id && r.held));
        const revised = JSON.parse(JSON.stringify(base));
        revised.id = id;
        revised.item[0].answer = [{ valueString: 'revised ' + Date.now() }];
        expect('PUT revises it', await send('PUT', id, revised), 200, d => d.held);
        expect('PUT with another id in the body', await send('PUT', id, Object.assign({}, revised, { id: NOBODY })), 400);
        expect('POST for another patient', await send('POST', '', Object.assign({}, base, { subject: { reference: 'Patient/' + NOBODY } })), 403);
        expect('POST for an unknown Questionnaire', await send('POST', '', Object.assign({}, base, { questionnaire: 'Questionnaire/' + NOBODY })), 400);
        expect('PUT entered-in-error withdraws it', await send('PUT', id, Object.assign({}, revised, { status: 'entered-in-error' })), 200);
        expect('read after withdraw', await api({ action: 'read', id }), 410);
        expect('PUT after withdraw', await send('PUT', id, revised), 410);
        const left = await send('POST', '', build());
        expect('POST one for staff review', left, 201, d => d.held);
        spin.style.display = 'none';
        log('\n' + pass + ' passed, ' + fail + ' failed.');
        if (left.id) {
            idIn.value = left.id;
            log('Held for staff: ' + left.id + '. Approve or deny it in Miscellaneous → Patient App Submissions, then Read:'
                + ' approved answers the chart record (200, no tag); denied answers 410. Then EHI rows.');
        }
    });
})();
</script>
