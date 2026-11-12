<?php

/**
 * patient_app_write.php — driver for the Patient app writes panel (openemr #14999, #15162).
 *
 * AJAX endpoint that shares the Explorer's session token, like fhir_write.php. The token here
 * is a patient-context one (the Public client's, or a SMART launch's): OpenEMR holds every
 * QuestionnaireResponse a patient app writes for staff review instead of putting it in the chart.
 *
 * Actions (?action=):
 *   status     the token's client, patient and patient write scopes; whether the server
 *              advertises patient/QuestionnaireResponse.cu (the practice opened it); the
 *              Questionnaires the token can read
 *   send       POST body {verb, id?, body} -> POST or PUT a QuestionnaireResponse
 *   read       ?id=..  GET QuestionnaireResponse/<id>
 *   search     GET QuestionnaireResponse?patient=<the token's patient>
 *   ehi        SQL listing the rows an EHI export must carry for the token's patient: the
 *              review requests and their history (openemr #15104), questionnaire responses and
 *              Tasks. This endpoint doesn't open the database; run the SQL yourself.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once 'config.php';
require_once __DIR__ . '/fhir_http.php';

/** The scope OpenEMR offers only while a practice opens QuestionnaireResponse to patient apps. */
const PATIENT_QR_WRITE_SCOPE = 'patient/QuestionnaireResponse.cu';

$access = (string) ($_SESSION['access_token'] ?? '');
if ($access === '') {
    http_response_code(401);
    echo json_encode(['error' => 'No access token in session. Fetch once with the Public client (patient login) first.']);
    exit;
}

$fhirBase = rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/');
$accept = ['Accept: application/fhir+json'];
$tokenPatient = $_SESSION['token_response']['patient'] ?? null;
$tokenPatient = is_string($tokenPatient) && $tokenPatient !== '' ? $tokenPatient : null;
$tokenScopes = array_values(array_filter(explode(' ', trim((string) ($_SESSION['access_token_scopes'] ?? '')))));

/**
 * Whether a v2 scope string grants create or update on QuestionnaireResponse for the patient.
 *
 * @param list<string> $scopes
 */
