<?php

/**
 * referral_loop_card.php — the Referral loop panel for the API Explorer (openemr #14710).
 *
 * Works the Task OpenEMR makes when a referral is saved on the Transactions screen, the way a
 * recipient's system would: find the referral ServiceRequests and their Tasks, move the
 * referral's own Task (accepted, in-progress, rejected, failed, cancelled, completed) with a
 * PUT that carries If-Match, and follow Task.output to the reply's DocumentReference and Binary.
 *
 * Unlike the FHIR writes panel this acts on real referrals: each status change is the
 * referral's status on the referral screen and worklist, and accepted, rejected, completed and
 * failed send Refer By an inbox message (when the fhir_referral_tasks global is on). A Task in
 * a final status can only become entered-in-error.
 *
 * All traffic goes through fhir_write.php (actions referrals, verify, send, output), which holds
 * the session's bearer token.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

?>
<!-- Referral loop — works a referral's own Task; shares this Explorer's session token -->
<?php explorer_panel_open('referralLoop', 'Referral loop', ['subtitle' => 'referral → Task → reply']); ?>

        <p class="small text-muted mb-2">
            Save a referral on a patient's Transactions screen (with the <code>fhir_referral_tasks</code> global on)
            and OpenEMR makes its Task. These buttons act on that real Task: the referral screen shows its status, and
            accepted, rejected, completed and failed send Refer By an inbox message. A Task that is completed, rejected,
            failed or cancelled can't change again except to entered-in-error.
            Needs <code>user/ServiceRequest.read</code>, <code>user/Task.read</code> / <code>.write</code>,
            <code>user/DocumentReference.read</code> and <code>user/Binary.read</code>.
        </p>

        <div class="form-row align-items-end">
            <div class="form-group col-md-4">
                <label class="small mb-1" for="rlPatient">Patient id (optional)</label>
                <input id="rlPatient" class="form-control form-control-sm" placeholder="all patients">
            </div>
            <div class="form-group col-md-2">
                <label class="small mb-1" for="rlCount">Referrals</label>
                <input id="rlCount" type="number" min="1" max="50" value="20" class="form-control form-control-sm">
            </div>
            <div class="form-group col-md-2">
                <button type="button" id="rlFindBtn" class="btn btn-outline-primary btn-sm btn-block">Find referrals</button>
            </div>
        </div>

        <div id="rlTableWrap" class="table-responsive" style="display:none;">
            <table class="table table-sm table-hover small mb-2">
                <thead>
                <tr>
                    <th></th><th>Referral</th><th>Patient</th><th>Refer By → Refer To</th><th>Task</th><th>Status</th><th>Outputs</th>
                </tr>
                </thead>
                <tbody id="rlRows"></tbody>
            </table>
        </div>

        <div class="form-row align-items-end">
            <div class="form-group col-md-4">
                <label class="small mb-1" for="rlTask">Task id</label>
                <input id="rlTask" class="form-control form-control-sm" placeholder="pick a referral above, or paste a Task id">
            </div>
            <div class="form-group col-md-5">
                <label class="small mb-1" for="rlReason">Reason / business status (optional)</label>
                <input id="rlReason" class="form-control form-control-sm" placeholder="statusReason for rejected / failed / cancelled; businessStatus otherwise">
            </div>
            <div class="form-group col-md-3">
                <div class="custom-control custom-checkbox small">
                    <input type="checkbox" class="custom-control-input" id="rlStale">
                    <label class="custom-control-label" for="rlStale" title="Send If-Match one version behind: the server must answer 412 and change nothing">send a stale If-Match</label>
                </div>
            </div>
        </div>

        <div class="mb-2">
            <button type="button" class="btn btn-outline-success btn-sm rl-status" data-status="accepted">Accept</button>
            <button type="button" class="btn btn-outline-secondary btn-sm rl-status" data-status="in-progress">In progress</button>
            <button type="button" class="btn btn-outline-secondary btn-sm rl-status" data-status="on-hold">On hold</button>
            <button type="button" class="btn btn-outline-danger btn-sm rl-status" data-status="rejected">Reject</button>
            <button type="button" class="btn btn-outline-danger btn-sm rl-status" data-status="failed">Fail</button>
            <button type="button" class="btn btn-outline-danger btn-sm rl-status" data-status="cancelled">Cancel</button>
            <button type="button" class="btn btn-outline-success btn-sm rl-status" data-status="completed">Complete</button>
            <button type="button" class="btn btn-outline-dark btn-sm rl-status" data-status="entered-in-error">Entered in error</button>
            <button type="button" id="rlReadBtn" class="btn btn-outline-primary btn-sm">Read Task</button>
            <button type="button" id="rlOutputBtn" class="btn btn-outline-primary btn-sm">Follow output</button>
            <span id="rlSpinner" class="ml-2 small text-muted" style="display:none;">working…</span>
        </div>

        <pre id="rlOut" class="explorer-log mt-2 mb-0" style="display:none;"></pre>
<?php explorer_panel_close(); ?>
<script>
(function () {
    const patientIn = document.getElementById('rlPatient');
    const countIn = document.getElementById('rlCount');
    const findBtn = document.getElementById('rlFindBtn');
    const tableWrap = document.getElementById('rlTableWrap');
    const rows = document.getElementById('rlRows');
    const taskIn = document.getElementById('rlTask');
    const reasonIn = document.getElementById('rlReason');
    const staleIn = document.getElementById('rlStale');
    const readBtn = document.getElementById('rlReadBtn');
    const outputBtn = document.getElementById('rlOutputBtn');
    const out = document.getElementById('rlOut');
    const spin = document.getElementById('rlSpinner');

    // The statuses OpenEMR tells Refer By about (ReferralTaskNotice), and those that take a reason.
    const NOTIFIES = ['accepted', 'rejected', 'completed', 'failed'];
    const WITH_REASON = ['rejected', 'failed', 'cancelled'];

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

    function cell (text) {
        const td = document.createElement('td');
        td.textContent = text == null ? '' : String(text);
        return td;
    }

    function render (referrals) {
        rows.innerHTML = '';
        referrals.forEach(r => {
            const own = r.tasks.find(t => t.id === r.ownTask) || null;
            const tr = document.createElement('tr');
            const pick = document.createElement('td');
            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'rlPick';
            radio.disabled = !own;
            radio.title = own ? 'Work this referral\'s Task' : 'No Task for Refer To yet: save the referral with the global on';
            radio.addEventListener('change', () => { taskIn.value = own.id; });
            pick.appendChild(radio);
            tr.appendChild(pick);
            tr.appendChild(cell(r.id + (r.code ? ' — ' + r.code : '')));
            tr.appendChild(cell(r.patient));
            tr.appendChild(cell((r.requester || '?') + ' → ' + (r.performer || '?')));
            const others = r.tasks.length - (own ? 1 : 0);
            tr.appendChild(cell(own ? own.id + ' (v' + own.version + ')' + (others ? ' +' + others + ' other' : '') : (r.tasks.length ? r.tasks.length + ' other Task(s), none for Refer To' : 'none')));
            tr.appendChild(cell(own ? own.status + (own.statusReason ? ': ' + own.statusReason : (own.businessStatus ? ' — ' + own.businessStatus : '')) : ''));
            tr.appendChild(cell(own ? own.outputs : ''));
            rows.appendChild(tr);
        });
        tableWrap.style.display = referrals.length ? 'block' : 'none';
    }

    findBtn.addEventListener('click', async () => {
        spin.style.display = 'inline';
        log('Finding referral ServiceRequests and their Tasks…', true);
        const params = { action: 'referrals', count: countIn.value || '20' };
        if (patientIn.value.trim()) {
            params.patient = patientIn.value.trim();
        }
        const result = await api(params);
        spin.style.display = 'none';
        const d = result.data || {};
        log('GET ' + ((d.request && d.request.url) || '') + '  →  HTTP ' + d.code);
        if (d.hint) {
            log('  ⚠ ' + d.hint);
        }
        if (!d.referrals) {
            log('  ' + JSON.stringify(d.body || d, null, 2).split('\n').join('\n  '));
            render([]);
            return;
        }
        render(d.referrals);
        const withTask = d.referrals.filter(r => r.ownTask).length;
        log('  ' + d.referrals.length + ' referral(s), ' + withTask + ' with a Task for Refer To.');
        d.referrals.filter(r => r.taskSearchCode !== 200).forEach(r => {
            log('  ⚠ Task search for ' + r.id + ' answered HTTP ' + r.taskSearchCode + (r.taskSearchCode === 401 || r.taskSearchCode === 403 ? ' — no user/Task.read scope' : ''));
        });
    });

    async function readTask (id) {
        const result = await api({ action: 'verify', resource: 'Task', id });
        const d = result.data || {};
        return d.code === 200 && d.body && d.body.resourceType === 'Task' ? d.body : { error: d };
    }

    readBtn.addEventListener('click', async () => {
        const id = taskIn.value.trim();
        if (!id) { log('Pick a referral or enter a Task id.', true); return; }
        spin.style.display = 'inline';
        const task = await readTask(id);
        spin.style.display = 'none';
        log('GET Task/' + id, true);
        log(JSON.stringify(task.error || task, null, 2));
    });

    document.querySelectorAll('.rl-status').forEach(btn => btn.addEventListener('click', async () => {
        const id = taskIn.value.trim();
        const status = btn.getAttribute('data-status');
        if (!id) { log('Pick a referral or enter a Task id.', true); return; }
        const warn = 'Mark Task ' + id + ' ' + status + '?\n\nThis changes the real referral\'s status'
            + (NOTIFIES.indexOf(status) !== -1 ? ' and sends Refer By an inbox message' : '') + '.';
        if (!window.confirm(warn)) {
            return;
        }
        spin.style.display = 'inline';
        log(status + ' → Task/' + id, true);
        const task = await readTask(id);
        if (task.error) {
            spin.style.display = 'none';
            log('  ✗ could not read the Task first: ' + JSON.stringify(task.error).slice(0, 600));
            return;
        }
        const version = parseInt((task.meta && task.meta.versionId) || '0', 10);
        // Stale: a version the Task no longer has (one behind, or one ahead for a new Task).
        const ifMatch = 'W/"' + (staleIn.checked ? (version > 1 ? version - 1 : version + 1) : version) + '"';
        // The Task as read, with the new status: outputs, owner and the rest go back unchanged.
        const body = Object.assign({}, task, { status });
        const reason = reasonIn.value.trim();
        if (reason && WITH_REASON.indexOf(status) !== -1) {
            body.statusReason = { text: reason };
        } else if (reason) {
            body.businessStatus = { text: reason };
        }
        log('  from ' + task.status + ' (v' + version + '), If-Match ' + ifMatch);
        const result = await api({ action: 'send' }, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ resource: 'Task', verb: 'PUT', id, body, ifMatch })
        });
        spin.style.display = 'none';
        const d = result.data || {};
        log('  PUT  →  HTTP ' + d.code + (d.etag ? '   ETag ' + d.etag : ''));
        if (d.hint) {
            log('  ⚠ ' + d.hint);
        }
        if (d.code === 200) {
            log('  ✓ now ' + ((d.body && d.body.status) || status)
                + (NOTIFIES.indexOf(status) !== -1 && status !== task.status ? '. Refer By should have a "Referral" message in their inbox.' : '.'));
        } else {
            log('  ' + JSON.stringify(d.body, null, 2).split('\n').join('\n  '));
        }
        if (staleIn.checked) {
            log('  (stale If-Match: expected 412, and the Task unchanged)');
        }
    }));

    outputBtn.addEventListener('click', async () => {
        const id = taskIn.value.trim();
        if (!id) { log('Pick a referral or enter a Task id.', true); return; }
        spin.style.display = 'inline';
        log('Following Task/' + id + ' output…', true);
        const result = await api({ action: 'output', task: id });
        spin.style.display = 'none';
        const d = result.data || {};
        if (!d.outputs) {
            log('  ✗ HTTP ' + d.code + ' reading the ' + (d.step || 'Task') + ': ' + JSON.stringify(d.body || d).slice(0, 600));
            return;
        }
        log('  Task ' + d.task.id + ' is ' + d.task.status + ', ' + d.outputs.length + ' output(s).');
        if (!d.outputs.length) {
            log('  Record a Reply Date in the referral\'s Counter-Referral section and save: the reply becomes the output and completes the Task.');
        }
        d.outputs.forEach(o => {
            log('\n  output[' + o.index + '] ' + (o.type || ''));
            if (!o.documentReference) {
                log('    ' + JSON.stringify(o.value));
                return;
            }
            const doc = o.documentReference;
            log('    DocumentReference/' + doc.id + '  →  HTTP ' + doc.code
                + (doc.type ? '   type ' + doc.type : '') + (doc.contentType ? '   ' + doc.contentType : ''));
            if (doc.hint) {
                log('    ⚠ ' + doc.hint);
            }
            if (o.binary) {
                if (o.binary.skipped) {
                    log('    Binary skipped: ' + o.binary.skipped);
                    return;
                }
                log('    ' + (doc.url || 'Binary') + '  →  HTTP ' + o.binary.code + ', ' + o.binary.bytes + ' bytes');
                if (o.binary.hint) {
                    log('    ⚠ ' + o.binary.hint);
                }
                if (o.binary.text) {
                    log('    ' + o.binary.text.split('\n').join('\n    '));
                }
            }
        });
    });
})();
</script>
