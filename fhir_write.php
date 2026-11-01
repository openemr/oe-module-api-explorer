<?php

/**
 * fhir_write.php — FHIR write (POST/PUT) driver for the API Explorer.
 *
 * AJAX endpoint that SHARES the Explorer's browser session and OAuth token
 * ($_SESSION['access_token'], $_SESSION['selectedSite']). Like group_export.php and
 * scope_resources.php it bootstraps from session + config only -- it does NOT pull
 * interface/globals.php, so there's no HTTP_HOST / site-id / CLI problem.
 *
 * The bearer token never leaves the server: the browser posts a body, this file
 * attaches the Authorization header.
 *
 * Actions (?action=):
 *   catalog          the write matrix from fhir_write_templates.php, plus the scopes the
 *                    current token actually carries, so the UI can grey out what will 401
 *   context          resolve live uuids to fill the body placeholders (patient,
 *                    practitioner, encounter, organizations, facility, questionnaire,
 *                    serviceRequest, documentReference)
 *   send             POST body {resource, verb, id?, body, ifMatch?} -> perform the write,
 *                    return status + response body + Location + ETag
 *   search           ?resource=..&query=..  a GET search on a write resource (the Task loop
 *                    checks find their Task by focus, owner and status)
 *   referrals        the referral ServiceRequests (category 3457005) with the Tasks that name
 *                    each as focus, marking the referral's own Task (the Referral loop panel)
 *   output           ?task=..  follow a Task's outputs: each DocumentReference, and the text
 *                    its attachment's Binary holds
 *   verify           ?resource=..&id=..  read the resource back (by id, or by _id search
 *                    for the resources that have no read-by-id route)
 *   seed             create the prerequisites the bodies reference -- patient, practitioner,
 *                    facility, insurer, encounter, questionnaire -- by POSTing them through the
 *                    same API under test, so the workbench works on an install with no demo data
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
require_once __DIR__ . '/src/ScopeAlgebra.php';

use OpenEMR\ApiExplorer\ScopeLab\ScopeAlgebra;

/** @var array<string, array<string, mixed>> $writeMatrix */
$writeMatrix = require __DIR__ . '/fhir_write_templates.php';

/** @var array<string, array<string, mixed>> $seedFixtures */
$seedFixtures = require __DIR__ . '/fhir_write_seeds.php';

$access = (string) ($_SESSION['access_token'] ?? '');
if ($access === '') {
    http_response_code(401);
    echo json_encode([
        'error' => 'No access token in session. Obtain a token in the Explorer first '
            . '(Auth Code with a confidential/limited client carries the user/<Resource>.write scopes).',
    ]);
    exit;
}

$fhirBase = rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/');
$action = (string) ($_GET['action'] ?? 'catalog');

/**
 * @param array<int, string> $headers
 * @return array{code:int, body:string, location:string, etag:string}
 */
function fhirCall(string $method, string $url, string $access, array $headers = [], ?string $payload = null): array
{
    $ch = curl_init($url);
    $location = '';
    $etag = '';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(["Authorization: Bearer {$access}"], $headers),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$location, &$etag) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            } elseif (stripos($header, 'ETag:') === 0) {
                $etag = trim(substr($header, strlen('ETag:')));
            }
            return strlen($header);
        },
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($code === 0 && $curlError !== '') {
        return ['code' => 0, 'body' => json_encode(['transport_error' => $curlError]) ?: '', 'location' => '', 'etag' => ''];
    }

    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'location' => $location, 'etag' => $etag];
}

/**
 * First entry id out of a search bundle, optionally the first entry matching $pick.
 *
 * Returns the outcome rather than just the id: a null id can mean the read was refused
 * (no `.read` scope for that resource), that the search succeeded but the database holds
 * no such records yet, or that records exist but none is of the required kind. Those three
 * need completely different fixes, so the caller gets enough to say which one happened.
 *
 * With $strict the pick must match: returning some other entry would quietly hand back a
 * resource of the wrong kind (a provider Organization standing in for an insurer, say),
 * which then fails much later as an opaque validation error.
 *
 * @param callable(array<string, mixed>): bool|null $pick
 * @return array{id: ?string, ids: list<string>, code: int, entries: int, matched: bool}
 */
