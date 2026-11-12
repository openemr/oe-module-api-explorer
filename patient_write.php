<?php

/**
 * patient_write.php — JSON backend for the Patient app writes panel.
 *
 * Exercises what a patient-facing app can write through OpenEMR's FHIR API (openemr #15138,
 * #15144, #15162): with the Patient App FHIR Writes mode on, a patient token's
 * QuestionnaireResponse is held for staff review (Miscellaneous > Patient App Submissions)
 * instead of going into the chart. The app reads its held write back with a held-for-review
 * tag, can revise it while it is held, withdraw it with status entered-in-error, and gets 410
 * once it is withdrawn or denied. Approved, it keeps the same id and reads as a normal
 * QuestionnaireResponse.
 *
 * Vital signs, AllergyIntolerance and Condition have settings but are not served to patient
 * apps yet; the suite checks they are refused, and says so plainly if a server starts taking
 * them.
 *
 * Actions:
 *   status      what the session token is: patient id, granted patient scopes, the patient write
 *               scopes the server advertises
 *   seedVitals  create the "Home vital signs" Questionnaire (needs a staff token with
 *               user/Questionnaire.write); reports the existing one if it is already there
 *   run         ?questionnaire=<id>&keep=1  the held-write lifecycle and the must-refuse checks
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

const HELD_TAG_SYSTEM = 'http://open-emr.org/fhir/CodeSystem/patient-write';
const HELD_TAG_CODE = 'held-for-review';
const HOME_VITALS_URL = 'http://open-emr.org/fhir/Questionnaire/home-vital-signs';

$access = (string) ($_SESSION['access_token'] ?? '');
if ($access === '') {
    http_response_code(401);
    echo json_encode(['error' => 'No access token in session. Get one with the Public client (Authorization Code, PKCE) and log in as a portal patient.']);
    exit;
}

$fhirBase = rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/');
$action = (string) ($_GET['action'] ?? 'status');
$tokenResponse = is_array($_SESSION['token_response'] ?? null) ? $_SESSION['token_response'] : [];
$tokenScopes = array_values(array_filter(explode(' ', (string) ($_SESSION['access_token_scopes'] ?? $tokenResponse['scope'] ?? ''))));
$patientScopes = array_values(array_filter($tokenScopes, static fn(string $s): bool => str_starts_with($s, 'patient/')));
$userScopes = array_values(array_filter($tokenScopes, static fn(string $s): bool => str_starts_with($s, 'user/')));
$patientId = is_string($tokenResponse['patient'] ?? null) ? $tokenResponse['patient'] : '';
$patientFrom = $patientId !== '' ? 'token response' : '';
// A Patient Login token without launch/patient has no `patient` in the token response; the
// patient is the signed-in user. Take it from the id_token's fhirUser, else from a Patient
// search, which a patient token answers with only the patient's own record.
if ($patientId === '' && $patientScopes !== [] && $userScopes === []) {
    [$patientId, $patientFrom] = patientOfToken($tokenResponse, $fhirBase, $access);
}

/**
 * @param array<string, mixed> $tokenResponse
 * @return array{string, string} patient id and where it came from, or ['', '']
 */
function patientOfToken(array $tokenResponse, string $fhirBase, string $access): array
{
    $idToken = is_string($tokenResponse['id_token'] ?? null) ? explode('.', $tokenResponse['id_token']) : [];
    if (count($idToken) === 3) {
        $claims = json_decode((string) base64_decode(strtr($idToken[1], '-_', '+/')), true);
        $fhirUser = is_array($claims) && is_string($claims['fhirUser'] ?? null) ? $claims['fhirUser'] : '';
        if (preg_match('#(?:^|/)Patient/([A-Za-z0-9\-.]{1,64})$#', $fhirUser, $m)) {
            return [$m[1], 'id_token fhirUser'];
        }
    }
    $patients = entries(call('GET', "{$fhirBase}/Patient?_count=2", $access)['json']);
    if (count($patients) === 1 && is_string($patients[0]['id'] ?? null)) {
        return [$patients[0]['id'], 'Patient search (the only record the token reads)'];
    }
    return ['', ''];
}

/**
 * @param list<string> $headers
 * @return array{code:int, body:string, location:string, json:mixed}
 */
