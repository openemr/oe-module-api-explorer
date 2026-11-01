<?php

/**
 * fhir_stress.php — concurrent load driver for the FHIR write endpoints.
 *
 * The sibling of fhir_write.php. That file answers one request at a time, which is the
 * right shape for exploring a payload but says nothing about what happens when two
 * writers hit the same allocator at once. This one issues N requests with up to C of
 * them in flight, and reports latency, status distribution and -- the point of the
 * exercise -- whether every create came back with a distinct id.
 *
 * WHY THE PARALLELISM LIVES HERE AND NOT IN THE BROWSER
 *
 * The obvious implementation is to fire C fetch() calls at fhir_write.php?action=send
 * and let the browser interleave them. That produces a test that cannot fail: every
 * Explorer endpoint calls session_start(), PHP's default session handler takes an
 * exclusive flock on the session file for the life of the request, so request 2 blocks
 * on the lock until request 1 has finished. The writes would run strictly one after
 * another while the UI reported a concurrency of 16. So this file reads what it needs
 * out of the session, calls session_write_close() to drop the lock, and drives the
 * fan-out with curl_multi from a single PHP request.
 *
 * The bearer token never reaches the browser: the caller posts a plan, this file
 * attaches the Authorization header to every handle.
 *
 * Actions (?action=):
 *   plan   describe what a given run would do -- request count, tag, the resources
 *          touched -- without sending anything
 *   run    execute the plan and return per-status counts, latency percentiles,
 *          duplicate-id analysis and a bounded sample of failures
 *
 * Every body written carries the run tag in its free-text field, so rows created by a
 * run can be found afterwards. There is no DELETE route on the FHIR API, so the panel
 * reports the tag and the SQL to find it rather than pretending it can clean up.
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

$access = (string) ($_SESSION['access_token'] ?? '');
$tokenScopes = explode(' ', trim((string) ($_SESSION['access_token_scopes'] ?? '')));

// Everything past this point is outbound HTTP. Holding the session lock across it would
// serialise the fan-out below and block every other Explorer tab for the whole run.
session_write_close();

if ($access === '') {
    http_response_code(401);
    echo json_encode([
        'error' => 'No access token in session. Obtain a token in the Explorer first '
            . '(Auth Code with a confidential/limited client carries the user/<Resource>.write scopes).',
    ]);
    exit;
}

$fhirBase = rtrim((string) $GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/');
$action = (string) ($_GET['action'] ?? 'plan');

// Bounds. A runaway run writes rows that nothing here can delete, so the ceiling is
// deliberately low enough to stay cleanable by hand.
const STRESS_MAX_ITERATIONS = 200;
const STRESS_MAX_CONCURRENCY = 32;
const STRESS_MAX_FAILURE_SAMPLES = 12;

/**
 * Substitutes {{placeholders}} at any depth. The twin of fhir_write.php's fillPlaceholders().
 *
 * @param array<string, string|null> $context
 */
function stressFill(mixed $value, array $context): mixed
{
    if (is_string($value)) {
        return preg_replace_callback(
            '/{{(\w+)}}/',
            static fn(array $m): string => $context[$m[1]] ?? $m[0],
            $value
        );
    }
    if (is_array($value)) {
        return array_map(static fn(mixed $item): mixed => stressFill($item, $context), $value);
    }

    return $value;
}

/**
 * Runs a list of requests with a bounded number in flight, preserving input order in the result.
 *
 * Each request: ['method' => string, 'url' => string, 'payload' => ?string, 'meta' => array].
 * Each result adds: code, ms, body, location, error.
 *
 * @param list<array{method:string, url:string, payload:?string, meta:array<string,mixed>}> $requests
 * @return list<array<string, mixed>>
 */
