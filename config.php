<?php

/**
 * Configuration for OpenEMR API Explorer.
 *
 * DO NOT put your own sites in this file. Copy config.local.sample.php to config.local.php
 * and edit that -- it is git-ignored, so pulling updates never overwrites your settings and
 * your server names never end up in a commit. With no config.local.php the Explorer talks to
 * https://localhost/openemr.
 *
 * What lives here: the defaults, the endpoint map built from the selected site, and the scope
 * constants each registered client asks for.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2025-2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// ---------------------------------------------------------------------------------------
// Local settings (config.local.php), merged over these defaults.
// ---------------------------------------------------------------------------------------
$explorerSettings = [
    // name => OpenEMR base URL (no trailing slash). The name becomes part of the registered
    // client names and credential file names, so keep it short and stable.
    'sites' => ['localhost' => 'https://localhost/openemr'],
    // Site selected on first load. Defaults to the first entry of 'sites'.
    'default_site' => null,
    // The OpenEMR multisite id used in the OAuth2 and API paths (/oauth2/<id>/, /apis/<id>/).
    'openemr_site' => 'default',
    // false: the JWKS is sent inline at registration (works everywhere).
    // true:  registration sends a jwks_uri pointing at clients_keys/<site>_jwks.json, which
    //        the OpenEMR server must be able to fetch over HTTPS.
    'use_keys_file' => false,
    // Ask for user/Observation.write. Register Clients drops any scope the server does not
    // publish, so leaving this on is safe against a server without Observation writes.
    'observation_write' => true,
    // The Explorer bypasses OpenEMR login and holds client secrets, so by default it only
    // answers requests from this machine and private networks. Set true to lift that.
    'allow_remote' => false,
    // Public URL of this folder, when the one derived from the request is wrong (reverse
    // proxy, port mapping). Example: 'https://dev.example.org/devtools/oe-module-api-explorer'
    'app_url' => null,
];
if (is_file(__DIR__ . '/config.local.php')) {
    $explorerLocal = require __DIR__ . '/config.local.php';
    if (is_array($explorerLocal)) {
        $explorerSettings = array_merge($explorerSettings, $explorerLocal);
    }
}
if (empty($explorerSettings['sites']) || !is_array($explorerSettings['sites'])) {
    $explorerSettings['sites'] = ['localhost' => 'https://localhost/openemr'];
}

// List of OpenEMR sites offered in the Site dropdown.
$apiSites = array_map(static fn($url): string => rtrim((string) $url, '/'), $explorerSettings['sites']);
$explorerDefaultSite = isset($apiSites[(string) $explorerSettings['default_site']])
    ? (string) $explorerSettings['default_site']
    : (string) array_key_first($apiSites);
$explorerOpenemrSite = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $explorerSettings['openemr_site']) ?: 'default';

// ---------------------------------------------------------------------------------------
// Access guard. This tool sets $ignoreAuth, stores client secrets and private keys, and can
// write to the chart with whatever token is in session. It is for development machines.
// ---------------------------------------------------------------------------------------
if (php_sapi_name() !== 'cli' && empty($explorerSettings['allow_remote'])) {
    $explorerRemote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $explorerIsLocal = $explorerRemote === ''
        || filter_var($explorerRemote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if (!$explorerIsLocal) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "OpenEMR API Explorer only answers local and private-network requests.\n"
            . "To allow others, set 'allow_remote' => true in config.local.php (development servers only).\n";
        exit;
    }
}

// Determine domain and root directory automatically except if CLI then add host and root
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$selectedSite = $selectedSite ?? $_SESSION['selectedSite'] ?? $explorerDefaultSite;
$api_site = $selectedSite;

// Set default site if not passed via query or session
if (php_sapi_name() !== 'cli') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $selectedSite = $_SESSION['selectedSite'] ?? $_GET['api_site'] ?? $explorerDefaultSite;
    // A site name left in the session by an older config (or typed into the URL) that is no
    // longer configured would otherwise name credential files for a site that does not exist.
    if (!is_string($selectedSite) || !isset($apiSites[$selectedSite])) {
        $selectedSite = $explorerDefaultSite;
    }
    $api_site = $selectedSite;
    $_SESSION['selectedSite'] = $selectedSite;
    $base_path = $apiSites[$selectedSite];
} else {
    $base_path = $apiSites[$explorerDefaultSite]; // or --base_path=<url>
    foreach ($argv ?? [] as $arg) {
        if (stripos($arg, '--base_path=') === 0) {
            $base_path = rtrim(substr($arg, strlen('--base_path=')), '/');
        }
    }
}

// The Explorer lives two levels below the OpenEMR root (it requires ../../interface/globals.php).
// The folder names are taken from disk, so cloning under a different name still works.
$web_root = $web_root ?? '';
$domain = "{$scheme}://{$host}{$web_root}";
$app_path = !empty($explorerSettings['app_url'])
    ? rtrim((string) $explorerSettings['app_url'], '/')
    : "{$domain}/" . basename(dirname(__DIR__)) . '/' . basename(__DIR__);
$appbase_path = $app_path;

// Register the generated JWKS inline (default) or by public URI -- see 'use_keys_file' above.
$use_keys_file = !empty($explorerSettings['use_keys_file']);
$jwks_app_path = $use_keys_file ? "{$app_path}/clients_keys/{$selectedSite}_jwks.json" : null;

// Dynamic API endpoint configuration
$GLOBALS['ApiConfig'] = [
    'JWKS_LOCATION_URL'        => $jwks_app_path,
    'AUTHORIZATION_ENDPOINT'   => "{$base_path}/oauth2/{$explorerOpenemrSite}/authorize",
    'TOKEN_ENDPOINT'           => "{$base_path}/oauth2/{$explorerOpenemrSite}/token",
    'LOGOUT_REDIRECT_URI'      => "{$base_path}/oauth2/{$explorerOpenemrSite}/logout.php",
    'REGISTER_CLIENT_ENDPOINT' => "{$base_path}/oauth2/{$explorerOpenemrSite}/registration",
    'FHIR_SERVER_URL'          => "{$base_path}/apis/{$explorerOpenemrSite}/fhir",
    'API_SERVER_URL'           => "{$base_path}/apis/{$explorerOpenemrSite}/api",
    'REDIRECT_URI'             => "{$app_path}/oeApiExplorer.php"
];

// SMART EHR launch endpoints for this client application.
$GLOBALS['ApiConfig']['SMART_LAUNCH_URI'] = "{$app_path}/smart_launch.php";
$GLOBALS['ApiConfig']['SMART_REDIRECT_URI'] = "{$app_path}/oeApiExplorer.php";

// Provider-facing SMART EHR launch test client. OpenEMR returns patient, encounter,
// fhirUser, intent, fhirContext, style URL, and any future appContext in the token response.
// QuestionnaireResponse is requested in the v1 read + write form, not v2 `.crus`.
// ServerScopeListEntity's v2 builder emits only `.rs` for every FHIR resource -- see the
// "we'll ignore write for now" comment beside it -- so no `.crus` scope is published and
// registration is rejected outright with invalid_scope. The only `.crus` strings in OpenEMR
// are documentation labels for the standard api:oemr endpoints (user/encounter.crus and
// friends, lowercase), which are a different scope space entirely.
//
// The pair stays on one version for the same reason FHIR_READ_SCOPES does: the consent form
// reconstructs a single scope version per resource, so a v2 read beside a v1 write drops one
// of them silently. Patient, Encounter and Questionnaire stay `.rs` because this client only
// reads them -- if it ever needs to write one, move that resource to .read + .write too.
const SMART_SCOPES = 'openid fhirUser launch user/Patient.rs user/Encounter.rs user/Appointment.read user/Questionnaire.rs user/QuestionnaireResponse.read user/QuestionnaireResponse.write';

const SYSTEM_SCOPES = 'openid offline_access api:oemr api:fhir api:port system/Patient.rs system/AllergyIntolerance.rs user/AllergyIntolerance.rs system/CarePlan.read system/CareTeam.rs system/Condition.read system/Coverage.read system/Device.read system/DiagnosticReport.read system/DocumentReference.read system/Encounter.read system/Goal.read system/Group.read system/Immunization.read system/Location.read system/Medication.read system/MedicationRequest.read system/Observation.read system/Organization.read system/Person.read system/Practitioner.read system/PractitionerRole.read system/Procedure.read system/Provenance.read system/*.$export system/Patient.$export system/ServiceRequest.rs system/Specimen.rs user/Specimen.rs system/ServiceRequest.r system/ServiceRequest.read system/Task.read system/Binary.read system/Group.$export system/*.$bulkdata-status';

// Read scopes for every resource the write workbench touches -- the POST/PUT half is
// useless without them, since verifying a write means reading it back, and resolving
// the reference context means searching Patient/Practitioner/Location/Organization.
//
// These are deliberately ALL the v1 `.read` form, never the SMART v2 `.rs` form. The
// scope-authorize consent screen groups its checkboxes by resource name and reconstructs a
// single scope version per resource, so asking for `user/X.rs` (v2) and `user/X.write` (v1)
// together makes the form emit only the v2 scope -- the write is dropped from the approved set
// with no error anywhere, and the token comes back read-only for that resource.
// Requires OpenEMR to publish a v1 read scope for every writable resource; Questionnaire,
// QuestionnaireResponse, RelatedPerson, ServiceRequest and Task were added to
// ServerScopeListEntity::$fhirReadResources for exactly this reason.
// Task, DocumentReference and Binary are here for the referral loop (openemr #14710): a
// referral is a ServiceRequest, its Task says where it stands, and the reply comes back as a
// DocumentReference in Task.output whose content is read through Binary.
// Observation writes arrive with OpenEMR PR #14217. 'observation_write' in config.local.php
// turns the scope request off; it is on by default because Register Clients drops any scope
// the server does not publish, so an older server simply registers without it.
define('EXPLORER_OBSERVATION_WRITE', !empty($explorerSettings['observation_write']));

const FHIR_READ_SCOPES = 'user/AllergyIntolerance.read user/Appointment.read user/CarePlan.read user/CareTeam.read user/Condition.read user/Coverage.read user/Device.read user/Encounter.read user/Goal.read user/Immunization.read user/Location.read user/Medication.read user/MedicationRequest.read user/Observation.read user/Organization.read user/Patient.read user/Person.read user/Practitioner.read user/PractitionerRole.read user/Questionnaire.read user/QuestionnaireResponse.read user/RelatedPerson.read user/ServiceRequest.read user/Task.read user/DocumentReference.read user/Binary.read';

// FHIR write scopes for the resources the OpenEMR FHIR API accepts POST/PUT on.
// The generic write controller rejects patient-scope tokens outright, so these are all
// user/ scopes. Every name here must also appear in
// ServerScopeListEntity::$fhirWriteResources on the server, or the token request is
// rejected for an unsupported scope.
const FHIR_WRITE_SCOPES = 'user/AllergyIntolerance.write user/Appointment.write user/CarePlan.write user/CareTeam.write user/Condition.write user/Coverage.write user/Device.write user/Encounter.write user/Goal.write user/Immunization.write user/Medication.write user/MedicationRequest.write user/Organization.write user/Patient.write user/Person.write user/Practitioner.write user/PractitionerRole.write user/Questionnaire.write user/QuestionnaireResponse.write user/RelatedPerson.write user/ServiceRequest.write user/Task.write'
    . (EXPLORER_OBSERVATION_WRITE ? ' user/Observation.write' : '');

// DiagnosticReport, DocumentReference, Group, Location, MedicationDispense, Procedure and
// Provenance have POST/PUT routes that answer 405 (RestControllerHelper::fhirWriteNotImplemented).
// They are deliberately absent above: there is nothing to write, so the Explorer's clients do
// not ask for a write scope on them. The write workbench still probes the routes; without the
// scope the answer is 401/403 from the scope check rather than the 405.

// Write scopes for the standard (non-FHIR) REST API, from ServerScopeListEntity::apiScopes().
// These are the `api:oemr` endpoints under /apis/default/api, not the FHIR ones above.
const STANDARD_API_WRITE_SCOPES = 'user/allergy.write user/appointment.write user/dental_issue.write user/document.write user/encounter.write user/facility.write user/insurance.write user/insurance_company.write user/medical_problem.write user/medication.write user/message.write user/patient.write user/practitioner.write user/prescription.write user/soap_note.write user/surgery.write user/transaction.write user/vital.write';

// Note: there is no patient/ form of any write scope. ServerScopeListEntity now also emits
// `system/<Resource>.write` when system scopes are enabled, but SYSTEM_SCOPES does not ask for
// them -- use the Confidential Auth-Code client for writes, or the Scope Lab to test system writes.

const LIMITED_SCOPES = 'openid offline_access api:oemr api:fhir api:port user/Procedure.rs patient/encounter.read patient/patient.read patient/Patient.read patient/Encounter.read ' . FHIR_READ_SCOPES . ' ' . FHIR_WRITE_SCOPES . ' ' . STANDARD_API_WRITE_SCOPES;

const PUBLIC_SCOPES = 'openid offline_access api:oemr api:fhir api:port patient/encounter.read patient/patient.read patient/Patient.read patient/Encounter.read patient/AllergyIntolerance.read patient/CareTeam.read patient/MedicationRequest.read patient/Task.read';