function call(string $method, string $url, string $access, ?array $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $location = '';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(
            ["Authorization: Bearer {$access}", 'Accept: application/fhir+json'],
            $body !== null ? ['Content-Type: application/fhir+json'] : [],
            $headers
        ),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            }
            return strlen($header);
        },
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';

    return ['code' => $code, 'body' => $text, 'location' => $location, 'json' => json_decode($text, true)];
}

/** @return list<array<string, mixed>> */
function entries(mixed $bundle): array
{
    $out = [];
    foreach (is_array($bundle) && is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
        if (is_array($entry['resource'] ?? null)) {
            $out[] = $entry['resource'];
        }
    }
    return $out;
}

/**
 * Home vital signs: its title and the canonical as url or as an identifier (urn:ietf:rfc:3986).
 * OpenEMR publishes its own url on create, so a seeded copy is known by the identifier.
 */
function isHomeVitals(mixed $questionnaire): bool
{
    if (!is_array($questionnaire) || ($questionnaire['title'] ?? null) !== 'Home vital signs') {
        return false;
    }
    if (($questionnaire['url'] ?? null) === HOME_VITALS_URL) {
        return true;
    }
    foreach (is_array($questionnaire['identifier'] ?? null) ? $questionnaire['identifier'] : [] as $identifier) {
        if (is_array($identifier) && ($identifier['system'] ?? null) === 'urn:ietf:rfc:3986' && ($identifier['value'] ?? null) === HOME_VITALS_URL) {
            return true;
        }
    }
    return false;
}

function isHeld(mixed $resource): bool
{
    foreach (is_array($resource) && is_array($resource['meta']['tag'] ?? null) ? $resource['meta']['tag'] : [] as $tag) {
        if (($tag['system'] ?? null) === HELD_TAG_SYSTEM && ($tag['code'] ?? null) === HELD_TAG_CODE) {
            return true;
        }
    }
    return false;
}

/** The first diagnostics or text of an OperationOutcome, else a short slice of the body. */
function why(array $r): string
{
    $issue = is_array($r['json']) ? ($r['json']['issue'][0] ?? null) : null;
    if (is_array($issue)) {
        return (string) ($issue['diagnostics'] ?? $issue['details']['text'] ?? $issue['code'] ?? '');
    }
    return mb_substr($r['body'], 0, 300);
}

/**
 * An answer for a Questionnaire item, by its type, or null for one the suite doesn't answer.
 *
 * @param array<string, mixed> $item
 * @return ?array<string, mixed>
 */
function answerFor(array $item, string $marker): ?array
{
    $options = is_array($item['answerOption'] ?? null) ? $item['answerOption'] : [];
    return match ($item['type'] ?? null) {
        'string', 'text' => ['valueString' => $marker],
        'integer' => ['valueInteger' => 72],
        'decimal' => ['valueDecimal' => 98.6],
        'boolean' => ['valueBoolean' => true],
        'date' => ['valueDate' => gmdate('Y-m-d')],
        'dateTime' => ['valueDateTime' => gmdate('Y-m-d\TH:i:s\Z')],
        'choice', 'open-choice' => is_array($options[0] ?? null)
            ? array_intersect_key($options[0], array_flip(['valueCoding', 'valueString', 'valueInteger', 'valueDate']))
            : null,
        default => null,
    };
}

/**
 * Answers for the first few answerable items, nested under their groups.
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function answerItems(array $items, string $marker, int &$budget): array
{
    $out = [];
    foreach ($items as $item) {
        if ($budget <= 0 || !is_string($item['linkId'] ?? null)) {
            continue;
        }
        if (($item['type'] ?? null) === 'group') {
            $children = answerItems(is_array($item['item'] ?? null) ? $item['item'] : [], $marker, $budget);
            if ($children !== []) {
                $out[] = ['linkId' => $item['linkId'], 'item' => $children];
            }
            continue;
        }
        $answer = answerFor($item, $marker);
        if ($answer !== null && $answer !== []) {
            $out[] = ['linkId' => $item['linkId'], 'text' => $item['text'] ?? null, 'answer' => [$answer]];
            $budget--;
        }
    }
    return array_map(static fn(array $i): array => array_filter($i, static fn($v): bool => $v !== null), $out);
}

/** @return list<string> */
function advertisedPatientWrites(string $fhirBase): array
{
    $r = call('GET', "{$fhirBase}/.well-known/smart-configuration", '');
    $scopes = is_array($r['json']) && is_array($r['json']['scopes_supported'] ?? null) ? $r['json']['scopes_supported'] : [];
    // v1 .write / .* or a v2 permission string that includes create or update.
    return array_values(array_filter(
        $scopes,
        static fn($s): bool => is_string($s)
            && preg_match('~^patient/[A-Za-z]+\.(?:write|\*|c?r?u?d?s?)$~', $s, $m) === 1
            && preg_match('~\.(?:write|\*|[^.]*[cu][^.]*)$~', $s) === 1
    ));
}