function firstBundleEntry(string $url, string $access, ?callable $pick = null, bool $strict = false): array
{
    $r = fhirCall('GET', $url, $access, ['Accept: application/fhir+json']);
    $outcome = ['id' => null, 'ids' => [], 'code' => $r['code'], 'entries' => 0, 'matched' => false];
    if ($r['code'] !== 200) {
        return $outcome;
    }
    $bundle = json_decode($r['body'], true);
    if (!is_array($bundle)) {
        return $outcome;
    }
    $fallback = null;
    foreach (($bundle['entry'] ?? []) as $entry) {
        $resource = $entry['resource'] ?? null;
        if (!is_array($resource) || !is_string($resource['id'] ?? null)) {
            continue;
        }
        $outcome['entries']++;
        $fallback ??= $resource['id'];
        if ($pick === null || $pick($resource)) {
            $outcome['matched'] = true;
            $outcome['ids'][] = $resource['id'];
            $outcome['id'] ??= $resource['id'];
        }
    }
    if ($outcome['id'] === null && !$strict) {
        $outcome['id'] = $fallback;
    }

    return $outcome;
}

/**
 * Plain-language reason a context key could not be resolved, or null when it was.
 *
 * @param array{id: ?string, code: int, entries: int, matched: bool} $outcome
 */
function contextFailureReason(array $outcome, string $resource, bool $strict): ?string
{
    if ($outcome['id'] !== null) {
        return null;
    }
    if ($outcome['code'] === 0) {
        return 'the search request never reached the server';
    }
    if ($outcome['code'] === 401 || $outcome['code'] === 403) {
        return "HTTP {$outcome['code']} - your token carries no user/{$resource}.read scope";
    }
    if ($outcome['code'] !== 200) {
        return "the search answered HTTP {$outcome['code']}";
    }
    if ($outcome['entries'] === 0) {
        return "the search succeeded but no {$resource} records exist yet - load demo data";
    }
    if ($strict) {
        return "{$outcome['entries']} {$resource} record(s) found, none of the required kind";
    }

    return "{$outcome['entries']} {$resource} record(s) found but none carried an id";
}

/**
 * True when an Organization carries one of the given OpenEMR type codes.
 *
 * Compared case-insensitively on purpose. FhirOrganizationService declares the constants as
 * `Ins` / `Pay` / `Prov` and matches against them when parsing an incoming write, but the READ
 * side emits lowercase: FhirOrganizationFacilityService and FhirOrganizationProcedureProviderService
 * both add `code => 'prov'` and FhirOrganizationInsuranceService adds `code => 'ins'`. A
 * case-sensitive compare against the constants therefore matches nothing a search returns.
 *
 * @param array<string, mixed> $organization
 * @param array<int, string> $codes
 */