function grantsPatientQrWrite(array $scopes): bool
{
    foreach ($scopes as $scope) {
        if (preg_match('~^patient/(QuestionnaireResponse|\*)\.(c?r?u?d?s?)$~', $scope, $m) === 1 && preg_match('/[cu]/', $m[2]) === 1) {
            return true;
        }
        if (preg_match('~^patient/(QuestionnaireResponse|\*)\.(write|\*)$~', $scope) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * scopes_supported from the SMART configuration, flattened (OpenEMR has served it both flat and
 * nested), or null when it can't be read.
 *
 * @return list<string>|null
 */
function advertisedScopes(string $fhirBase): ?array
{
    $r = fhirCall('GET', "{$fhirBase}/.well-known/smart-configuration", '', ['Accept: application/json']);
    $config = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !is_array($config) || !is_array($config['scopes_supported'] ?? null)) {
        return null;
    }
    $scopes = [];
    array_walk_recursive($config['scopes_supported'], static function ($scope) use (&$scopes): void {
        if (is_string($scope)) {
            $scopes[] = $scope;
        }
    });
    return $scopes;
}

/**
 * The resource id a write answered with: the body's id, else the last segment of Location.
 *
 * @param array<string, mixed>|null $decoded
 */
function writtenId(?array $decoded, string $location): ?string
{
    if (is_string($decoded['id'] ?? null) && $decoded['id'] !== '') {
        return $decoded['id'];
    }
    if (preg_match('~/QuestionnaireResponse/([^/?#]+)~', $location, $m) === 1) {
        return $m[1];
    }
    return null;
}

/**
 * Whether a resource carries OpenEMR's held-for-review tag, whatever system it names.
 *
 * @param array<string, mixed>|null $resource
 */
function isHeld(?array $resource): bool
{
    $tags = $resource['meta']['tag'] ?? null;
    if (!is_array($tags)) {
        return false;
    }
    foreach ($tags as $tag) {
        if (is_array($tag) && ($tag['code'] ?? null) === 'held-for-review') {
            return true;
        }
    }
    return false;
}

/**
 * The hint for a status a patient app write can answer.
 */
function writeHint(int $code): ?string
{
    return match ($code) {
        0 => 'The request never reached the server -- check FHIR_SERVER_URL and TLS.',
        401 => 'The token is missing or expired: fetch again with the Public client.',
        403 => 'Refused: the token has no patient/QuestionnaireResponse.cu, the practice setting does not allow this kind of client, '
            . 'the resource names another patient, or the id is not a write of this app still held for review.',
        400 => 'Invalid: the Questionnaire is not on this server at its current version, or the body id does not match the URL id.',
        410 => 'The write was denied or withdrawn.',
        413 => 'Larger than the 256 KB limit.',
        429 => 'This app already has 50 writes waiting for this patient, or the write was revised too many times.',
        default => null,
    };
}

/**
 * @return array<string, mixed>
 */
function answer(string $method, string $url, array $r): array
{
    $decoded = json_decode($r['body'], true);
    $decoded = is_array($decoded) ? $decoded : null;
    return [
        'request' => ['method' => $method, 'url' => $url],
        'code' => $r['code'],
        'location' => $r['location'],
        'id' => writtenId($decoded, $r['location']),
        'held' => isHeld($decoded),
        'body' => $decoded ?? $r['body'],
        'hint' => $r['code'] >= 200 && $r['code'] < 300 ? null : writeHint($r['code']),
    ];
}

try {
    switch ((string) ($_GET['action'] ?? 'status')) {
        case 'status':
            $advertised = advertisedScopes($fhirBase);
            $questionnaires = [];
            $qUrl = "{$fhirBase}/Questionnaire?_count=50";
            $q = fhirCall('GET', $qUrl, $access, $accept);
            $bundle = json_decode($q['body'], true);
            foreach (is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
                $resource = $entry['resource'] ?? null;
                if (is_array($resource) && is_string($resource['id'] ?? null)) {
                    $questionnaires[] = [
                        'id' => $resource['id'],
                        'title' => (string) ($resource['title'] ?? $resource['name'] ?? $resource['id']),
                        'item' => is_array($resource['item'] ?? null) ? $resource['item'] : [],
                    ];
                }
            }
            echo json_encode([
                'clientType' => (string) ($_SESSION['client_type'] ?? ''),
                'patient' => $tokenPatient,
                'scopes' => array_values(array_filter($tokenScopes, static fn(string $s): bool => str_starts_with($s, 'patient/Questionnaire'))),
                'canWrite' => grantsPatientQrWrite($tokenScopes),
                'advertised' => $advertised === null ? null : in_array(PATIENT_QR_WRITE_SCOPE, $advertised, true),
                'questionnaireSearch' => ['url' => $qUrl, 'code' => $q['code']],
                'questionnaires' => $questionnaires,
            ]);
            break;

        case 'send':
            $raw = file_get_contents('php://input');
            $request = json_decode(is_string($raw) ? $raw : '', true);
            $verb = strtoupper((string) ($request['verb'] ?? ''));
            $id = (string) ($request['id'] ?? '');
            $body = $request['body'] ?? null;
            if (!in_array($verb, ['POST', 'PUT'], true) || !is_array($body) || ($verb === 'PUT' && $id === '')) {
                http_response_code(400);
                echo json_encode(['error' => 'send needs verb POST or PUT, a JSON body, and an id for PUT']);
                exit;
            }
            $url = "{$fhirBase}/QuestionnaireResponse" . ($verb === 'PUT' ? '/' . rawurlencode($id) : '');
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            $r = fhirCall($verb, $url, $access, array_merge($accept, ['Content-Type: application/fhir+json']), is_string($payload) ? $payload : '{}');
            echo json_encode(answer($verb, $url, $r));
            break;

        case 'read':
            $id = trim((string) ($_GET['id'] ?? ''));
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'id is required']);
                exit;
            }
            $url = "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($id);
            echo json_encode(answer('GET', $url, fhirCall('GET', $url, $access, $accept)));
            break;

        case 'search':
            if ($tokenPatient === null) {
                http_response_code(400);
                echo json_encode(['error' => 'The token names no patient: fetch with the Public client and log in as a patient.']);
                exit;
            }
            $url = "{$fhirBase}/QuestionnaireResponse?patient=" . rawurlencode($tokenPatient);
            $r = fhirCall('GET', $url, $access, $accept);
            $bundle = json_decode($r['body'], true);
            $found = [];
            foreach (is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
                $resource = $entry['resource'] ?? null;
                if (is_array($resource) && is_string($resource['id'] ?? null)) {
                    $found[] = ['id' => $resource['id'], 'status' => $resource['status'] ?? null, 'held' => isHeld($resource)];
                }
            }
            echo json_encode([
                'request' => ['method' => 'GET', 'url' => $url],
                'code' => $r['code'],
                'found' => $found,
                'hint' => $r['code'] === 200 ? null : writeHint($r['code']),
            ]);
            break;

        case 'ehi':
            $patient = trim((string) ($_GET['patient'] ?? $tokenPatient ?? ''));
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $patient) !== 1) {
                http_response_code(400);
                echo json_encode(['error' => 'A patient uuid is required (the token names none, or it is not a uuid).']);
                exit;
            }
            // The tables Documentation/EHI_Export/b10-tables.yml lists for this work. The EHI
            // exporter walks its schema files, so a row here that is missing from an export is a
            // gap in docs/openemr.openemr.xml (openemr #15104 fixed it for the review tables).
            $hex = str_replace('-', '', strtolower($patient));
            echo json_encode([
                'patient' => $patient,
                'tables' => ['patient_review_request', 'patient_review_event', 'questionnaire_response', 'task', 'task_input', 'task_output'],
                'sql' => implode("\n", [
                    "SET @pid = (SELECT pid FROM patient_data WHERE uuid = UNHEX('{$hex}'));",
                    "SELECT @pid AS pid;",
                    "SELECT id, HEX(uuid) AS uuid, type, source, client_id, status, target_table, target_id FROM patient_review_request WHERE pid = @pid ORDER BY id;",
                    "SELECT e.* FROM patient_review_event e JOIN patient_review_request r ON r.id = e.request_id WHERE r.pid = @pid ORDER BY e.id;",
                    "SELECT id, HEX(uuid) AS uuid, questionnaire_id, status FROM questionnaire_response WHERE patient_id = @pid ORDER BY id;",
                    "SELECT id, HEX(uuid) AS uuid, status, description FROM task WHERE patient_id = @pid ORDER BY id;",
                    "SELECT 'task_input' AS t, COUNT(*) AS n FROM task_input i JOIN task t ON t.id = i.task_id WHERE t.patient_id = @pid",
                    "UNION ALL SELECT 'task_output', COUNT(*) FROM task_output o JOIN task t ON t.id = o.task_id WHERE t.patient_id = @pid;",
                ]),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
