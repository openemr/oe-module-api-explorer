<?php

/**
 * readme.php — the help shown at the bottom of the Explorer page.
 *
 * The same material, in more depth, is in README.md, INSTALLATION.md, FHIR_WRITES.md,
 * SCOPE_LAB.md and SMART_TESTING.md.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2025-2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$helpLocalConfig = is_file(__DIR__ . '/config.local.php');
?>
<h5>OpenEMR API Explorer</h5>
<p>
    A working reference for OpenEMR's OAuth2, SMART on FHIR, FHIR and standard REST APIs, and a
    test bench for the API itself. It registers its own clients, walks each grant type, reads and
    writes resources, and checks that scopes are granted and enforced correctly.
</p>
<p class="small text-muted">
    Development tool: it skips the OpenEMR login and keeps client secrets on disk. Use it on a
    development install with test data. See <code>SECURITY.md</code>.
</p>

<h5>Getting started</h5>
<ol>
    <li>Choose a <strong>Site</strong> in the bar at the top.</li>
    <li>Click <strong>Register Clients</strong>. It creates a key pair and four clients for that
        site (JWT, Confidential, Public, SMART EHR Launch) and enables them. Run it once per site,
        and again after any scope change.</li>
    <li>In <strong>Request</strong>, pick a client, grant, API and resource, then <strong>Fetch</strong>.
        Authorization Code grants send you through login and consent first.</li>
    <li><strong>Clear Session</strong> drops the current token when you want to start over.</li>
</ol>

<h5>Your sites</h5>
<?php if ($helpLocalConfig) : ?>
    <p>Loaded from <code>config.local.php</code>:</p>
<?php else : ?>
    <p>
        No <code>config.local.php</code> yet, so the default site is used. To add your own, copy
        <code>config.local.sample.php</code> to <code>config.local.php</code> and edit the
        <code>sites</code> list. That file is git-ignored.
    </p>
<?php endif; ?>
<table class="table table-sm table-bordered bg-light">
    <thead><tr><th>Site</th><th>OpenEMR base URL</th></tr></thead>
    <tbody>
    <?php foreach ($apiSites as $helpSiteName => $helpSiteUrl) : ?>
        <tr>
            <td><?= text($helpSiteName) ?><?= $helpSiteName === ($_SESSION['selectedSite'] ?? null) ? ' (selected)' : '' ?></td>
            <td><?= text($helpSiteUrl) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="small text-muted">
    A site name starting with <code>remote-</code> marks a server whose database this install
    can't reach. Register Clients leaves existing clients there alone and can't enable new ones:
    enable them on that server under Admin → System → API Clients.
</p>

<h5>Clients and grants</h5>
<table class="table table-sm table-bordered bg-light">
    <thead><tr><th>Client</th><th>Grant</th><th>Scopes (config.php)</th><th>Use it for</th></tr></thead>
    <tbody>
    <tr><td>Confidential</td><td>Authorization Code + refresh</td><td><code>LIMITED_SCOPES</code></td><td>Provider-facing apps; the only client with write scopes</td></tr>
    <tr><td>Public</td><td>Authorization Code with PKCE</td><td><code>PUBLIC_SCOPES</code></td><td>Patient-facing and browser apps</td></tr>
    <tr><td>JWT</td><td>Client Credentials</td><td><code>SYSTEM_SCOPES</code></td><td>Backend services, bulk export</td></tr>
    <tr><td>SMART EHR Launch</td><td>Authorization Code with <code>launch</code></td><td><code>SMART_SCOPES</code></td><td>Apps launched from inside OpenEMR</td></tr>
    </tbody>
</table>
<ul>
    <li>Register Clients reads the scopes the server publishes and leaves out any it doesn't offer,
        listing them in its output. One checkout therefore works across OpenEMR versions.</li>
    <li>Scopes are fixed when a client is registered. After editing a scope constant, run
        <strong>Register Clients</strong> again and get a new token.</li>
    <li>On servers without the consent fix (OpenEMR PR #14317), asking for a v2 read and a v1 write
        on the same resource drops the write at the consent screen. That is why the write scope
        sets here use v1 <code>.read</code> and <code>.write</code> together. The Scope Lab's
        mixed profiles test this.</li>
</ul>

<h5>Bulk $export</h5>
<ol>
    <li>Fetch once with the <strong>JWT</strong> client so a system token is in session.</li>
    <li><strong>List</strong> the groups, choose one with members, optionally limit
        <code>_type</code>, and <strong>Start export</strong>.</li>
    <li>The panel polls the status URL and lists the NDJSON files when the export completes.</li>
</ol>

<h5>FHIR writes</h5>
<p>Fetch once with the <strong>Confidential</strong> client first, so the token carries write scopes.</p>
<ol>
    <li><strong>Load</strong> reads the write catalog and resolves a patient, practitioner, facility,
        insurer, encounter and questionnaire from your server. On an empty install,
        <strong>Create missing fixtures</strong> makes them through the API.</li>
    <li>Pick a resource. The line below it shows the scope needed and whether the token has it,
        the ACL the route enforces, and the verbs available.</li>
    <li><strong>Send</strong> one request, run <strong>POST → PUT → verify</strong> for one resource,
        or <strong>Run all resources</strong>.</li>
</ol>
<ul>
    <li><strong>23 writable resources</strong>, with bodies taken from OpenEMR's own test fixtures.
        Appointment is create-only.</li>
    <li><strong>7 routes answer 405</strong> (DiagnosticReport, DocumentReference, Group, Location,
        MedicationDispense, Procedure, Provenance). The suite passes them on a 405 and skips them
        on a 401/403, where the scope check answered before the route could.</li>
    <li><strong>Observation</strong> (OpenEMR PR #14217) covers vital signs. A dropdown offers
        more bodies: unit conversion, blood-pressure components, pulse oximetry, and six that must
        answer 400. The round trip also checks that a repeat POST and a date-changing PUT are refused.</li>
    <li>The create response names the new id differently per resource: most use <code>uuid</code>,
        Encounter <code>euuid</code>, Appointment <code>pc_uuid</code>.</li>
    <li>A PUT that answers 200 with an empty body is reported as a failure.</li>
    <li>A write that answers 401 with the scope present usually means the logged-in user lacks the
        ACL shown for that resource.</li>
    <li><strong>Task</strong> uses one of the patient's orders as its focus, never a referral: OpenEMR
        takes a Task on a referral for the referral's own Task.</li>
</ul>

<h5>Referral loop</h5>
<ul>
    <li>Save a referral on a patient's Transactions screen with the <code>fhir_referral_tasks</code>
        global on, and OpenEMR makes its Task for Refer To.</li>
    <li><strong>Find referrals</strong>, pick one, then <strong>Accept</strong>, <strong>Reject</strong>,
        <strong>Complete</strong> and the rest. Each PUT carries <code>If-Match</code>.</li>
    <li>Record a Reply Date on the referral to complete its Task with the reply, then
        <strong>Follow output</strong> to read it through DocumentReference and Binary.</li>
    <li>These act on real referrals: Refer By gets an inbox message on accepted, rejected, completed
        and failed.</li>
</ul>

<h5>FHIR write stress</h5>
<p>
    Sends many writes at once and reports latency, status spread and whether every create returned
    a distinct id. <strong>It writes real rows that the API can't delete.</strong> Each run tags
    its rows and prints SQL to find them. Use <strong>Dry run</strong> first.
</p>

<h5>Scope Lab</h5>
<p>
    Tests whether scopes are reliable across SMART v1, v2 and mixed sets, for every grant type.
</p>
<ol>
    <li><strong>Discover scopes</strong>, then choose a grant and context.</li>
    <li>Tick profiles and <strong>Run selected</strong>. Client Credentials and Password run
        unattended. Auth Code runs go through consent one at a time: approve every scope.</li>
    <li>Each run registers its own client, gets a token, compares requested, granted, JWT and
        introspected scopes as permissions, probes the API, and tests refresh.</li>
    <li>Click a result row for the detail. <strong>Copy Markdown</strong> gives a summary for an
        issue or pull request.</li>
</ol>
<p class="small text-muted">
    Probes don't create data: reads use <code>_count=1</code> or a fake id, writes send an empty
    body the server refuses after the scope check. 401/403 means the scope was refused;
    400/404/422 means it was accepted.
</p>

<h5>SMART EHR launch</h5>
<p>
    The SMART client is started <em>from</em> OpenEMR: the SMART Enabled Apps card on the patient
    dashboard, or <strong>Patient → Assessments → FHIR Assessments</strong>. The Explorer then
    shows the discovery document, the launch context from the token response, and the
    questionnaire workspace. If it reports an unknown issuer, add that OpenEMR URL to your sites.
</p>

<h5>Current endpoints</h5>
<table class="table table-sm table-bordered bg-light">
    <thead><tr><th>Config key</th><th>Value</th></tr></thead>
    <tbody>
    <?php foreach (['AUTHORIZATION_ENDPOINT', 'TOKEN_ENDPOINT', 'REGISTER_CLIENT_ENDPOINT', 'FHIR_SERVER_URL', 'API_SERVER_URL', 'REDIRECT_URI', 'SMART_LAUNCH_URI', 'JWKS_LOCATION_URL'] as $helpKey) : ?>
        <tr>
            <td><?= text($helpKey) ?></td>
            <td><?= text((string) ($GLOBALS['ApiConfig'][$helpKey] ?? 'inline (not served by URL)')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h5>When something fails</h5>
<table class="table table-sm table-bordered bg-light">
    <thead><tr><th>Symptom</th><th>Fix</th></tr></thead>
    <tbody>
    <tr><td>Token error: client not enabled</td><td>Enable the client under Admin → System → API Clients (always needed for <code>remote-</code> sites).</td></tr>
    <tr><td>401 on everything after a scope change</td><td>Register Clients again, Clear Session, fetch.</td></tr>
    <tr><td>JWT client can't get a token</td><td>Enable system scopes in Admin → Config → Connectors.</td></tr>
    <tr><td>Redirect returns to the wrong host</td><td>Check OpenEMR's Site Address, or set <code>app_url</code> in <code>config.local.php</code>.</td></tr>
    <tr><td>Writes skipped for unresolved context</td><td>Create missing fixtures.</td></tr>
    <tr><td>Scope Lab: invalid CSRF token</td><td>Reload this page.</td></tr>
    </tbody>
</table>

<h5>More</h5>
<p>
    Guides in this folder: <code>README.md</code>, <code>INSTALLATION.md</code>,
    <code>FHIR_WRITES.md</code>, <code>SCOPE_LAB.md</code>, <code>SMART_TESTING.md</code>,
    <code>SECURITY.md</code>, <code>CONTRIBUTING.md</code>.
    Source and issues: <a href="https://github.com/sjpadgett/oe-module-api-explorer" target="_blank" rel="noopener">github.com/sjpadgett/oe-module-api-explorer</a>.
</p>
<p class="small text-muted">
    A community project, not an official OpenEMR product. GNU GPL v3.
    © 2025-2026 Jerry Padgett
</p>