try {
    switch ($action) {
        case 'status':
            echo json_encode([
                'patient' => $patientId,
                'patientFrom' => $patientFrom,
                'patientScopes' => $patientScopes,
                'userScopes' => $userScopes,
                'clientType' => $_SESSION['client_type'] ?? null,
                'isPatientToken' => $patientId !== '' && $patientScopes !== [] && $userScopes === [],
                'advertised' => advertisedPatientWrites($fhirBase),
                'fhirBase' => $fhirBase,
            ]);
            break;

        case 'seedVitals':
            /** @var array{homeVitals: array<string, mixed>} $seeds */
            $seeds = require __DIR__ . '/patient_write_seeds.php';
            $definition = $seeds['homeVitals'];
            $search = call('GET', "{$fhirBase}/Questionnaire?title=" . rawurlencode((string) $definition['title']), $access);
            foreach (entries($search['json']) as $found) {
                if (isHomeVitals($found)) {
                    echo json_encode(['code' => 200, 'id' => $found['id'] ?? null, 'existing' => true, 'version' => $found['version'] ?? null]);
                    break 2;
                }
            }
            $r = call('POST', "{$fhirBase}/Questionnaire", $access, $definition);
            $id = is_array($r['json']) ? ($r['json']['uuid'] ?? $r['json']['id'] ?? null) : null;
            if ($id === null && $r['location'] !== '' && preg_match('~/Questionnaire/([^/]+)~', $r['location'], $m) === 1) {
                $id = $m[1];
            }
            echo json_encode([
                'code' => $r['code'],
                'id' => $id,
                'existing' => false,
                'why' => $r['code'] >= 300 ? why($r) : null,
                'hint' => in_array($r['code'], [401, 403], true)
                    ? 'Seeding needs a staff token with user/Questionnaire.write (Confidential client) and the admin/super ACL. Get one, seed, then fetch the patient token again.'
                    : null,
            ]);
            break;

        case 'run':
            if ($patientId === '' || $patientScopes === [] || $userScopes !== []) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'This needs a patient token: the Public client, Authorization Code, logged in as a portal patient. '
                        . 'The session token ' . ($patientId === '' ? 'has no patient' : 'carries user/ scopes') . '.',
                ]);
                exit;
            }
            $steps = [];
            $step = static function (string $name, bool|null $pass, string $detail, array $r = []) use (&$steps): void {
                $steps[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail]
                    + ($r !== [] ? ['code' => $r['code'], 'body' => is_array($r['json']) ? $r['json'] : mb_substr($r['body'], 0, 2000)] : []);
            };
            $marker = 'explorer patient-write ' . bin2hex(random_bytes(3));
            $keep = ($_GET['keep'] ?? '') === '1';

            // 1. Which Questionnaire to answer: the one asked for, else Home vital signs, else the first with items.
            $wanted = trim((string) ($_GET['questionnaire'] ?? ''));
            $questionnaire = null;
            if ($wanted !== '') {
                $r = call('GET', "{$fhirBase}/Questionnaire/" . rawurlencode($wanted), $access);
                $questionnaire = $r['code'] === 200 && is_array($r['json']) ? $r['json'] : null;
                $step('read Questionnaire/' . $wanted, $questionnaire !== null, $questionnaire !== null ? (string) ($questionnaire['title'] ?? $questionnaire['name'] ?? '') : why($r), $questionnaire === null ? $r : []);
            } else {
                $r = call('GET', "{$fhirBase}/Questionnaire?_count=100", $access);
                $all = entries($r['json']);
                foreach ($all as $candidate) {
                    if (isHomeVitals($candidate)) {
                        $questionnaire = $candidate;
                        break;
                    }
                }
                foreach ($questionnaire === null ? $all : [] as $candidate) {
                    if (!empty($candidate['item']) && ($candidate['status'] ?? 'active') === 'active') {
                        $questionnaire = $candidate;
                        break;
                    }
                }
                $step(
                    'find a Questionnaire',
                    $questionnaire !== null,
                    $questionnaire !== null
                        ? (string) ($questionnaire['title'] ?? $questionnaire['name'] ?? $questionnaire['id']) . (isHomeVitals($questionnaire) ? ' (Home vital signs)' : ' (Home vital signs not seeded; using another)')
                        : 'none readable with this token (HTTP ' . $r['code'] . ')',
                    $questionnaire === null ? $r : []
                );
            }
            if ($questionnaire === null || !is_string($questionnaire['id'] ?? null)) {
                echo json_encode(['steps' => $steps]);
                break;
            }
            $budget = 4;
            $items = answerItems(is_array($questionnaire['item'] ?? null) ? $questionnaire['item'] : [], $marker, $budget);
            $qr = [
                'resourceType' => 'QuestionnaireResponse',
                'status' => 'completed',
                'questionnaire' => 'Questionnaire/' . $questionnaire['id'],
                'subject' => ['reference' => 'Patient/' . $patientId],
                'authored' => gmdate('Y-m-d\TH:i:s\Z'),
                'item' => $items,
            ];

            // 2. Create: held for review, not written to the chart.
            $created = call('POST', "{$fhirBase}/QuestionnaireResponse", $access, $qr);
            $id = is_array($created['json']) && is_string($created['json']['id'] ?? null) ? $created['json']['id'] : null;
            $createdOk = $created['code'] === 201 && $id !== null && isHeld($created['json']);
            $step(
                'POST QuestionnaireResponse',
                $createdOk,
                $createdOk
                    ? 'held for review as ' . $id . ($created['location'] !== '' ? ', Location ' . $created['location'] : ', no Location header')
                    : (in_array($created['code'], [401, 403], true)
                        ? 'refused: ' . why($created) . ' — check Patient App FHIR Writes (mode allows this client type, QuestionnaireResponse on) and that the token has patient/QuestionnaireResponse.cu or .write'
                        : why($created)),
                $created
            );
            if (!$createdOk) {
                echo json_encode(['steps' => $steps, 'questionnaire' => $questionnaire['id']]);
                break;
            }

            // 3. Read it back by id: the held write, tagged.
            $read = call('GET', "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($id), $access);
            $step('GET by id', $read['code'] === 200 && isHeld($read['json']), $read['code'] === 200 ? (isHeld($read['json']) ? 'held-for-review tag present' : 'no held tag') : why($read), $read['code'] === 200 ? [] : $read);

            // 4. A patient search shows the app's held writes alongside the chart's.
            $search = call('GET', "{$fhirBase}/QuestionnaireResponse?patient=" . rawurlencode($patientId) . '&_count=100', $access);
            $inSearch = in_array($id, array_map(static fn($e) => $e['id'] ?? null, entries($search['json'])), true);
            $step('search ?patient=', $search['code'] === 200 && $inSearch, $inSearch ? 'the held write is in the bundle' : 'not in the bundle (HTTP ' . $search['code'] . ')');

            // 5. Only _id, patient, subject and _count bring held writes in; any other parameter
            //    searches the chart alone. Information for app authors, not a pass/fail.
            $sorted = call('GET', "{$fhirBase}/QuestionnaireResponse?patient=" . rawurlencode($patientId) . '&_sort=-authored&_count=100', $access);
            $inSorted = in_array($id, array_map(static fn($e) => $e['id'] ?? null, entries($sorted['json'])), true);
            $step('search ?patient=&_sort=', null, $inSorted
                ? 'the held write is included with _sort'
                : 'not included with _sort (HTTP ' . $sorted['code'] . '): only _id, patient, subject and _count bring held writes in, so sort on the client');

            // 6. Revise while held.
            $revised = $qr + ['id' => $id];
            $budget = 4;
            $revised['item'] = answerItems(is_array($questionnaire['item'] ?? null) ? $questionnaire['item'] : [], $marker . ' revised', $budget);
            $put = call('PUT', "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($id), $access, $revised);
            $step('PUT revise while held', $put['code'] === 200 && isHeld($put['json']), $put['code'] === 200 ? 'revised, still held' : why($put), $put['code'] === 200 ? [] : $put);

            // 7-10. Must-refuse checks.
            $mismatch = call('PUT', "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($id), $access, ['id' => $id . 'x'] + $revised);
            $step('PUT with a body id that is not the URL id', $mismatch['code'] === 400, 'HTTP ' . $mismatch['code'] . ' ' . why($mismatch) . ' (expect 400)');

            $strangerId = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
            $notHeld = call('PUT', "{$fhirBase}/QuestionnaireResponse/{$strangerId}", $access, ['id' => $strangerId] + $qr);
            $step('PUT on an id the app did not write', $notHeld['code'] === 403, 'HTTP ' . $notHeld['code'] . ' ' . why($notHeld) . ' (expect 403)');

            $other = call('POST', "{$fhirBase}/QuestionnaireResponse", $access, ['subject' => ['reference' => 'Patient/' . $strangerId]] + $qr);
            $step('POST for another patient', $other['code'] === 403, 'HTTP ' . $other['code'] . ' ' . why($other) . ' (expect 403)');

            // Types that have a setting but are not served to patient apps yet.
            $notServed = [
                'Observation (vital-signs)' => ['Observation', [
                    'resourceType' => 'Observation', 'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8867-4', 'display' => 'Heart rate']]],
                    'subject' => ['reference' => 'Patient/' . $patientId],
                    'effectiveDateTime' => gmdate('Y-m-d\TH:i:s\Z'),
                    'valueQuantity' => ['value' => 72, 'unit' => '/min', 'system' => 'http://unitsofmeasure.org', 'code' => '/min'],
                ]],
                'AllergyIntolerance' => ['AllergyIntolerance', [
                    'resourceType' => 'AllergyIntolerance',
                    'clinicalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical', 'code' => 'active']]],
                    'code' => ['text' => 'Penicillin'],
                    'patient' => ['reference' => 'Patient/' . $patientId],
                ]],
                'Condition' => ['Condition', [
                    'resourceType' => 'Condition',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'problem-list-item']]]],
                    'code' => ['text' => 'Seasonal allergies'],
                    'subject' => ['reference' => 'Patient/' . $patientId],
                ]],
            ];
            foreach ($notServed as $label => [$type, $body]) {
                $r = call('POST', "{$fhirBase}/{$type}", $access, $body);
                $acceptedHeld = $r['code'] === 201 && isHeld($r['json']);
                if ($acceptedHeld) {
                    // A server that serves this type now: withdraw the probe at once.
                    $heldId = (string) ($r['json']['id'] ?? '');
                    $withdraw = $body + ['id' => $heldId];
                    if ($type === 'Observation') {
                        $withdraw['status'] = 'entered-in-error';
                    } else {
                        $withdraw['verificationStatus'] = ['coding' => [['system' => $type === 'Condition'
                            ? 'http://terminology.hl7.org/CodeSystem/condition-ver-status'
                            : 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification', 'code' => 'entered-in-error']]];
                    }
                    call('PUT', "{$fhirBase}/{$type}/" . rawurlencode($heldId), $access, $withdraw);
                }
                $step(
                    'POST ' . $label . ' (not served to patient apps yet)',
                    $acceptedHeld ? null : in_array($r['code'], [401, 403], true),
                    $acceptedHeld
                        ? 'ACCEPTED and held: this server now serves ' . $type . ' to patient apps (probe withdrawn). Update the Explorer.'
                        : 'HTTP ' . $r['code'] . ' ' . why($r) . ' (expect 401/403)',
                    in_array($r['code'], [401, 403, 201], true) ? [] : $r
                );
            }

            // 11. Withdraw, then the write is gone (410), unless it should wait for staff.
            if ($keep) {
                $step('left for staff review', null, $id . ' is waiting in Miscellaneous > Patient App Submissions. Approve it and it keeps this id; deny it and GET answers 410.');
            } else {
                $withdrawn = call('PUT', "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($id), $access, ['status' => 'entered-in-error'] + $revised);
                $step('PUT entered-in-error (withdraw)', $withdrawn['code'] === 200, 'HTTP ' . $withdrawn['code'] . ($withdrawn['code'] === 200 ? '' : ' ' . why($withdrawn)));
                $gone = call('GET', "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($id), $access);
                $step('GET after withdrawing', $gone['code'] === 410, 'HTTP ' . $gone['code'] . ' (expect 410)');
                $again = call('PUT', "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($id), $access, $revised);
                $step('PUT on a withdrawn write', $again['code'] === 410, 'HTTP ' . $again['code'] . ' ' . why($again) . ' (expect 410)');
            }

            echo json_encode(['steps' => $steps, 'questionnaire' => $questionnaire['id'], 'id' => $id]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('patient_write.php: ' . $e->getMessage());
    echo json_encode(['error' => 'The Explorer failed running this action; see the PHP error log.']);
}