function organizationHasType(array $organization, array $codes): bool
{
    $wanted = array_map('strtolower', $codes);
    foreach (($organization['type'] ?? []) as $type) {
        foreach ((is_array($type) ? ($type['coding'] ?? []) : []) as $coding) {
            if (is_array($coding) && in_array(strtolower((string) ($coding['code'] ?? '')), $wanted, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Finds an Organization that is a row in `facility`.
 *
 * Appointment and PractitionerRole writes resolve their Location / Organization reference
 * against `facility`.`uuid`, so that is the id needed. The trouble is telling a facility
 * from a procedure provider: FhirOrganizationFacilityService and
 * FhirOrganizationProcedureProviderService both emit type `prov`.
 *
 * (An earlier version probed GET /Location/<organization id>. That can never succeed: a
 * Location's id is its own uuid_mapping row, not the facility's uuid.)
 *
 * Two signals, in order:
 *   1. Location.managingOrganization. FhirLocationService sets it on every Location to the
 *      primary business entity, which is always a facility.
 *   2. Shape. A facility Organization carries an address and telecom; a procedure provider
 *      carries only a name and an NPI.
 *
 * @return array{id: ?string, how: ?string, code: int, provCount: int}
 */
function resolveFacilityOrganization(string $fhirBase, string $access): array
{
    $accept = ['Accept: application/fhir+json'];

    $locations = fhirCall('GET', "{$fhirBase}/Location?_count=5", $access, $accept);
    $bundle = $locations['code'] === 200 ? json_decode($locations['body'], true) : null;
    foreach (is_array($bundle) && is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
        $reference = is_array($entry) ? ($entry['resource']['managingOrganization']['reference'] ?? null) : null;
        if (is_string($reference) && preg_match('~(?:^|/)Organization/([A-Za-z0-9.-]+)$~', $reference, $m) === 1) {
            $check = fhirCall('GET', "{$fhirBase}/Organization/" . rawurlencode($m[1]), $access, $accept);
            if ($check['code'] === 200) {
                return ['id' => $m[1], 'how' => 'Location.managingOrganization', 'code' => 200, 'provCount' => 1];
            }
        }
    }

    $organizations = fhirCall('GET', "{$fhirBase}/Organization?_count=100", $access, $accept);
    $bundle = $organizations['code'] === 200 ? json_decode($organizations['body'], true) : null;
    $provCount = 0;
    foreach (is_array($bundle) && is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
        $organization = is_array($entry) && is_array($entry['resource'] ?? null) ? $entry['resource'] : null;
        if ($organization === null || !organizationHasType($organization, ['Prov'])) {
            continue;
        }
        $provCount++;
        $hasAddress = false;
        foreach (is_array($organization['address'] ?? null) ? $organization['address'] : [] as $address) {
            if (is_array($address) && array_intersect_key($address, array_flip(['line', 'city', 'state', 'postalCode'])) !== []) {
                $hasAddress = true;
                break;
            }
        }
        if (($hasAddress || !empty($organization['telecom'])) && is_string($organization['id'] ?? null)) {
            return ['id' => $organization['id'], 'how' => 'provider Organization with an address', 'code' => 200, 'provCount' => $provCount];
        }
    }

    return ['id' => null, 'how' => null, 'code' => $organizations['code'], 'provCount' => $provCount];
}

/** SNOMED CT 3457005 Patient referral: the category OpenEMR gives a referral ServiceRequest. */
const REFERRAL_CATEGORY = '3457005';

/**
 * True when a ServiceRequest is a referral from the Transactions referral form.
 *
 * @param array<string, mixed> $serviceRequest
 */
function isReferral(array $serviceRequest): bool
{
    foreach (is_array($serviceRequest['category'] ?? null) ? $serviceRequest['category'] : [] as $category) {
        foreach (is_array($category) && is_array($category['coding'] ?? null) ? $category['coding'] : [] as $coding) {
            if (is_array($coding) && ($coding['code'] ?? null) === REFERRAL_CATEGORY) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The ServiceRequest the write workbench's Task names as focus: one of the patient's orders,
 * never a referral.
 *
 * Task.focus must be in the Task's patient's compartment, so the search is by patient. A Task
 * with code fulfill, no partOf and a ServiceRequest focus is what OpenEMR takes for a referral's
 * own Task (ReferralTaskService::isReferralTask): on a real referral the workbench's Task would
 * become the referral's status, message Refer By on accepted / completed, and be cancelled by the
 * referral's next save as a Task for another recipient. On an order none of that happens. The
 * Referral loop panel works the referral's own Task instead.
 *
 * @param array<string, string> $reasons
 */
function resolveOrderFocus(string $fhirBase, string $access, string $patient, array &$reasons): ?string
{
    $outcome = firstBundleEntry(
        "{$fhirBase}/ServiceRequest?patient=" . rawurlencode($patient) . '&_count=50',
        $access,
        static fn(array $serviceRequest): bool => !isReferral($serviceRequest),
        true
    );
    $reason = contextFailureReason($outcome, 'ServiceRequest', true);
    if ($reason !== null) {
        $reasons['serviceRequest'] = $reason . ' (an order, not a referral) for this patient - use Create missing fixtures';
    }

    return $outcome['id'];
}

/**
 * The id out of a literal reference of the given type ("Patient/abc" -> "abc"), or null.
 */
function referenceId(mixed $reference, string $type): ?string
{
    $literal = is_array($reference) ? ($reference['reference'] ?? null) : null;
    if (!is_string($literal) || preg_match('~(?:^|/)' . preg_quote($type, '~') . '/([A-Za-z0-9.-]+)$~', $literal, $m) !== 1) {
        return null;
    }

    return $m[1];
}

/**
 * A reference as "Type/id (display)" for the panel.
 */
function referenceLabel(mixed $reference): ?string
{
    if (!is_array($reference)) {
        return null;
    }
    $literal = is_string($reference['reference'] ?? null) ? $reference['reference'] : null;
    $display = is_string($reference['display'] ?? null) ? $reference['display'] : null;
    if ($literal === null) {
        return $display;
    }

    return $display === null ? $literal : "{$literal} ({$display})";
}

/**
 * The entries of a search bundle as resources.
 *
 * @return list<array<string, mixed>>
 */
function bundleResources(string $body): array
{
    $bundle = json_decode($body, true);
    $resources = [];
    foreach (is_array($bundle) && is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
        if (is_array($entry) && is_array($entry['resource'] ?? null)) {
            $resources[] = $entry['resource'];
        }
    }

    return $resources;
}

/**
 * Substitutes {{placeholders}} at any depth, the server-side twin of the card's fill().
 *
 * @param mixed $value
 * @param array<string, string|null> $context
 * @return mixed
 */
function fillPlaceholders(mixed $value, array $context): mixed
{
    if (is_string($value)) {
        return preg_replace_callback(
            '/{{(\w+)}}/',
            static fn(array $m): string => $context[$m[1]] ?? $m[0],
            $value
        );
    }
    if (is_array($value)) {
        return array_map(static fn(mixed $item): mixed => fillPlaceholders($item, $context), $value);
    }

    return $value;
}

try {
    switch ($action) {
        case 'catalog':
            $catalog = [];
            $tokenScopes = explode(' ', trim((string) ($_SESSION['access_token_scopes'] ?? '')));
            // The scope string OpenEMR returns with a token leaves out api:fhir on purpose
            // (IdTokenSMARTResponse::getScopeString), though the token carries it. Judging the
            // resource scopes behind that gate reported every resource as "scope missing".
            $gatedScopes = array_merge($tokenScopes, ['api:fhir']);
            foreach ($writeMatrix as $resource => $definition) {
                $catalog[$resource] = [
                    'status' => $definition['status'] ?? 'supported',
                    'reason' => $definition['reason'] ?? null,
                    // Any spelling that grants create counts: user/ or system/, v1 .write or v2
                    // .c*. Matching only the one v1 string reported "scope missing" for tokens
                    // that could write perfectly well.
                    'hasScope' => ScopeAlgebra::predict($gatedScopes, 'api:fhir', (string) $resource, 'c') === 'allow',
                    'scope' => $definition['scope'],
                    'acl' => $definition['acl'],
                    'verbs' => $definition['verbs'],
                    'readById' => $definition['readById'],
                    'needs' => $definition['needs'],
                    'idKey' => $definition['idKey'] ?? 'uuid',
                    'body' => $definition['body'],
                    'variants' => $definition['variants'] ?? null,
                    'checks' => $definition['checks'] ?? null,
                ];
            }
            echo json_encode([
                'catalog' => $catalog,
                'tokenScopes' => $tokenScopes,
                'fhirBase' => $fhirBase,
            ]);
            break;

        case 'context':
        case 'seed':
            // Each key records how it was resolved so the UI can say *why* a resource is
            // being skipped: a refused read, an empty database, or data of the wrong kind.
            $reasons = [];
            $resolve = static function (
                string $key,
                string $resource,
                string $url,
                ?callable $pick = null,
                bool $strict = false
            ) use (
                $access,
                &$reasons
            ): ?string {
                $outcome = firstBundleEntry($url, $access, $pick, $strict);
                $reason = contextFailureReason($outcome, $resource, $strict);
                if ($reason !== null) {
                    $reasons[$key] = $reason;
                }
                return $outcome['id'];
            };

            $patient = $resolve('patient', 'Patient', "{$fhirBase}/Patient?_count=1");

            // Appointment and PractitionerRole both resolve their reference against
            // `facility`.`uuid`, which is the id of the facility's Organization.
            $facilityLookup = resolveFacilityOrganization($fhirBase, $access);
            $facility = $facilityLookup['id'];
            $facilityReason = null;
            if ($facility === null) {
                if ($facilityLookup['code'] === 401 || $facilityLookup['code'] === 403) {
                    $facilityReason = "HTTP {$facilityLookup['code']} - your token carries no user/Organization.read scope";
                } elseif ($facilityLookup['code'] !== 200) {
                    $facilityReason = "the Organization search answered HTTP {$facilityLookup['code']}";
                } elseif ($facilityLookup['provCount'] === 0) {
                    $facilityReason = 'no provider Organization found - add a facility under Admin > Clinic > Facilities, or use Create missing fixtures';
                } else {
                    $facilityReason = $facilityLookup['provCount'] . ' provider Organization(s) found, but none could be identified as a facility:'
                        . ' no Location names a managing organization (no facility is flagged Primary Business Entity)'
                        . ' and none has an address or phone. Give a facility an address, or flag one as Primary Business Entity';
                }
            }
            if ($facilityReason !== null) {
                $reasons['facility'] = $facilityReason;
                $reasons['orgProvider'] = $facilityReason;
            }

            $context = [
                'patient' => $patient,
                'practitioner' => $resolve('practitioner', 'Practitioner', "{$fhirBase}/Practitioner?_count=1"),
                'facility' => $facility,
                'orgProvider' => $facility,
                'orgInsurer' => $resolve(
                    'orgInsurer',
                    'Organization',
                    "{$fhirBase}/Organization?_count=50",
                    static fn(array $o): bool => organizationHasType($o, ['Ins', 'Pay']),
                    true
                ),
                'encounter' => $patient !== null
                    ? $resolve(
                        'encounter',
                        'Encounter',
                        "{$fhirBase}/Encounter?patient=" . rawurlencode($patient) . '&_count=1'
                    )
                    : null,
                'questionnaire' => $resolve('questionnaire', 'Questionnaire', "{$fhirBase}/Questionnaire?_count=1"),
                'serviceRequest' => $patient !== null ? resolveOrderFocus($fhirBase, $access, $patient, $reasons) : null,
                // Optional: the Task loop's reply step names it in Task.output, and completes
                // with the reply as text when the patient has no DocumentReference.
                'documentReference' => $patient !== null
                    ? $resolve(
                        'documentReference',
                        'DocumentReference',
                        "{$fhirBase}/DocumentReference?patient=" . rawurlencode($patient) . '&_count=1'
                    )
                    : null,
                'unique' => substr(bin2hex(random_bytes(4)), 0, 8),
                // A numeric per-run token, for identifier fields that must be digits (NPI).
                'uniqueDigits' => str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT),
            ];
            if ($patient === null) {
                $reasons['encounter'] = 'no patient to search encounters for';
                $reasons['serviceRequest'] = 'no patient to search service requests for';
                $reasons['documentReference'] = 'no patient to search documents for';
            }
            $missing = array_keys(array_filter($context, static fn($v): bool => $v === null));

            if ($action === 'context') {
                echo json_encode(['context' => $context, 'missing' => $missing, 'reasons' => $reasons]);
                break;
            }

            // seed: create what is missing, in the order the fixtures are declared, so a body
            // that references an earlier key ({{patient}} in the encounter) is filled from the
            // resource created a moment ago rather than from a stale read.
            $created = [];
            foreach ($seedFixtures as $key => $fixture) {
                if (($context[$key] ?? null) !== null) {
                    continue;
                }
                $blockedBy = array_values(array_filter(
                    $fixture['needs'] ?? [],
                    static fn(string $need): bool => ($context[$need] ?? null) === null
                ));
                if ($blockedBy !== []) {
                    $created[$key] = [
                        'ok' => false,
                        'resource' => $fixture['resource'],
                        'error' => 'skipped: needs ' . implode(', ', $blockedBy) . ' first',
                    ];
                    continue;
                }

                $payload = json_encode(fillPlaceholders($fixture['body'], $context), JSON_UNESCAPED_SLASHES);
                $response = fhirCall(
                    'POST',
                    "{$fhirBase}/{$fixture['resource']}",
                    $access,
                    ['Accept: application/fhir+json', 'Content-Type: application/fhir+json'],
                    is_string($payload) ? $payload : '{}'
                );
                $decoded = json_decode($response['body'], true);
                $newId = is_array($decoded)
                    ? ($decoded['uuid'] ?? $decoded['euuid'] ?? $decoded['pc_uuid'] ?? $decoded['id'] ?? null)
                    : null;

                if ($response['code'] === 201 && is_string($newId)) {
                    $context[$key] = $newId;
                    if ($key === 'facility') {
                        $context['orgProvider'] = $newId;
                    }
                    unset($reasons[$key], $reasons['orgProvider']);
                    $created[$key] = ['ok' => true, 'resource' => $fixture['resource'], 'id' => $newId];
                } else {
                    $created[$key] = [
                        'ok' => false,
                        'resource' => $fixture['resource'],
                        'code' => $response['code'],
                        'error' => $response['code'] === 401 || $response['code'] === 403
                            ? "HTTP {$response['code']} - your token carries no {$fixture['scope']}"
                            : "HTTP {$response['code']}",
                        'body' => is_array($decoded) ? $decoded : $response['body'],
                    ];
                }
            }

            $missing = array_keys(array_filter($context, static fn($v): bool => $v === null));
            echo json_encode([
                'context' => $context,
                'missing' => $missing,
                'reasons' => $reasons,
                'created' => $created,
            ]);
            break;

        case 'send':
            $raw = file_get_contents('php://input');
            $request = json_decode(is_string($raw) ? $raw : '', true);
            if (!is_array($request)) {
                http_response_code(400);
                echo json_encode(['error' => 'Request must be a JSON object']);
                exit;
            }

            $resource = (string) ($request['resource'] ?? '');
            $verb = strtoupper((string) ($request['verb'] ?? ''));
            $id = (string) ($request['id'] ?? '');
            $body = $request['body'] ?? null;

            if (!isset($writeMatrix[$resource])) {
                http_response_code(400);
                echo json_encode(['error' => "Unknown write resource: {$resource}"]);
                exit;
            }
            if (!in_array($verb, $writeMatrix[$resource]['verbs'], true)) {
                http_response_code(400);
                echo json_encode(['error' => "{$resource} does not support {$verb}"]);
                exit;
            }
            if (!is_array($body)) {
                http_response_code(400);
                echo json_encode(['error' => 'body must be a JSON object']);
                exit;
            }
            if ($verb === 'PUT' && $id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'PUT requires the resource id']);
                exit;
            }

            $url = "{$fhirBase}/{$resource}" . ($verb === 'PUT' ? '/' . rawurlencode($id) : '');
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers = ['Accept: application/fhir+json', 'Content-Type: application/fhir+json'];
            // Task honours If-Match (W/"versionId") and answers 412 when the version is stale.
            $ifMatch = trim((string) ($request['ifMatch'] ?? ''));
            if ($ifMatch !== '' && preg_match('/^[\x20-\x7E]+$/', $ifMatch) === 1) {
                $headers[] = 'If-Match: ' . $ifMatch;
            }
            $r = fhirCall($verb, $url, $access, $headers, is_string($payload) ? $payload : '{}');

            $decoded = json_decode($r['body'], true);
            $hint = null;
            if ($r['code'] === 401 || $r['code'] === 403) {
                $hint = "Token is missing {$writeMatrix[$resource]['scope']}, or the user lacks the "
                    . "{$writeMatrix[$resource]['acl']} ACL.";
            } elseif ($r['code'] === 0) {
                $hint = 'The request never reached the server -- check FHIR_SERVER_URL and TLS.';
            } elseif ($r['code'] === 412) {
                $hint = 'If-Match named a version the resource no longer has: read it again for its ETag.';
            }

            echo json_encode([
                'request' => ['method' => $verb, 'url' => $url] + ($ifMatch !== '' ? ['ifMatch' => $ifMatch] : []),
                'code' => $r['code'],
                'location' => $r['location'],
                'etag' => $r['etag'],
                // The created/updated id. A create answers the OpenEMR insert result, which
                // does not name the id consistently across resources -- Encounter answers
                // `euuid` and Appointment `pc_uuid` -- so the key comes from the matrix.
                // An update answers the FHIR resource itself, keyed `id`.
                'id' => is_array($decoded)
                    ? ($decoded[$writeMatrix[$resource]['idKey'] ?? 'uuid'] ?? $decoded['id'] ?? null)
                    : null,
                'body' => is_array($decoded) ? $decoded : $r['body'],
                'hint' => $hint,
            ]);
            break;

        case 'verify':
            $resource = (string) ($_GET['resource'] ?? '');
            $id = (string) ($_GET['id'] ?? '');
            if (!isset($writeMatrix[$resource]) || $id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'resource and id are required']);
                exit;
            }

            $url = $writeMatrix[$resource]['readById']
                ? "{$fhirBase}/{$resource}/" . rawurlencode($id)
                : "{$fhirBase}/{$resource}?_id=" . rawurlencode($id);
            $r = fhirCall('GET', $url, $access, ['Accept: application/fhir+json']);
            $decoded = json_decode($r['body'], true);

            // A bundle search answers 200 with total 0 when nothing matched, which is a
            // miss rather than a hit.
            $found = $r['code'] === 200;
            if ($found && !$writeMatrix[$resource]['readById']) {
                $found = is_array($decoded) && !empty($decoded['entry']);
            }

            echo json_encode([
                'request' => ['method' => 'GET', 'url' => $url],
                'code' => $r['code'],
                'found' => $found,
                'byIdRoute' => $writeMatrix[$resource]['readById'],
                'body' => is_array($decoded) ? $decoded : $r['body'],
            ]);
            break;

        case 'referrals':
            // Each referral with the Tasks that name it. The referral's own Task is the one
            // OpenEMR made when the referral was saved: code fulfill, no partOf, owned by the
            // referral's performer (Refer To). Others are sub-Tasks or Tasks an API client made.
            $accept = ['Accept: application/fhir+json'];
            $limit = max(1, min(50, (int) ($_GET['count'] ?? 20)));
            $query = 'category=' . rawurlencode('http://snomed.info/sct|' . REFERRAL_CATEGORY) . '&_count=' . $limit;
            $patientFilter = trim((string) ($_GET['patient'] ?? ''));
            if ($patientFilter !== '') {
                $query .= '&patient=' . rawurlencode($patientFilter);
            }
            $url = "{$fhirBase}/ServiceRequest?{$query}";
            $found = fhirCall('GET', $url, $access, $accept);
            if ($found['code'] !== 200) {
                echo json_encode([
                    'request' => ['method' => 'GET', 'url' => $url],
                    'code' => $found['code'],
                    'hint' => $found['code'] === 401 || $found['code'] === 403
                        ? 'Your token carries no user/ServiceRequest.read scope.'
                        : null,
                    'body' => json_decode($found['body'], true) ?? $found['body'],
                ]);
                break;
            }

            $referrals = [];
            foreach (bundleResources($found['body']) as $serviceRequest) {
                $id = is_string($serviceRequest['id'] ?? null) ? $serviceRequest['id'] : null;
                if ($id === null || !isReferral($serviceRequest)) {
                    continue;
                }
                $performers = is_array($serviceRequest['performer'] ?? null) ? $serviceRequest['performer'] : [];
                $performer = $performers[0] ?? null;
                $performerRef = is_array($performer) && is_string($performer['reference'] ?? null) ? $performer['reference'] : null;

                $taskSearch = fhirCall('GET', "{$fhirBase}/Task?focus=" . rawurlencode("ServiceRequest/{$id}"), $access, $accept);
                $tasks = [];
                $ownTask = null;
                foreach ($taskSearch['code'] === 200 ? bundleResources($taskSearch['body']) : [] as $task) {
                    $codes = array_map(
                        static fn($c) => is_array($c) ? ($c['code'] ?? null) : null,
                        is_array($task['code']['coding'] ?? null) ? $task['code']['coding'] : []
                    );
                    $owner = is_array($task['owner'] ?? null) && is_string($task['owner']['reference'] ?? null) ? $task['owner']['reference'] : null;
                    $own = in_array('fulfill', $codes, true) && empty($task['partOf'])
                        && $owner !== null && $performerRef !== null
                        && strcasecmp(preg_replace('~^.*/(\w+/[^/]+)$~', '$1', $owner) ?? $owner, preg_replace('~^.*/(\w+/[^/]+)$~', '$1', $performerRef) ?? $performerRef) === 0;
                    $row = [
                        'id' => $task['id'] ?? null,
                        'status' => $task['status'] ?? null,
                        'version' => $task['meta']['versionId'] ?? null,
                        'owner' => referenceLabel($task['owner'] ?? null),
                        'partOf' => !empty($task['partOf']),
                        'code' => implode(',', array_filter($codes, 'is_string')),
                        'businessStatus' => $task['businessStatus']['text'] ?? null,
                        'statusReason' => $task['statusReason']['text'] ?? null,
                        'outputs' => is_array($task['output'] ?? null) ? count($task['output']) : 0,
                        'own' => $own,
                    ];
                    $tasks[] = $row;
                    // The newest own Task stands for the referral, as ReferralStatus reads it.
                    if ($own && ($ownTask === null || !in_array($row['status'], ['cancelled', 'entered-in-error'], true))) {
                        $ownTask = $row['id'];
                    }
                }

                $referrals[] = [
                    'id' => $id,
                    'status' => $serviceRequest['status'] ?? null,
                    'authoredOn' => $serviceRequest['authoredOn'] ?? null,
                    'patient' => referenceLabel($serviceRequest['subject'] ?? null),
                    'requester' => referenceLabel($serviceRequest['requester'] ?? null),
                    'performer' => referenceLabel($performer),
                    'code' => $serviceRequest['code']['text'] ?? ($serviceRequest['code']['coding'][0]['display'] ?? null),
                    'taskSearchCode' => $taskSearch['code'],
                    'tasks' => $tasks,
                    'ownTask' => $ownTask,
                ];
            }

            echo json_encode([
                'request' => ['method' => 'GET', 'url' => $url],
                'code' => 200,
                'referrals' => $referrals,
            ]);
            break;

        case 'output':
            // Follow a Task's outputs the way a referrer's system would: Task.output ->
            // DocumentReference -> its attachment's Binary. The Binary url is only fetched when it
            // is on this FHIR server, so the bearer token never goes anywhere else.
            $taskId = (string) ($_GET['task'] ?? '');
            if ($taskId === '') {
                http_response_code(400);
                echo json_encode(['error' => 'task is required']);
                exit;
            }
            $task = fhirCall('GET', "{$fhirBase}/Task/" . rawurlencode($taskId), $access, ['Accept: application/fhir+json']);
            $taskBody = json_decode($task['body'], true);
            if ($task['code'] !== 200 || !is_array($taskBody)) {
                echo json_encode(['code' => $task['code'], 'step' => 'Task', 'body' => $taskBody ?? $task['body']]);
                break;
            }

            $outputs = [];
            foreach (is_array($taskBody['output'] ?? null) ? $taskBody['output'] : [] as $i => $output) {
                $row = [
                    'index' => $i,
                    'type' => is_array($output) ? ($output['type']['text'] ?? ($output['type']['coding'][0]['code'] ?? null)) : null,
                ];
                $docId = is_array($output) ? referenceId($output['valueReference'] ?? null, 'DocumentReference') : null;
                if ($docId === null) {
                    $row['value'] = is_array($output) ? array_diff_key($output, ['type' => true]) : $output;
                    $outputs[] = $row;
                    continue;
                }
                $doc = fhirCall('GET', "{$fhirBase}/DocumentReference/" . rawurlencode($docId), $access, ['Accept: application/fhir+json']);
                $docBody = json_decode($doc['body'], true);
                $row['documentReference'] = ['id' => $docId, 'code' => $doc['code']];
                if ($doc['code'] !== 200 || !is_array($docBody)) {
                    $row['documentReference']['hint'] = $doc['code'] === 401 || $doc['code'] === 403
                        ? 'Your token carries no user/DocumentReference.read scope - run Register Clients and get a new token.'
                        : null;
                    $outputs[] = $row;
                    continue;
                }
                $attachment = $docBody['content'][0]['attachment'] ?? [];
                $row['documentReference'] += [
                    'type' => $docBody['type']['coding'][0]['code'] ?? null,
                    'contentType' => $attachment['contentType'] ?? null,
                    'url' => $attachment['url'] ?? null,
                ];
                $binaryUrl = is_string($attachment['url'] ?? null) ? $attachment['url'] : '';
                if ($binaryUrl === '' || stripos($binaryUrl, $fhirBase . '/Binary/') !== 0) {
                    $row['binary'] = ['skipped' => 'the attachment url is not a Binary on this FHIR server'];
                    $outputs[] = $row;
                    continue;
                }
                $binary = fhirCall('GET', $binaryUrl, $access, ['Accept: application/fhir+json']);
                $content = $binary['body'];
                $decoded = json_decode($content, true);
                // Answered as a FHIR Binary (base64 data), or as the document's own bytes.
                if (is_array($decoded) && ($decoded['resourceType'] ?? null) === 'Binary') {
                    $content = base64_decode((string) ($decoded['data'] ?? ''), true) ?: '';
                }
                $isText = str_starts_with((string) ($attachment['contentType'] ?? ''), 'text/') || mb_check_encoding($content, 'UTF-8');
                $row['binary'] = [
                    'code' => $binary['code'],
                    'hint' => $binary['code'] === 401 || $binary['code'] === 403
                        ? 'Your token carries no user/Binary.read scope - run Register Clients and get a new token.'
                        : null,
                    'bytes' => strlen($content),
                    'text' => $binary['code'] === 200 && $isText ? mb_substr($content, 0, 20000) : null,
                ];
                $outputs[] = $row;
            }

            echo json_encode([
                'code' => 200,
                'task' => ['id' => $taskBody['id'] ?? $taskId, 'status' => $taskBody['status'] ?? null],
                'outputs' => $outputs,
            ]);
            break;

        case 'search':
            $resource = (string) ($_GET['resource'] ?? '');
            $query = ltrim((string) ($_GET['query'] ?? ''), '?');
            if (!isset($writeMatrix[$resource])) {
                http_response_code(400);
                echo json_encode(['error' => 'resource must be a write resource']);
                exit;
            }

            $url = "{$fhirBase}/{$resource}" . ($query !== '' ? '?' . $query : '');
            $r = fhirCall('GET', $url, $access, ['Accept: application/fhir+json']);
            $decoded = json_decode($r['body'], true);
            $ids = [];
            foreach (is_array($decoded) && is_array($decoded['entry'] ?? null) ? $decoded['entry'] : [] as $entry) {
                $entryId = is_array($entry) ? ($entry['resource']['id'] ?? null) : null;
                if (is_string($entryId)) {
                    $ids[] = $entryId;
                }
            }

            echo json_encode([
                'request' => ['method' => 'GET', 'url' => $url],
                'code' => $r['code'],
                'ids' => $ids,
                'body' => is_array($decoded) ? $decoded : $r['body'],
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => "Unknown action: {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'fhir_write failure', 'detail' => $e->getMessage()]);
}
