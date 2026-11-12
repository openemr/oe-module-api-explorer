<?php

/**
 * patient_write_card.php — the Patient app writes panel for the API Explorer.
 *
 * Tests what a patient-facing app (a SMART app the patient signs in to) can write: a
 * QuestionnaireResponse held for staff review, read back, revised, withdrawn; and the types
 * that have a setting but are refused until core serves them (vital-sign Observation,
 * AllergyIntolerance, Condition). Also seeds the "Home vital signs" Questionnaire a patient app
 * records home readings with.
 *
 * All traffic goes through patient_write.php, which holds the session's bearer token.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

?>
<?php explorer_panel_open('patientWrites', 'Patient app writes', ['subtitle' => 'held for staff review']); ?>

        <p class="small text-muted mb-2">
            With <b>Patient App FHIR Writes</b> on (Admin &gt; Config &gt; Connectors), a patient app's
            QuestionnaireResponse is held for staff review in <b>Miscellaneous &gt; Patient App Submissions</b> and stays
            out of the chart until approved. The app reads it back tagged <code>held-for-review</code>, can revise it
            while it is held and withdraw it with <code>entered-in-error</code>; after a withdrawal or a denial it reads
            410. Approved, it keeps its id. Vital signs, AllergyIntolerance and Condition have settings but are
            <b>not accepted yet</b>: the server publishes no patient write scope for them, and the run checks they are
            refused.
        </p>
        <p class="small text-muted mb-2">
            Needs a <b>patient token</b>: the <b>Public</b> client, Authorization Code, logged in as a portal patient
            (a public client only writes when the mode is <i>Confidential and public apps</i>). Home readings travel as
            answers to the <b>Home vital signs</b> Questionnaire; seed it once with a staff token.
        </p>

        <div class="form-row align-items-end">
            <div class="form-group col-md-4">
                <label class="small mb-1" for="pwQuestionnaire">Questionnaire id (optional)</label>
                <input id="pwQuestionnaire" class="form-control form-control-sm" placeholder="Home vital signs, else the first with items">
            </div>
            <div class="form-group col-md-3">
                <div class="custom-control custom-checkbox small">
                    <input type="checkbox" class="custom-control-input" id="pwKeep">
                    <label class="custom-control-label" for="pwKeep" title="Skip the withdrawal so the write waits in Patient App Submissions">leave it for staff review</label>
                </div>
            </div>
        </div>
        <div class="mb-2">
            <button type="button" id="pwStatusBtn" class="btn btn-outline-primary btn-sm">Token &amp; server</button>
            <button type="button" id="pwRunBtn" class="btn btn-primary btn-sm">Run patient write checks</button>
            <button type="button" id="pwSeedBtn" class="btn btn-outline-secondary btn-sm"
                    title="POST the Home vital signs Questionnaire with the session's staff token (user/Questionnaire.write, admin/super)">Seed Home vital signs Questionnaire</button>
            <span id="pwSpinner" class="ml-2 small text-muted" style="display:none;">working…</span>
        </div>

        <pre id="pwOut" class="explorer-log mt-2 mb-0" style="display:none;"></pre>
<?php explorer_panel_close(); ?>
<script>
(function () {
    const out = document.getElementById('pwOut');
    const spin = document.getElementById('pwSpinner');

    function log (msg, reset) {
        out.style.display = 'block';
        out.textContent = (reset ? '' : out.textContent + '\n') + msg;
        out.scrollTop = out.scrollHeight;
    }

    async function api (params) {
        spin.style.display = 'inline';
        try {
            const res = await fetch('patient_write.php?' + new URLSearchParams(params), { credentials: 'same-origin' });
            return await res.json().catch(() => ({ error: 'non-JSON response (is your session still alive?)' }));
        } finally {
            spin.style.display = 'none';
        }
    }

    document.getElementById('pwStatusBtn').addEventListener('click', async () => {
        const d = await api({ action: 'status' });
        log('Session token and server', true);
        if (d.error) { log('  ✗ ' + d.error); return; }
        log('  patient: ' + (d.patient || '(none)') + (d.patientFrom ? ' (from ' + d.patientFrom + ')' : '') + '   client: ' + (d.clientType || '?'));
        log('  ' + (d.isPatientToken ? '✓ a patient token' : '✗ not a patient token' + (d.userScopes.length ? ' (carries user/ scopes)' : '')));
        log('  granted patient scopes: ' + (d.patientScopes.join(' ') || '(none)'));
        log('  server publishes patient write scopes: ' + (d.advertised.join(' ') || '(none: Patient App FHIR Writes is Off)'));
        ['Observation', 'AllergyIntolerance', 'Condition'].forEach(t => {
            if (d.advertised.some(s => s.startsWith('patient/' + t + '.'))) {
                log('  ⚠ ' + t + ' is published for patient writes: this server is newer than this panel.');
            }
        });
    });

    document.getElementById('pwSeedBtn').addEventListener('click', async () => {
        const d = await api({ action: 'seedVitals' });
        log('Seed Home vital signs Questionnaire', true);
        if (d.error) { log('  ✗ ' + d.error); return; }
        if (d.existing) {
            log('  ✓ already there: Questionnaire/' + d.id + ' (version ' + (d.version || '?') + ')');
        } else if (d.code === 201 || d.code === 200) {
            log('  ✓ created Questionnaire/' + (d.id || '(id not returned)') + '. Patient apps find it by its title and canonical identifier (OpenEMR publishes its own url).');
        } else {
            log('  ✗ HTTP ' + d.code + ' ' + (d.why || ''));
            if (d.hint) log('  ' + d.hint);
        }
    });

    document.getElementById('pwRunBtn').addEventListener('click', async () => {
        const params = { action: 'run' };
        const q = document.getElementById('pwQuestionnaire').value.trim();
        if (q) params.questionnaire = q;
        if (document.getElementById('pwKeep').checked) params.keep = '1';
        log('Running the patient app write checks…', true);
        const d = await api(params);
        if (d.error) { log('  ✗ ' + d.error); return; }
        let passed = 0, failed = 0;
        (d.steps || []).forEach(s => {
            const mark = s.pass === true ? '✓' : (s.pass === false ? '✗' : 'ℹ');
            if (s.pass === true) passed++;
            if (s.pass === false) failed++;
            log('  ' + mark + ' ' + s.name + ' — ' + s.detail);
            if (s.pass === false && s.body) {
                log('      ' + JSON.stringify(s.body).slice(0, 500));
            }
        });
        log('\n  ' + passed + ' passed, ' + failed + ' failed' + (d.id ? '   (write ' + d.id + ' on Questionnaire/' + d.questionnaire + ')' : ''));
    });
})();
</script>