function stressBatch(array $requests, int $concurrency, string $access): array
{
    if ($requests === []) {
        return [];
    }
    $concurrency = max(1, min($concurrency, count($requests)));
    $multi = curl_multi_init();
    $results = [];
    $inFlight = [];
    $next = 0;

    $launch = static function (int $index) use (&$inFlight, $multi, $requests, $access): void {
        $request = $requests[$index];
        $handle = curl_init($request['url']);
        $headers = [
            "Authorization: Bearer {$access}",
            'Accept: application/fhir+json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            // A hung write must not hold a slot for the whole run.
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($request['payload'] !== null) {
            $headers[] = 'Content-Type: application/fhir+json';
            $opts[CURLOPT_POSTFIELDS] = $request['payload'];
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $opts);
        curl_multi_add_handle($multi, $handle);
        // spl_object_id, not (int): curl_init() answers a CurlHandle object in PHP 8, and
        // casting an object to int warns and yields 1 -- every handle would share one slot.
        $inFlight[spl_object_id($handle)] = ['index' => $index, 'handle' => $handle];
    };

    while ($next < count($requests) && count($inFlight) < $concurrency) {
        $launch($next++);
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($status !== CURLM_OK) {
            break;
        }
        // Blocks until something moves, so the loop is not a spin on the CPU.
        curl_multi_select($multi, 1.0);

        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            $key = spl_object_id($handle);
            $slot = $inFlight[$key] ?? null;
            if ($slot === null) {
                continue;
            }
            $index = $slot['index'];
            $bodyRaw = curl_multi_getcontent($handle);
            $curlError = curl_error($handle);
            $results[$index] = [
                'meta' => $requests[$index]['meta'],
                'method' => $requests[$index]['method'],
                'url' => $requests[$index]['url'],
                'code' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'ms' => round(((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000, 1),
                'body' => is_string($bodyRaw) ? $bodyRaw : '',
                'error' => $curlError !== '' ? $curlError : null,
            ];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            unset($inFlight[$key]);

            if ($next < count($requests)) {
                $launch($next++);
                $running = 1;
            }
        }
    } while ($running > 0 || $inFlight !== []);

    curl_multi_close($multi);
    ksort($results);

    return array_values($results);
}

/**
 * min / mean / p50 / p95 / max over a list of millisecond timings.
 *
 * @param list<float> $values
 * @return array<string, float>
 */
function stressLatency(array $values): array
{
    if ($values === []) {
        return ['min' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'max' => 0.0, 'mean' => 0.0];
    }
    sort($values);
    $count = count($values);
    $at = static function (float $q) use ($values, $count): float {
        // Nearest-rank: with 20 samples p95 is the 19th, not an interpolation between two.
        $rank = (int) ceil($q * $count);
        return $values[max(0, min($count - 1, $rank - 1))];
    };

    return [
        'min' => round($values[0], 1),
        'p50' => round($at(0.50), 1),
        'p95' => round($at(0.95), 1),
        'max' => round($values[$count - 1], 1),
        'mean' => round(array_sum($values) / $count, 1),
    ];
}

/**
 * The id a create answered with, or null. Mirrors fhir_write.php's idKey handling:
 * an insert answers OpenEMR's result shape (uuid / euuid / pc_uuid), an update answers
 * the FHIR resource keyed `id`.
 *
 * @param array<string, mixed> $matrixEntry
 */
function stressExtractId(?array $decoded, array $matrixEntry): ?string
{
    if ($decoded === null) {
        return null;
    }
    $key = (string) ($matrixEntry['idKey'] ?? 'uuid');
    foreach ([$key, 'uuid', 'euuid', 'pc_uuid', 'id'] as $candidate) {
        $value = $decoded[$candidate] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
}

/**
 * A short reason for a non-2xx, so failures group by cause instead of by row.
 */
function stressFailureKey(int $code, ?array $decoded, ?string $transport): string
{
    if ($transport !== null) {
        return 'transport: ' . $transport;
    }
    if ($decoded === null) {
        return "HTTP {$code}";
    }
    $validation = $decoded['validationErrors'] ?? null;
    if (is_array($validation) && $validation !== []) {
        $first = reset($validation);
        $label = is_array($first) ? implode('; ', array_map('strval', $first)) : (string) $first;
        return "HTTP {$code}: " . $label;
    }
    foreach (($decoded['issue'] ?? []) as $issue) {
        if (is_array($issue) && is_string($issue['diagnostics'] ?? null)) {
            return "HTTP {$code}: " . $issue['diagnostics'];
        }
    }
    if (is_string($decoded['error'] ?? null)) {
        return "HTTP {$code}: " . $decoded['error'];
    }

    return "HTTP {$code}";
}

/**
 * Reads and validates the posted plan.
 *
 * @param array<string, array<string, mixed>> $writeMatrix
 * @return array{resources:list<string>, iterations:int, concurrency:int, mode:string,
 *               context:array<string,string|null>, tag:string}
 */
function stressReadPlan(array $writeMatrix): array
{
    $raw = file_get_contents('php://input');
    $request = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($request)) {
        http_response_code(400);
        echo json_encode(['error' => 'Request must be a JSON object']);
        exit;
    }

    $resources = array_values(array_filter(
        array_map('strval', (array) ($request['resources'] ?? [])),
        static fn(string $r): bool => isset($writeMatrix[$r])
    ));
    if ($resources === []) {
        http_response_code(400);
        echo json_encode(['error' => 'Pick at least one resource the write matrix knows about']);
        exit;
    }

    $mode = (string) ($request['mode'] ?? 'post');
    if (!in_array($mode, ['post', 'postput'], true)) {
        http_response_code(400);
        echo json_encode(['error' => "mode must be 'post' or 'postput'"]);
        exit;
    }

    $context = [];
    foreach ((array) ($request['context'] ?? []) as $key => $value) {
        // Context is uuids resolved by fhir_write.php?action=context and handed back by the
        // browser. Keys are template placeholders, so anything outside \w cannot match one.
        if (is_string($key) && preg_match('/^\w+$/', $key) === 1) {
            $context[$key] = is_string($value) && $value !== '' ? $value : null;
        }
    }

    return [
        'resources' => $resources,
        'iterations' => max(1, min(STRESS_MAX_ITERATIONS, (int) ($request['iterations'] ?? 10))),
        'concurrency' => max(1, min(STRESS_MAX_CONCURRENCY, (int) ($request['concurrency'] ?? 4))),
        'mode' => $mode,
        'context' => $context,
        'tag' => 'stress-' . substr(bin2hex(random_bytes(3)), 0, 6),
    ];
}

try {
    $plan = stressReadPlan($writeMatrix);
    $resources = $plan['resources'];
    $iterations = $plan['iterations'];
    $total = $iterations * count($resources);

    // Resources whose body references something the context could not resolve would post a
    // literal "Patient/{{patient}}" and fail on every single iteration, which buries the
    // result the run was for. Drop them up front and say so.
    $skipped = [];
    $runnable = [];
    foreach ($resources as $resource) {
        $unresolved = array_values(array_filter(
            (array) ($writeMatrix[$resource]['needs'] ?? []),
            static fn(string $need): bool => ($plan['context'][$need] ?? null) === null
        ));
        // api:fhir is added because OpenEMR omits it from the token response's scope string.
        $missingScope = ScopeAlgebra::predict(array_merge($tokenScopes, ['api:fhir']), 'api:fhir', (string) $resource, 'c') !== 'allow';
        if (($writeMatrix[$resource]['status'] ?? 'supported') !== 'supported') {
            $skipped[$resource] = 'route answers 405 (not implemented)';
        } elseif ($unresolved !== []) {
            $skipped[$resource] = 'unresolved context: ' . implode(', ', $unresolved);
        } elseif ($missingScope) {
            $skipped[$resource] = 'token grants no create on ' . $resource
                . ' (needs ' . $writeMatrix[$resource]['scope'] . ' or an equivalent v2 / system scope)';
        } else {
            $runnable[] = $resource;
        }
    }

    if ($action === 'plan') {
        echo json_encode([
            'tag' => $plan['tag'],
            'resources' => $runnable,
            'skipped' => $skipped,
            'iterations' => $iterations,
            'concurrency' => $plan['concurrency'],
            'mode' => $plan['mode'],
            'writes' => count($runnable) * $iterations * ($plan['mode'] === 'postput' ? 2 : 1),
            'fhirBase' => $fhirBase,
            'limits' => [
                'iterations' => STRESS_MAX_ITERATIONS,
                'concurrency' => STRESS_MAX_CONCURRENCY,
            ],
        ]);
        exit;
    }

    if ($action !== 'run') {
        http_response_code(400);
        echo json_encode(['error' => "Unknown action: {$action}"]);
        exit;
    }

    if ($runnable === []) {
        echo json_encode([
            'tag' => $plan['tag'],
            'skipped' => $skipped,
            'error' => 'Nothing runnable — every selected resource is missing context or scope.',
        ]);
        exit;
    }

    // Interleave rather than running one resource to completion then the next. Contention
    // between *different* write paths on the same shared tables (uuid_registry, forms,
    // the sequences allocator) only shows up when they overlap in time.
    // {{effective}}: one timestamp per iteration, a second apart, from a random point in the
    // last 90 days. Observation vitals coalesce on encounter + effectiveDateTime and refuse a
    // POST for a reading that already exists, so a fixed time would turn every request after
    // the first into a 400. The PUT phase reuses the iteration's timestamp -- a PUT that moves
    // effectiveDateTime is refused too.
    $effectiveBase = time() - random_int(3600, 90 * 86400);
    $effectiveFor = static fn(int $iteration): string => gmdate('Y-m-d\TH:i:s\Z', $effectiveBase + $iteration);
    // {{uniqueDate}}: a different day per iteration, for natural keys that include a date
    // (Coverage refuses a second policy of the same type with the same start date).
    $dateBase = time() - random_int(400, 9000) * 86400;
    $dateFor = static fn(int $iteration): string => gmdate('Y-m-d', $dateBase - $iteration * 86400);

    $createRequests = [];
    for ($i = 0; $i < $iterations; $i++) {
        foreach ($runnable as $resource) {
            $entry = $writeMatrix[$resource];
            $context = $plan['context'];
            // Per-request, not per-run: a repeated NPI collides on a unique index and the
            // resulting 400 would look like a concurrency failure rather than a duplicate.
            $context['unique'] = $plan['tag'] . '-' . $i;
            $context['uniqueDigits'] = str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $context['effective'] = $effectiveFor($i);
            $context['uniqueDate'] = $dateFor($i);
            $payload = json_encode(stressFill($entry['body'], $context), JSON_UNESCAPED_SLASHES);
            $createRequests[] = [
                'method' => 'POST',
                'url' => "{$fhirBase}/{$resource}",
                'payload' => is_string($payload) ? $payload : '{}',
                'meta' => ['resource' => $resource, 'iteration' => $i, 'phase' => 'POST'],
            ];
        }
    }

    $startedAt = microtime(true);
    $createResults = stressBatch($createRequests, $plan['concurrency'], $access);
    $createWall = microtime(true) - $startedAt;

    // Phase two. Updating the rows this run just created keeps the PUT load off whatever
    // else is in the database, and exercises the update path under the same fan-out.
    $updateRequests = [];
    $updateResults = [];
    $updateWall = 0.0;
    if ($plan['mode'] === 'postput') {
        foreach ($createResults as $result) {
            $resource = (string) $result['meta']['resource'];
            $entry = $writeMatrix[$resource];
            if ((int) $result['code'] !== 201 || !in_array('PUT', (array) $entry['verbs'], true)) {
                continue;
            }
            $decoded = json_decode((string) $result['body'], true);
            $id = stressExtractId(is_array($decoded) ? $decoded : null, $entry);
            if ($id === null) {
                continue;
            }
            $context = $plan['context'];
            $context['unique'] = $plan['tag'] . '-' . $result['meta']['iteration'] . '-put';
            $context['uniqueDigits'] = str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $context['effective'] = $effectiveFor((int) $result['meta']['iteration']);
            $context['uniqueDate'] = $dateFor((int) $result['meta']['iteration']);
            $body = stressFill($entry['body'], $context);
            if (is_array($body)) {
                $body['id'] = $id;
            }
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            $updateRequests[] = [
                'method' => 'PUT',
                'url' => "{$fhirBase}/{$resource}/" . rawurlencode($id),
                'payload' => is_string($payload) ? $payload : '{}',
                'meta' => ['resource' => $resource, 'iteration' => $result['meta']['iteration'], 'phase' => 'PUT'],
            ];
        }
        $startedAt = microtime(true);
        $updateResults = stressBatch($updateRequests, $plan['concurrency'], $access);
        $updateWall = microtime(true) - $startedAt;
    }

    // Aggregate. Per-resource so a single bad path is visible instead of averaged away.
    $byResource = [];
    $failureCounts = [];
    $failureSamples = [];
    $idsByResource = [];

    $absorb = static function (array $results, string $phase, int $expectedOk) use (
        &$byResource,
        &$failureCounts,
        &$failureSamples,
        &$idsByResource,
        $writeMatrix
    ): void {
        foreach ($results as $result) {
            $resource = (string) $result['meta']['resource'];
            $bucket = &$byResource[$resource][$phase];
            $bucket['sent'] = ($bucket['sent'] ?? 0) + 1;
            $bucket['ms'][] = (float) $result['ms'];
            $code = (int) $result['code'];
            $bucket['codes'][$code] = ($bucket['codes'][$code] ?? 0) + 1;

            $decoded = json_decode((string) $result['body'], true);
            $decoded = is_array($decoded) ? $decoded : null;

            if ($code === $expectedOk) {
                $bucket['ok'] = ($bucket['ok'] ?? 0) + 1;
                if ($phase === 'POST') {
                    $id = stressExtractId($decoded, $writeMatrix[$resource]);
                    if ($id !== null) {
                        $idsByResource[$resource][] = $id;
                    } else {
                        // A create that answers 201 without naming an id is a real defect:
                        // the caller has no handle on what it just wrote.
                        $bucket['noId'] = ($bucket['noId'] ?? 0) + 1;
                    }
                }
                continue;
            }

            $key = $resource . ' ' . $phase . ' — ' . stressFailureKey($code, $decoded, $result['error']);
            $failureCounts[$key] = ($failureCounts[$key] ?? 0) + 1;
            if (count($failureSamples) < STRESS_MAX_FAILURE_SAMPLES) {
                $failureSamples[] = [
                    'resource' => $resource,
                    'phase' => $phase,
                    'code' => $code,
                    'ms' => $result['ms'],
                    'url' => $result['url'],
                    'body' => $decoded ?? mb_substr((string) $result['body'], 0, 600),
                ];
            }
        }
    };

    $absorb($createResults, 'POST', 201);
    if ($updateResults !== []) {
        $absorb($updateResults, 'PUT', 200);
    }

    // The question the id allocator changes actually raise: did two concurrent creates
    // ever come back with the same id?
    $duplicates = [];
    foreach ($idsByResource as $resource => $ids) {
        $seen = array_count_values($ids);
        $repeated = array_filter($seen, static fn(int $n): bool => $n > 1);
        if ($repeated !== []) {
            $duplicates[$resource] = $repeated;
        }
    }

    $summary = [];
    foreach ($byResource as $resource => $phases) {
        foreach ($phases as $phase => $bucket) {
            $summary[] = [
                'resource' => $resource,
                'phase' => $phase,
                'sent' => $bucket['sent'] ?? 0,
                'ok' => $bucket['ok'] ?? 0,
                'noId' => $bucket['noId'] ?? 0,
                'codes' => $bucket['codes'] ?? [],
                'latency' => stressLatency($bucket['ms'] ?? []),
            ];
        }
    }
    usort($summary, static function (array $a, array $b): int {
        return [$a['resource'], $a['phase']] <=> [$b['resource'], $b['phase']];
    });

    $sentTotal = count($createResults) + count($updateResults);
    $wall = $createWall + $updateWall;

    echo json_encode([
        'tag' => $plan['tag'],
        'mode' => $plan['mode'],
        'concurrency' => $plan['concurrency'],
        'iterations' => $iterations,
        'resources' => $runnable,
        'skipped' => $skipped,
        'sent' => $sentTotal,
        'wallSeconds' => round($wall, 2),
        'throughput' => $wall > 0 ? round($sentTotal / $wall, 1) : 0,
        'summary' => $summary,
        'duplicateIds' => $duplicates,
        'failureCounts' => $failureCounts,
        'failureSamples' => $failureSamples,
        // No DELETE route exists on the FHIR API, so the run reports how to find its rows
        // rather than claiming it can remove them.
        'cleanup' => [
            'tag' => $plan['tag'],
            'note' => 'Rows created by this run carry the tag in their free-text field '
                . '(note / comment / description, depending on the resource).',
            'sql' => [
                "SELECT id, pid, title, comments FROM lists WHERE comments LIKE '%{$plan['tag']}%';",
                "SELECT pc_eid, pc_pid, pc_hometext FROM openemr_postcalendar_events "
                    . "WHERE pc_hometext LIKE '%{$plan['tag']}%';",
                "SELECT id, patient_id, status, description FROM task WHERE description LIKE '%{$plan['tag']}%';",
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'fhir_stress failure', 'detail' => $e->getMessage()]);
}
