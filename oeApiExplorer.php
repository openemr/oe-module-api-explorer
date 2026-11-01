<?php

/**
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2025 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

session_start();

$ignoreAuth = true;
$sessionAllowWrite = true;
require_once("../../interface/globals.php");
require_once 'config.php';
require_once __DIR__ . '/ui_panel.php';
require_once 'oauth_client.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;

/**
 * Perform a SMART-authorized FHIR GET and return the decoded resource.
 *
 * @return array<string, mixed>|null
 */
function smartFhirGet(Client $http, string $url, array &$errors): ?array
{
    try {
        $response = $http->get($url);
        $body = (string)$response->getBody();
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    } catch (RequestException $exception) {
        $status = $exception->getResponse()?->getStatusCode() ?? 'unknown';
        $body = $exception->getResponse() ? (string)$exception->getResponse()->getBody() : '';
        $errors[] = "SMART FHIR request failed ({$status}) for {$url}: " .
            ($body !== '' ? substr($body, 0, 300) : $exception->getMessage());
    } catch (GuzzleException|JsonException $exception) {
        $errors[] = "SMART FHIR request failed for {$url}: " . $exception->getMessage();
    }

    return null;
}

/**
 * FHIR base for SMART calls. After an EHR launch the issuer is authoritative: the app must
 * talk to the server that launched it, not whatever site the Explorer's config points at.
 */
function smartFhirBase(): string
{
    $issuer = $_SESSION['smart_issuer'] ?? null;
    if (is_string($issuer) && $issuer !== '') {
        return rtrim($issuer, '/');
    }

    return rtrim((string)$GLOBALS['ApiConfig']['FHIR_SERVER_URL'], '/');
}

/**
 * Perform a SMART-authorized FHIR POST/PUT and return [status, decoded body, Location header].
 *
 * @param array<string, mixed> $resource
 * @return array{status: int, body: array<string, mixed>|null, location: string|null, error: string|null}
 */
function smartFhirWrite(Client $http, string $method, string $url, array $resource): array
{
    try {
        $response = $http->request($method, $url, [
            'headers' => ['Content-Type' => 'application/fhir+json'],
            'body' => json_encode($resource, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $body = (string)$response->getBody();
        $decoded = $body !== '' ? json_decode($body, true) : null;

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : null,
            'location' => $response->getHeaderLine('Location') ?: null,
            'error' => null,
        ];
    } catch (RequestException $exception) {
        $errorResponse = $exception->getResponse();
        $body = $errorResponse ? (string)$errorResponse->getBody() : '';
        $decoded = $body !== '' ? json_decode($body, true) : null;
        $diagnostics = is_array($decoded)
            ? ($decoded['issue'][0]['diagnostics'] ?? $decoded['message'] ?? null)
            : null;

        return [
            'status' => $errorResponse?->getStatusCode() ?? 0,
            'body' => is_array($decoded) ? $decoded : null,
            'location' => null,
            'error' => is_string($diagnostics) && $diagnostics !== ''
                ? $diagnostics
                : ($body !== '' ? substr($body, 0, 300) : $exception->getMessage()),
        ];
    } catch (GuzzleException|JsonException $exception) {
        return ['status' => 0, 'body' => null, 'location' => null, 'error' => $exception->getMessage()];
    }
}

function smartJsonExit(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<string, mixed>|null $bundle
 * @return array<int, array<string, mixed>>
 */
function smartBundleResources(?array $bundle): array
{
    $resources = [];
    foreach (($bundle['entry'] ?? []) as $entry) {
        if (is_array($entry) && isset($entry['resource']) && is_array($entry['resource'])) {
            $resources[] = $entry['resource'];
        }
    }

    return $resources;
}

/**
 * @param array<string, mixed> $patient
 */
function smartPatientDisplayName(array $patient): string
{
    foreach (($patient['name'] ?? []) as $name) {
        if (!is_array($name)) {
            continue;
        }
        $given = is_array($name['given'] ?? null) ? implode(' ', $name['given']) : '';
        $family = is_string($name['family'] ?? null) ? $name['family'] : '';
        $display = trim($given . ' ' . $family);
        if ($display !== '') {
            return $display;
        }
    }

    return 'SMART Launch Patient';
}

/**
 * @param array<string, mixed> $questionnaire
 * @return array<int, array<string, mixed>>
 */

/**
 * Return the resource id from either a relative or absolute FHIR reference.
 */
function smartReferenceId(mixed $reference, string $resourceType): ?string
{
    if (!is_string($reference) || $reference === '') {
        return null;
    }

    $pattern = '~(?:^|/)' . preg_quote($resourceType, '~') . '/([A-Za-z0-9.-]+)$~';
    return preg_match($pattern, $reference, $matches) === 1 ? $matches[1] : null;
}

function smartFlattenQuestionnaireItems(array $questionnaire): array
{
    $flattened = [];
    $walk = static function (array $items, int $depth = 0) use (&$flattened, &$walk): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item['_depth'] = $depth;
            $flattened[] = $item;
            if (isset($item['item']) && is_array($item['item'])) {
                $walk($item['item'], $depth + 1);
            }
        }
    };
    $walk(is_array($questionnaire['item'] ?? null) ? $questionnaire['item'] : []);

    return $flattened;
}

// --- Clean session if requested ---
if (isset($_REQUEST['cleanSession'])) {
    session_unset();
    if (isset($_COOKIE['OpenEMR'])) {
        setcookie('OpenEMR', '', time() - 3600, '/');
        unset($_COOKIE['OpenEMR']);
    }
    header("Location: oeApiExplorer.php");
    exit;
}

// --- Ensure client keys directory exists ---
ensureClientKeysDir();

$api_site = $_GET['api_site'] ?? $_SESSION['selectedSite'] ?? $explorerDefaultSite;
if (!is_string($api_site) || !isset($apiSites[$api_site])) {
    $api_site = $_SESSION['selectedSite'] ?? $explorerDefaultSite;
}

// --- Handle OAuth callback for Auth Code ---
if (!empty($_GET['code']) && !empty($_GET['state'])) {
    $clientType = $_GET['client'] ?? $_SESSION['client_type'] ?? null;
    $apiType = $_GET['api'] ?? $_SESSION['api_type'] ?? null;
    $resourceType = $_GET['resource'] ?? $_SESSION['resource_type'] ?? null;
    $grantType = $_GET['grant'] ?? $_SESSION['grant_type'] ?? 'authorization_code';

    $clientFile = __DIR__ . "/clients_keys/client_{$api_site}_{$clientType}.json";
    if ($clientType && file_exists($clientFile)) {
        $clientData = json_decode(file_get_contents($clientFile), true);
        $tokenResp = getAccessTokenViaAuthCode($clientType, $clientData);
        if (!empty($tokenResp['access_token'])) {
            // Stored in session by helper
        }
    }
    // Redirect back to remove code from URL
    $redirectParams = ['client' => $clientType, 'api' => $apiType ?: 'fhir', 'grant' => 'authorization_code'];
    if ($resourceType) {
        $redirectParams['resource'] = $resourceType;
    }
    if ($clientType === 'smart') {
        $redirectParams['smart_complete'] = '1';
    }
    header('Location: oeApiExplorer.php?' . http_build_query($redirectParams));
    exit;
}

// --- Start SMART EHR authorization after receiving iss + launch ---
if (isset($_GET['smart_authorize'])) {
    $api_site = $_SESSION['selectedSite'] ?? $api_site;
    $clientFile = __DIR__ . "/clients_keys/client_{$api_site}_smart.json";
    if (!file_exists($clientFile)) {
        die(text("Missing SMART client registration: {$clientFile}"));
    }
    $clientData = json_decode((string)file_get_contents($clientFile), true, 512, JSON_THROW_ON_ERROR);
    getAccessTokenViaAuthCode('smart', $clientData);
}

// --- Read or persist user selections ---
$clientType = $_GET['client'] ?? $_SESSION['client_type'] ?? null;
$apiType = $_GET['api'] ?? $_SESSION['api_type'] ?? null;
$resourceType = $_GET['resource'] ?? $_SESSION['resource_type'] ?? null;
$grantType = $_GET['grant'] ?? $_SESSION['grant_type'] ?? 'authorization_code';

// The SMART session is authoritative after an EHR launch. OAuth redirects may
// return without the Explorer's client/api query parameters, so restore the
// correct UI and render mode from the retained SMART launch context.
$isSmartLaunch = isset($_SESSION['smart_issuer'])
    && is_string($_SESSION['smart_issuer'])
    && $_SESSION['smart_issuer'] !== ''
    && isset($_SESSION['token_response'])
    && is_array($_SESSION['token_response'])
    && isset($_SESSION['token_response']['patient'])
    && is_string($_SESSION['token_response']['patient'])
    && $_SESSION['token_response']['patient'] !== '';

if ($isSmartLaunch) {
    $clientType = 'smart';
    $apiType = 'fhir';
    $grantType = 'authorization_code';
}

$_SESSION['client_type'] = $clientType;
$_SESSION['api_type'] = $apiType;
$_SESSION['resource_type'] = $resourceType;
$_SESSION['grant_type'] = $grantType;
// --- Optional user-supplied query string to append to the endpoint URL ---
$queryStringRaw = $_GET['query'] ?? $_SESSION['last_query'] ?? '';
// Normalize for storage (don’t trim leading '?'—we’ll handle it later)
$_SESSION['last_query'] = $queryStringRaw;

// --- Flow description ---
$flowNote = match ($grantType) {
    'authorization_code' => match ($clientType) {
        'smart' => 'SMART EHR Launch (PKCE)',
        'public' => 'PKCE (Public)',
        default => 'Authorization Code',
    },
    'client_credentials' => 'Client Credentials',
    'refresh_token' => 'Refresh Token',
    default => 'Unknown Flow',
};

$errors = [];
$response = null;
if (isset($_SESSION['access_token'])) {
    if (isTokenExpired()) {
        $clientFile = __DIR__ . "/clients_keys/client_{$api_site}_{$clientType}.json";
        $clientData = json_decode(file_get_contents($clientFile), true);
        $accessToken = refreshAccessToken($clientData);
    } else {
        $accessToken = $_SESSION['access_token'];
    }
}
$accessToken = $_SESSION['access_token'] ?? null;

// Per-session token guarding the Explorer's own save endpoint below.
if (empty($_SESSION['smart_qr_csrf']) || !is_string($_SESSION['smart_qr_csrf'])) {
    $_SESSION['smart_qr_csrf'] = bin2hex(random_bytes(32));
}

// --- Save a QuestionnaireResponse for the SMART launch through the FHIR API ---
// The browser posts the rendered response here (same origin as the Explorer); the Explorer
// then writes it to the launching EHR with the SMART bearer token. The EHR's internal
// session pages are never involved, so this works when the Explorer is hosted anywhere.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_GET['smart_action'] ?? null) === 'save_questionnaire_response'
) {
    $csrfHeader = $_SERVER['HTTP_X_EXPLORER_CSRF'] ?? '';
    if (!is_string($csrfHeader) || !hash_equals((string)$_SESSION['smart_qr_csrf'], $csrfHeader)) {
        smartJsonExit(403, ['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    }
    $launchPatient = $_SESSION['token_response']['patient'] ?? null;
    $launchQuestionnaireId = $_SESSION['smart_launch_questionnaire_id'] ?? null;
    if (!is_string($accessToken) || $accessToken === '' || !is_string($launchPatient) || $launchPatient === '') {
        smartJsonExit(401, ['success' => false, 'message' => 'No active SMART launch. Relaunch from the EHR.']);
    }
    if (!is_string($launchQuestionnaireId) || $launchQuestionnaireId === '') {
        smartJsonExit(400, ['success' => false, 'message' => 'The launch did not carry a Questionnaire context.']);
    }

    try {
        $submitted = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        smartJsonExit(400, ['success' => false, 'message' => 'Request body is not valid JSON.']);
    }
    if (!is_array($submitted) || ($submitted['resourceType'] ?? null) !== 'QuestionnaireResponse') {
        smartJsonExit(400, ['success' => false, 'message' => 'Body must be a QuestionnaireResponse.']);
    }

    // Continue/edit is only allowed for the response this page loaded for the launch.
    $allowedResponseIds = $_SESSION['smart_editable_response_ids'] ?? [];
    $responseId = is_string($submitted['id'] ?? null) && $submitted['id'] !== '' ? $submitted['id'] : null;
    if ($responseId !== null && !in_array($responseId, is_array($allowedResponseIds) ? $allowedResponseIds : [], true)) {
        smartJsonExit(403, ['success' => false, 'message' => 'That QuestionnaireResponse is not part of this launch.']);
    }

    // Stamp the fields the launch context owns; the client does not get to choose them.
    $submitted['subject'] = ['reference' => 'Patient/' . $launchPatient];
    if ($responseId === null) {
        unset($submitted['id'], $submitted['meta']);
    }
    // Relative reference: OpenEMR resolves Questionnaire/<uuid> on its own server. On an edit,
    // keep the reference the stored response already carries (it may pin a version).
    if ($responseId === null || !is_string($submitted['questionnaire'] ?? null) || $submitted['questionnaire'] === '') {
        $submitted['questionnaire'] = 'Questionnaire/' . $launchQuestionnaireId;
    }
    $requestedStatus = is_string($submitted['status'] ?? null) ? $submitted['status'] : 'in-progress';
    $submitted['status'] = in_array($requestedStatus, ['in-progress', 'completed', 'amended'], true)
        ? $requestedStatus
        : 'in-progress';

    $fhirBase = smartFhirBase();
    $writeHttp = new Client([
        'verify' => false,
        'headers' => [
            'Authorization' => "Bearer {$accessToken}",
            'Accept' => 'application/fhir+json',
        ],
    ]);
    $result = $responseId === null
        ? smartFhirWrite($writeHttp, 'POST', "{$fhirBase}/QuestionnaireResponse", $submitted)
        : smartFhirWrite($writeHttp, 'PUT', "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($responseId), $submitted);

    if ($result['status'] < 200 || $result['status'] >= 300) {
        smartJsonExit($result['status'] >= 400 ? $result['status'] : 502, [
            'success' => false,
            'message' => 'FHIR ' . ($responseId === null ? 'POST' : 'PUT') . ' failed ('
                . $result['status'] . '): ' . ($result['error'] ?? 'unknown error'),
        ]);
    }

    $savedId = $result['body']['id'] ?? null;
    if (!is_string($savedId) && is_string($result['location'])) {
        $savedId = smartReferenceId(preg_replace('~/_history/.*$~', '', $result['location']), 'QuestionnaireResponse');
    }
    $savedId = is_string($savedId) ? $savedId : $responseId;
    if (is_string($savedId)) {
        $_SESSION['smart_editable_response_ids'] = array_values(array_unique(array_merge(
            is_array($allowedResponseIds) ? $allowedResponseIds : [],
            [$savedId]
        )));
    }

    smartJsonExit(200, [
        'success' => true,
        'response_id' => $savedId,
        'status' => $result['status'],
        'resource' => $result['body'],
    ]);
}

// --- Build the SMART SDOH demonstration workspace from launch context ---
$smartWorkspace = null;
$smartPatient = null;
$smartQuestionnaires = [];
$smartQuestionnaireResponses = [];
$smartPatientId = null;
$smartLaunchQuestionnaireId = null;
$smartLaunchQuestionnaireResponseId = null;
$smartLaunchSource = 'patient-dashboard';
$smartSelectedQuestionnaireResponse = null;
$smartLaunchQuestionnaire = null;

if (
    $isSmartLaunch
    && is_string($accessToken)
    && $accessToken !== ''
    && isset($_SESSION['token_response'])
    && is_array($_SESSION['token_response'])
) {
    $launchPatient = $_SESSION['token_response']['patient'] ?? null;
    $launchFhirContext = $_SESSION['token_response']['fhirContext'] ?? [];
    if (is_string($launchFhirContext)) {
        $decodedFhirContext = json_decode($launchFhirContext, true);
        $launchFhirContext = is_array($decodedFhirContext) ? $decodedFhirContext : [];
    }
    if (is_array($launchFhirContext)) {
        foreach ($launchFhirContext as $contextEntry) {
            $reference = is_array($contextEntry) ? ($contextEntry['reference'] ?? null) : $contextEntry;
            if (!is_string($reference)) {
                continue;
            }
            $questionnaireId = smartReferenceId($reference, 'Questionnaire');
            if ($smartLaunchQuestionnaireId === null && $questionnaireId !== null) {
                $smartLaunchQuestionnaireId = $questionnaireId;
                $smartLaunchSource = 'assessment';
                continue;
            }

            $questionnaireResponseId = smartReferenceId($reference, 'QuestionnaireResponse');
            if ($smartLaunchQuestionnaireResponseId === null && $questionnaireResponseId !== null) {
                $smartLaunchQuestionnaireResponseId = $questionnaireResponseId;
            }
        }
    }

    if (is_string($launchPatient) && $launchPatient !== '') {
        $smartPatientId = $launchPatient;
        $smartHttp = new Client([
            'verify' => false,
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Accept' => 'application/fhir+json',
            ],
        ]);
        $fhirBase = smartFhirBase();

        $smartPatient = smartFhirGet(
            $smartHttp,
            "{$fhirBase}/Patient/" . rawurlencode($smartPatientId),
            $errors
        );

        $questionnaireQuery = $smartLaunchQuestionnaireId !== null
            ? '_id=' . rawurlencode($smartLaunchQuestionnaireId)
            : '_count=50';
        $questionnaireBundle = smartFhirGet(
            $smartHttp,
            "{$fhirBase}/Questionnaire?{$questionnaireQuery}",
            $errors
        );
        $smartQuestionnaires = smartBundleResources($questionnaireBundle);

        $responseBundle = smartFhirGet(
            $smartHttp,
            "{$fhirBase}/QuestionnaireResponse?patient=" . rawurlencode($smartPatientId) . "&_sort=-authored&_count=50",
            $errors
        );

        if ($responseBundle === null) {
            $responseBundle = smartFhirGet(
                $smartHttp,
                "{$fhirBase}/QuestionnaireResponse?subject=" .
                rawurlencode("Patient/{$smartPatientId}") .
                "&_sort=-authored&_count=50",
                $errors
            );
        }
        $smartQuestionnaireResponses = smartBundleResources($responseBundle);
        if ($smartLaunchSource === 'assessment' && $smartLaunchQuestionnaireId !== null) {
            $matchingResponses = [];
            foreach ($smartQuestionnaireResponses as $questionnaireResponse) {
                $responseId = is_string($questionnaireResponse['id'] ?? null)
                    ? $questionnaireResponse['id']
                    : null;
                $questionnaireId = smartReferenceId(
                    $questionnaireResponse['questionnaire'] ?? null,
                    'Questionnaire'
                );

                if (
                    $smartLaunchQuestionnaireResponseId !== null
                    && $responseId === $smartLaunchQuestionnaireResponseId
                ) {
                    $matchingResponses = [$questionnaireResponse];
                    break;
                }

                if ($smartLaunchQuestionnaireResponseId === null && $questionnaireId === $smartLaunchQuestionnaireId) {
                    $matchingResponses[] = $questionnaireResponse;
                }
            }
            $smartQuestionnaireResponses = $matchingResponses;

            if ($smartQuestionnaireResponses !== []) {
                if ($smartLaunchQuestionnaireResponseId !== null) {
                    $smartSelectedQuestionnaireResponse = $smartQuestionnaireResponses[0];
                } else {
                    foreach ($smartQuestionnaireResponses as $questionnaireResponse) {
                        if (($questionnaireResponse['status'] ?? null) === 'in-progress') {
                            $smartSelectedQuestionnaireResponse = $questionnaireResponse;
                            break;
                        }
                    }
                    $smartSelectedQuestionnaireResponse ??= $smartQuestionnaireResponses[0];
                }
            }

            foreach ($smartQuestionnaires as $questionnaire) {
                if (($questionnaire['id'] ?? null) === $smartLaunchQuestionnaireId) {
                    $smartLaunchQuestionnaire = $questionnaire;
                    break;
                }
            }
            if ($smartLaunchQuestionnaire === null) {
                // Some servers ignore _id on search; fall back to a direct read.
                $smartLaunchQuestionnaire = smartFhirGet(
                    $smartHttp,
                    "{$fhirBase}/Questionnaire/" . rawurlencode($smartLaunchQuestionnaireId),
                    $errors
                );
                if (is_array($smartLaunchQuestionnaire)) {
                    $smartQuestionnaires = [$smartLaunchQuestionnaire];
                }
            }
            if (
                $smartLaunchQuestionnaireResponseId !== null
                && $smartSelectedQuestionnaireResponse === null
            ) {
                $smartSelectedQuestionnaireResponse = smartFhirGet(
                    $smartHttp,
                    "{$fhirBase}/QuestionnaireResponse/" . rawurlencode($smartLaunchQuestionnaireResponseId),
                    $errors
                );
            }

            // Remember what the save endpoint may write for this launch.
            $_SESSION['smart_launch_questionnaire_id'] = $smartLaunchQuestionnaireId;
            $_SESSION['smart_editable_response_ids'] = array_values(array_filter(array_map(
                static fn($questionnaireResponse) => is_array($questionnaireResponse) ? ($questionnaireResponse['id'] ?? null) : null,
                array_merge($smartQuestionnaireResponses, [$smartSelectedQuestionnaireResponse])
            ), 'is_string'));
        }

        $smartWorkspace = [
            'patient_id' => $smartPatientId,
            'questionnaire_count' => count($smartQuestionnaires),
            'response_count' => count($smartQuestionnaireResponses),
        ];
    }
}

// --- On form submit, obtain token & fetch ---
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $clientType
    && $apiType
    && $resourceType
    && (!$isSmartLaunch || isset($_GET['fetch_action']))
    // Returning from a Scope Lab consent screen is not a request for this form's resource --
    // without this the page would start the Explorer's own auth-code flow and bounce away.
    && !isset($_GET['scope_lab_run'])
) {
    $clientData = [];
    $clientFile = __DIR__ . "/clients_keys/client_{$api_site}_{$clientType}.json";
    if (!file_exists($clientFile)) {
        $errors[] = "Missing credentials: {$clientFile}";
    } else {
        $clientData = json_decode(file_get_contents($clientFile), true);
    }

    // 1) Obtain token
    try {
        switch ($grantType) {
            case 'client_credentials':
                $tokenResp = getClientCredentialsToken($clientData, $api_site);
                break;
            case 'authorization_code':
                $tokenResp = getAccessTokenViaAuthCode($clientType, $clientData);
                break;
            case 'refresh_token':
                if (!empty($_SESSION['refresh_token'] ?? '')) {
                    $tokenResp = refreshAccessToken($clientData, $_SESSION['refresh_token']);
                } else {
                    throw new Exception("No refresh token in session; run Auth‑Code first.");
                }
                break;
            default:
                throw new Exception("Unsupported grant: {$grantType}");
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        $tokenResp = [];
    }

    // 2) Extract access_token or show error
    if (!empty($tokenResp['access_token'])) {
        $accessToken = $tokenResp['access_token'];
        $_SESSION['access_token'] = $accessToken;
        $_SESSION['access_token_scopes'] = $tokenResp['scope'] ?? '';
    } else {
        $errors[] = "Token error: " . text(
                $tokenResp['error_description']
                ?? $tokenResp['error']
                ?? 'Unknown'
            );
    }

    // 2) Build endpoint URL
    if ($accessToken && empty($errors)) {
        $baseUrl = ($apiType === 'fhir')
            ? $GLOBALS['ApiConfig']['FHIR_SERVER_URL']
            : $GLOBALS['ApiConfig']['API_SERVER_URL'];

        // Always start from canonical resource URL
        $resourceUrl = "{$baseUrl}/{$resourceType}";

        // If the user supplied a query string, append it; otherwise the canonical collection
        // URL built above is the request.
        $queryUser = trim($queryStringRaw ?? '');

        if ($queryUser !== '') {
            // Accept either starting with '?' or '&' or plain 'key=val&...'
            if ($queryUser[0] === '?') {
                $resourceUrl .= $queryUser;
            } elseif ($queryUser[0] === '&') {
                $resourceUrl .= '?' . ltrim($queryUser, '&');
            } elseif ($queryUser[0] === '/') {
                $resourceUrl .= $queryUser;
            } else {
                $resourceUrl .= '?' . $queryUser;
            }
        }

        try {
            $http = new Client([
                'verify' => false,
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/fhir+json',
                    'Prefer' => 'respond-async',
                ],
            ]);

            $res = $http->get($resourceUrl);
            $statusCode = $res->getStatusCode();

            if ($statusCode === 202) {
                // FHIR Bulk Export: 202 Accepted with Content-Location header
                $contentLocation = $res->getHeader('Content-Location');
                $statusUrl = $contentLocation ? $contentLocation[0] : null;

                if ($statusUrl) {
                    $response = [
                        'status' => 'accepted',
                        'message' => 'Bulk export request accepted',
                        'status_url' => $statusUrl,
                        'poll_after' => $res->getHeader('Retry-After')[0] ?? '1'
                    ];
                } else {
                    $errors[] = "Bulk export accepted but no Content-Location header found";
                }

            } elseif ($statusCode >= 200 && $statusCode < 300) {
                // Standard successful response
                $bodyContent = (string)$res->getBody();

                if (empty($bodyContent)) {
                    $response = ['status' => 'success', 'data' => null];
                } else {
                    $response = json_decode($bodyContent, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $errors[] = "JSON decode error: " . json_last_error_msg() .
                            " (Status: {$statusCode}) | Body: " . substr($bodyContent, 0, 200);
                    }
                }

            } else {
                $errors[] = "HTTP error {$statusCode}: " . $res->getReasonPhrase() . " for URL: {$resourceUrl}";
            }

        } catch (ConnectException $ex) {
            $errors[] = "Connection failed to {$resourceUrl}: " . $ex->getMessage();
        } catch (RequestException $ex) {
            $statusCode = $ex->getResponse() ? $ex->getResponse()->getStatusCode() : 'unknown';
            $responseBody = $ex->getResponse() ? (string)$ex->getResponse()->getBody() : 'no response body';

            $errors[] = "API request failed (Status: {$statusCode}): " . $ex->getMessage() .
                " | URL: {$resourceUrl} | Response: " . substr($responseBody, 0, 200);
        } catch (Exception $ex) {
            $errors[] = "Unexpected error during API call to {$resourceUrl}: " . $ex->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="eng">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenEMR API Explorer</title>
    <?php Header::setupHeader(); ?>
    <script>
    </script>
    <script>
        /**
         * Remembers which panels are open.
         *
         * Every Fetch is a full page load, so without this the whole page would spring back to
         * its default shape on each request and collapsing would cost more than it saved.
         * Storage is per-browser and best-effort: a private window or blocked site data just
         * means the server-rendered defaults apply, which is why every access is guarded.
         */
        window.explorerPanels = (function () {
            const KEY = 'oeApiExplorer.panels'

            function read () {
                try {
                    return JSON.parse(window.localStorage.getItem(KEY) || '{}') || {}
                } catch (e) {
                    return {}
                }
            }

            function write (state) {
                try {
                    window.localStorage.setItem(KEY, JSON.stringify(state))
                } catch (e) {
                    /* storage unavailable — the panels still work, they just forget */
                }
            }

            function init () {
                const state = read()
                document.querySelectorAll('.explorer-panel-toggle').forEach(function (toggle) {
                    const id = toggle.dataset.panelId
                    const body = document.querySelector(toggle.getAttribute('data-target'))
                    if (!id || !body) {
                        return
                    }
                    if (Object.prototype.hasOwnProperty.call(state, id)) {
                        const open = state[id] === true
                        body.classList.toggle('show', open)
                        toggle.setAttribute('aria-expanded', open ? 'true' : 'false')
                    }
                    // Bootstrap fires these on the collapse element once the transition settles.
                    body.addEventListener('shown.bs.collapse', function () {
                        const next = read()
                        next[id] = true
                        write(next)
                        toggle.setAttribute('aria-expanded', 'true')
                    })
                    body.addEventListener('hidden.bs.collapse', function () {
                        const next = read()
                        next[id] = false
                        write(next)
                        toggle.setAttribute('aria-expanded', 'false')
                    })
                })
            }

            return { init: init }
        })()

        document.addEventListener('DOMContentLoaded', window.explorerPanels.init)

        /*
         * The session bar is sticky, so it needs an opaque background or the page scrolls
         * through it. Which colour that is depends on the OpenEMR theme the user has active --
         * this page is styled with neutral translucent overlays precisely so it works on both
         * light and dark themes, and there is no stylesheet value to hardcode here. So take it
         * from whatever ancestor actually paints one.
         */
        document.addEventListener('DOMContentLoaded', function () {
            const bar = document.querySelector('.explorer-sessionbar')
            if (!bar) {
                return
            }
            for (let node = bar.parentElement; node; node = node.parentElement) {
                const bg = window.getComputedStyle(node).backgroundColor
                // Skip fully transparent ancestors; rgba(0, 0, 0, 0) and 'transparent' paint nothing.
                if (bg && bg !== 'transparent' && !/,\s*0\s*\)$/.test(bg)) {
                    bar.style.backgroundColor = bg
                    return
                }
            }
        })
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const siteSelect = document.querySelector('[name="api_site"]')
            const apiSelect = document.querySelector('[name="api"]')
            const clientSelect = document.querySelector('[name="client"]')
            const resourceSelect = document.querySelector('[name="resource"]')
            const grantSelect = document.querySelector('[name="grant"]')
            const form = document.querySelector('form')
            const clearBtn = document.getElementById('clearQueryBtn')
            const queryInput = document.getElementById('query')

            if (clearBtn && queryInput) {
                clearBtn.addEventListener('click', function () {
                    queryInput.value = ''
                    // Optional: auto-submit the form after clearing
                    // queryInput.form.submit();
                })
            }

            if (!apiSelect || !clientSelect || !resourceSelect || !form || !grantSelect) return

            // Initialize selects
            function syncGrantClient () {
                const clientVal = clientSelect.value
                const grantVal = grantSelect.value

                if (clientVal === 'JWT') {
                    // JWT clients must use client_credentials
                    grantSelect.value = 'client_credentials'
                } else if (clientVal === 'smart') {
                    grantSelect.value = 'authorization_code'
                    apiSelect.value = 'fhir'
                } else if (clientVal === 'public') {
                    // Public clients must use authorization_code
                    grantSelect.value = 'authorization_code'
                } else if (clientVal === 'confidential') {
                    // Confidential clients default to authorization_code, unless refresh_token explicitly selected
                    if (grantVal !== 'authorization_code' && grantVal !== 'refresh_token') {
                        grantSelect.value = 'authorization_code'
                    }
                }

                // Now sync backwards: if user changed grant type
                const newGrant = grantSelect.value
                if (newGrant === 'client_credentials') {
                    if (clientVal !== 'confidential' && clientVal !== 'JWT') {
                        clientSelect.value = 'confidential'
                    }
                } else if (newGrant === 'authorization_code') {
                    if (clientVal === 'JWT') {
                        clientSelect.value = 'confidential' // fallback
                    }
                } else if (newGrant === 'refresh_token') {
                    if (clientVal === 'public' || clientVal === 'JWT') {
                        clientSelect.value = 'confidential'
                    }
                }
            }

            function updateResources () {
                const api = apiSelect.value
                const client = clientSelect.value

                syncGrantClient()

                fetch('scope_resources.php?client_type=' + encodeURIComponent(client) +
                    '&grant_type=' + encodeURIComponent(grantSelect.value) +
                    '&api_site=' + encodeURIComponent(siteSelect.value)).then(res => res.json()).then(data => {
                    let list = data[api] || []
                    if (api === 'fhir' && client === 'public') {
                        list = list.concat(data.public || [])
                    }
                    // De-dupe and sort
                    list = [...new Set(list)].sort()
                    resourceSelect.innerHTML = ''
                    list.forEach(res => {
                        const opt = document.createElement('option')
                        if (res == '$export') {
                            opt.textContent = 'Export */$export'
                        } else {
                            opt.textContent = res
                        }
                        opt.value = res
                        if (res === "<?= $resourceType ?>") opt.selected = true
                        resourceSelect.appendChild(opt)
                    })
                })
                //resourceSelect.addEventListener('change', () => form.submit())
            }

            // Init and bind
            updateResources()
            apiSelect.addEventListener('change', updateResources)
            clientSelect.addEventListener('change', updateResources)
            grantSelect.addEventListener('change', updateResources)
            siteSelect.addEventListener('change', updateResources)
        })
    </script>
    <style>
        .smart-workspace-hero {
            background: linear-gradient(135deg, rgba(13, 110, 253, .12), rgba(25, 135, 84, .12));
            border: 1px solid rgba(13, 110, 253, .18);
        }

        .smart-metric {
            min-height: 100%;
            border-left: .3rem solid #0d6efd;
        }

        .smart-questionnaire-item {
            border-left: .2rem solid rgba(13, 110, 253, .25);
            padding-left: .75rem;
            margin-bottom: .5rem;
        }

        .smart-diagnostics pre {
            max-height: 22rem;
            overflow: auto;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .smart-token {
            max-height: 8rem;
            overflow: auto;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        #smart-assessment-form {
            max-height: 72vh;
            overflow: auto;
        }

        /* ---- theme-agnostic surface tokens ---------------------------------
           OpenEMR ships both light and dark themes and this page inherits whichever
           is active, so nothing here hardcodes a polarity. Neutral grey at low alpha
           reads as a light tint over a white body and a dark tint over a black one,
           and every foreground is inherited rather than set. */
        .explorer-shell {
            --xp-surface: rgba(128, 128, 128, .07);
            --xp-surface-raised: rgba(128, 128, 128, .14);
            --xp-surface-hover: rgba(128, 128, 128, .22);
            --xp-border: rgba(128, 128, 128, .35);
            --xp-muted: rgba(128, 128, 128, 1);

            /* Wider than Bootstrap's .container: the payload here is pretty-printed
               JSON, and 1140px wraps it into noise. Capped so lines stay readable. */
            max-width: 1600px;
            padding-bottom: 3rem;
        }

        .explorer-titlebar {
            padding: .75rem 0 .5rem;
            gap: .5rem;
        }

        .explorer-chip {
            display: inline-block;
            padding: .2rem .6rem;
            border: 1px solid var(--xp-border);
            border-radius: 10rem;
            background: var(--xp-surface-raised);
            color: inherit;
            font-size: .75rem;
            line-height: 1.4;
        }

        .explorer-chip-on {
            border-color: rgba(40, 167, 69, .7);
        }

        /* Bootstrap assumes a light page in a few places that show up here as white-on-white or
           dark-on-dark depending on the theme: .card paints #fff, reboot gives <pre> a fixed
           near-black, and .btn-outline-secondary is a mid-grey that washes out either way. */
        .explorer-shell .card {
            background-color: var(--xp-surface);
            border-color: var(--xp-border);
        }

        .explorer-shell pre {
            color: inherit;
        }

        .explorer-shell .btn-outline-secondary {
            color: inherit;
            border-color: var(--xp-border);
        }

        .explorer-shell .btn-outline-secondary:hover,
        .explorer-shell .btn-outline-secondary:focus {
            color: inherit;
            background-color: var(--xp-surface-hover);
            border-color: var(--xp-border);
        }

        /* Site + session actions stay put while you work down the page. */
        .explorer-sessionbar {
            position: sticky;
            top: 0;
            z-index: 1020;
            gap: .5rem;
            padding: .5rem .75rem;
            margin-bottom: 1rem;
            border: 1px solid var(--xp-border);
            border-radius: .25rem;
            /* Needs to be opaque or the page scrolls through it, but which colour that is
               depends on the active theme. Filled in at load from the nearest ancestor that
               actually paints one -- see the resolver in the panel script below. */
            background-color: inherit;
            box-shadow: 0 1px 0 var(--xp-border);
        }

        /* ---- panels ------------------------------------------------------- */
        .explorer-shell .explorer-panel {
            background-color: transparent;
            border-color: var(--xp-border);
        }

        .explorer-panel > .card-header {
            background-color: var(--xp-surface);
            border-bottom: 1px solid var(--xp-border);
        }

        .explorer-panel > .collapse.show,
        .explorer-panel > .collapsing {
            background-color: var(--xp-surface);
        }

        .explorer-panel-toggle {
            padding: .55rem .9rem;
            color: inherit;
            white-space: normal;
        }

        .explorer-panel-toggle:hover,
        .explorer-panel-toggle:focus {
            background-color: var(--xp-surface-hover);
            color: inherit;
            text-decoration: none;
        }

        .explorer-panel-title {
            font-weight: 600;
        }

        .explorer-panel-subtitle {
            color: var(--xp-muted);
            font-size: .8125rem;
            margin-left: .5rem;
        }

        .explorer-panel-chevron {
            display: inline-block;
            width: 1rem;
            color: var(--xp-muted);
            transition: transform .15s ease-in-out;
        }

        .explorer-panel-toggle[aria-expanded="true"] .explorer-panel-chevron {
            transform: rotate(90deg);
        }

        /* ---- output ------------------------------------------------------- */
        /* Bounded so a large bundle cannot turn the page into an endless scroll.
           The JS log panes set display:block inline when they have something to show,
           which overrides the none here. */
        .explorer-response,
        .explorer-log {
            color: inherit;
            background-color: var(--xp-surface-raised);
            border: 0;
            border-radius: .25rem;
            font-size: .8125rem;
            overflow: auto;
        }

        .explorer-response {
            max-height: 60vh;
            padding: .75rem 1rem;
        }

        .explorer-log {
            display: none;
            max-height: 200vh;
            padding: .5rem .75rem;
        }

        /* Bootstrap's .bg-light on nested panes is a light-theme assumption; neutralise it
           so the SMART cards and metric tiles follow the active theme too. */
        .explorer-shell .bg-light {
            background-color: var(--xp-surface-raised) !important;
        }

        /* The tiles keep their blue left accent; only the card fill needs neutralising, and the
           hero is left alone entirely -- its gradient is already an alpha wash over the theme. */
        .explorer-shell .smart-metric {
            background-color: var(--xp-surface);
        }

        @media (max-width: 767.98px) {
            .explorer-sessionbar {
                position: static;
            }
        }

    </style>
</head>
<body>
    <div class="container-fluid explorer-shell">
        <header class="explorer-titlebar d-flex flex-wrap align-items-center">
            <h2 class="h4 mb-0 mr-3">OpenEMR API Explorer</h2>
            <span class="explorer-chip mr-2" title="Authorization flow in use">
                <?= text($flowNote) ?>
            </span>
            <?php $hasRefresh = (bool) ($_SESSION['refresh_token'] ?? ''); ?>
            <span class="explorer-chip <?= $hasRefresh ? 'explorer-chip-on' : '' ?>" title="Refresh token">
                refresh: <?= $hasRefresh ? 'available' : 'none' ?>
            </span>
        </header>

        <!-- Session bar: site selection and session-wide actions. Deliberately not collapsible --
             it is the context every panel below operates in, not a feature group of its own. -->
        <form method="get" class="explorer-sessionbar form-inline">
            <label class="mr-1 mb-0" for="api_site">Site</label>
            <select class="form-control form-control-sm mr-2" name="api_site" id="api_site">
                <?php foreach ($apiSites as $label => $url) : ?>
                    <option value="<?= attr($label) ?>" <?= ($label === $_SESSION['selectedSite']) ? 'selected' : '' ?>>
                        <?= text($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="fetch_action" value="true" class="btn btn-sm btn-outline-secondary mr-2">
                Switch
            </button>
            <div class="ml-auto btn-group btn-group-sm">
                <a href="client_register.php?regen=1&amp;api_site=<?= attr($_SESSION['selectedSite']) ?>"
                   class="btn btn-outline-success">Register Clients</a>
                <button type="submit" name="cleanSession" value="1" class="btn btn-outline-danger">Clear Session</button>
            </div>
        </form>

        <?php
        // Request first, then its response directly beneath it. The response used to render at the
        // very bottom of the page, so every Fetch meant scrolling past every other panel to see
        // what came back.
        explorer_panel_open('request', 'Request', ['subtitle' => 'build and send a call', 'open' => true]);
        ?>
            <form method="get" class="explorer-request-form">
                <div class="form-row align-items-end">
                    <div class="form-group col-6 col-md-3 col-xl-2">
                        <label class="small mb-1" for="reqClient">Client</label>
                        <select name="client" id="reqClient" class="form-control form-control-sm" required>
                            <option value="confidential" <?= $clientType === 'confidential' ? 'selected' : '' ?>>Confidential</option>
                            <option value="public" <?= $clientType === 'public' ? 'selected' : '' ?>>Public</option>
                            <option value="JWT" <?= $clientType === 'JWT' ? 'selected' : '' ?>>JWT</option>
                            <option value="smart" <?= $clientType === 'smart' ? 'selected' : '' ?>>SMART EHR Launch</option>
                        </select>
                    </div>
                    <div class="form-group col-6 col-md-3 col-xl-2">
                        <label class="small mb-1" for="reqGrant">Grant</label>
                        <select name="grant" id="reqGrant" class="form-control form-control-sm" required>
                            <option value="authorization_code" <?= $grantType === 'authorization_code' ? 'selected' : '' ?>>Authorization Code</option>
                            <option value="client_credentials" <?= $grantType === 'client_credentials' ? 'selected' : '' ?>>Client Credentials</option>
                        </select>
                    </div>
                    <div class="form-group col-6 col-md-2 col-xl-1">
                        <label class="small mb-1" for="reqApi">API</label>
                        <select name="api" id="reqApi" class="form-control form-control-sm" required>
                            <option value="fhir" <?= $apiType === 'fhir' ? 'selected' : '' ?>>FHIR</option>
                            <option value="standard" <?= $apiType === 'standard' ? 'selected' : '' ?>>Standard</option>
                        </select>
                    </div>
                    <div class="form-group col-6 col-md-4 col-xl-3">
                        <label class="small mb-1" for="reqResource">Resource</label>
                        <select name="resource" id="reqResource" class="form-control form-control-sm" required>
                            <!-- JS populates options from the selected API -->
                        </select>
                    </div>
                    <div class="form-group col-12 col-xl-4">
                        <label class="small mb-1" for="query">Query</label>
                        <div class="input-group input-group-sm">
                            <input
                                type="text"
                                name="query"
                                id="query"
                                class="form-control"
                                placeholder="_count=10&amp;name=..."
                                value="<?= attr($_SESSION['last_query'] ?? '') ?>"
                                aria-describedby="queryHelp"
                            />
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-warning" id="clearQueryBtn" title="Clear query">
                                    Clear
                                </button>
                                <button type="submit" name="fetch_action" value="true" class="btn btn-primary">
                                    Fetch
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        <?php explorer_panel_close(); ?>

        <?php if ($response) : ?>
            <?php explorer_panel_open('response', 'Response', [
                'subtitle' => 'last call',
                'open' => true,
                'bodyClass' => 'p-0',
            ]); ?>
                <pre class="explorer-response mb-0"><?= text(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
            <?php explorer_panel_close(); ?>
        <?php endif; ?>

    <hr />

    <?php
    // Bulk FHIR Group $export -- integrated; shares this Explorer's session token.
    explorer_panel_open('bulkExport', 'Bulk $export', ['subtitle' => 'Group export via NDJSON']);
    ?>
            <div class="form-row align-items-end">
                <div class="form-group col-md-7">
                    <label class="small mb-1" for="bulkGroupSelect">Group (provider panel)</label>
                    <select id="bulkGroupSelect" class="form-control form-control-sm">
                        <option value="">— list groups first —</option>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label class="small mb-1" for="bulkType">_type (optional)</label>
                    <input id="bulkType" class="form-control form-control-sm" placeholder="Patient,Observation">
                </div>
                <div class="form-group col-md-2">
                    <button type="button" id="bulkListBtn" class="btn btn-outline-secondary btn-sm btn-block">List</button>
                </div>
            </div>
            <button type="button" id="bulkStartBtn" class="btn btn-primary btn-sm" disabled>Start export</button>
            <span id="bulkSpinner" class="ml-2 small text-muted" style="display:none;">working…</span>
            <pre id="bulkOut" class="explorer-log mt-3 mb-0"></pre>
    <?php explorer_panel_close(); ?>
    <script>
    (function () {
        const sel = document.getElementById('bulkGroupSelect');
        const listBtn = document.getElementById('bulkListBtn');
        const startBtn = document.getElementById('bulkStartBtn');
        const spin = document.getElementById('bulkSpinner');
        const out = document.getElementById('bulkOut');
        const typeIn = document.getElementById('bulkType');
        function log(msg, reset) {
            out.style.display = 'block';
            out.textContent = (reset ? '' : out.textContent + '\n') + msg;
            out.scrollTop = out.scrollHeight;
        }
        async function api(params) {
            const res = await fetch('group_export.php?' + new URLSearchParams(params), { credentials: 'same-origin' });
            const data = await res.json().catch(() => ({ error: 'non-JSON response (are you logged in?)' }));
            return { ok: res.ok, data };
        }
        listBtn.addEventListener('click', async () => {
            spin.style.display = 'inline';
            log('Listing groups…', true);
            const { ok, data } = await api({ action: 'list' });
            spin.style.display = 'none';
            if (!ok) { log('Error: ' + (data.error || '') + (data.detail ? '\n' + data.detail : '')); return; }
            sel.innerHTML = '<option value="">— choose a group —</option>';
            (data.groups || []).forEach(g => {
                const o = document.createElement('option');
                o.value = g.id;
                o.textContent = g.id + (g.members != null ? '  (members: ' + g.members + ')' : '') + (g.name ? '  ' + g.name : '');
                sel.appendChild(o);
            });
            log((data.groups || []).length + ' group(s) loaded. Pick one with members.');
        });
        sel.addEventListener('change', () => { startBtn.disabled = !sel.value; });
        startBtn.addEventListener('click', async () => {
            const groupId = sel.value;
            if (!groupId) return;
            startBtn.disabled = true; spin.style.display = 'inline';
            log('Initiating export for Group/' + groupId + ' …', true);
            const initParams = { action: 'initiate', groupId };
            if (typeIn.value.trim()) initParams.type = typeIn.value.trim();
            const init = await api(initParams);
            if (!init.ok || !init.data.statusUrl) {
                spin.style.display = 'none'; startBtn.disabled = false;
                log('Initiate failed: ' + (init.data.error || '') + (init.data.hint ? '\n' + init.data.hint : '') + (init.data.detail ? '\n' + init.data.detail : ''));
                return;
            }
            log('Accepted (202). Polling status…');
            const statusUrl = init.data.statusUrl;
            let tries = 0;
            const timer = setInterval(async () => {
                tries++;
                const poll = await api({ action: 'poll', url: statusUrl });
                if (!poll.ok) {
                    clearInterval(timer); spin.style.display = 'none'; startBtn.disabled = false;
                    log('Poll error: ' + (poll.data.error || '') + (poll.data.hint ? '\n' + poll.data.hint : ''));
                    return;
                }
                if (!poll.data.done) { log('  … still running (poll ' + tries + ')'); if (tries > 40) { clearInterval(timer); spin.style.display='none'; startBtn.disabled=false; log('Timed out.'); } return; }
                clearInterval(timer); spin.style.display = 'none'; startBtn.disabled = false;
                const m = poll.data.manifest || {};
                const outputs = m.output || [];
                log('Export complete. ' + outputs.length + ' file(s):');
                outputs.forEach(o => log('  \u2022 ' + o.type + '  \u2192  ' + o.url));
                (m.error || []).forEach(e => log('  \u26a0 issues report (OperationOutcome)  \u2192  ' + e.url));
                if (!outputs.length) log('  (no output — group likely has no members/data)');
            }, 2500);
        });
    })();
    </script>

    <!-- FHIR writes — POST/PUT workbench; shares this Explorer's session token -->
    <?php include __DIR__ . '/fhir_write_card.php'; ?>

    <!-- Referral loop — works a referral's own Task (openemr #14710); shares this Explorer's session token -->
    <?php include __DIR__ . '/referral_loop_card.php'; ?>

    <!-- FHIR write stress — concurrent load; shares this Explorer's session token -->
    <?php include __DIR__ . '/fhir_stress_card.php'; ?>

    <!-- Scope Lab — v1 / v2 / mixed scopes across grant types; its own clients and tokens -->
    <?php include __DIR__ . '/scope_lab_card.php'; ?>

    <?php if ($smartWorkspace !== null && $smartPatientId !== null) : ?>
        <?php
        $patientName = is_array($smartPatient) ? smartPatientDisplayName($smartPatient) : 'SMART Launch Patient';
        $birthDate = is_array($smartPatient) && is_string($smartPatient['birthDate'] ?? null)
            ? $smartPatient['birthDate']
            : 'Not available';
        $gender = is_array($smartPatient) && is_string($smartPatient['gender'] ?? null)
            ? ucfirst($smartPatient['gender'])
            : 'Not available';

        $completedResponses = 0;
        $inProgressResponses = 0;
        foreach ($smartQuestionnaireResponses as $questionnaireResponse) {
            $status = $questionnaireResponse['status'] ?? '';
            if ($status === 'completed') {
                ++$completedResponses;
            } elseif (in_array($status, ['in-progress', 'amended'], true)) {
                ++$inProgressResponses;
            }
        }
        explorer_panel_open('smartWorkspace', 'SMART workspace', [
            'subtitle' => $patientName,
            'open' => true,
        ]);
        ?>
        <div class="smart-workspace-hero">
            <div class="pb-2">
                <div class="d-flex flex-wrap justify-content-between align-items-start">
                    <div>
                        <div class="text-uppercase small font-weight-bold text-primary">SMART on FHIR SDOH workspace</div>
                        <h3 class="mb-1"><?= text($patientName) ?></h3>
                        <div class="text-muted">
                            Patient UUID: <code><?= text($smartPatientId) ?></code>
                            &nbsp;·&nbsp; <?= text($birthDate) ?>
                            &nbsp;·&nbsp; <?= text($gender) ?>
                        </div>
                    </div>
                    <div class="text-right mt-2">
                        <span class="badge badge-success p-2">SMART launch authenticated</span>
                        <?php if ($smartLaunchSource === 'assessment') : ?>
                            <div class="mt-2">
                                <span class="badge badge-primary p-2">Assessment launch</span>
                            </div>
                            <div class="small text-muted mt-1">
                                Questionnaire/<code><?= text((string)$smartLaunchQuestionnaireId) ?></code>
                            </div>
                        <?php else : ?>
                            <div class="mt-2">
                                <span class="badge badge-secondary p-2">Patient dashboard launch</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-3 mb-2">
                        <div class="card smart-metric">
                            <div class="card-body py-3">
                                <div class="small text-muted">Questionnaires</div>
                                <div class="h3 mb-0"><?= text((string)count($smartQuestionnaires)) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card smart-metric">
                            <div class="card-body py-3">
                                <div class="small text-muted">
                                    <?= $smartLaunchSource === 'assessment' ? 'Matching responses' : 'Patient responses' ?>
                                </div>
                                <div class="h3 mb-0"><?= text((string)count($smartQuestionnaireResponses)) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card smart-metric">
                            <div class="card-body py-3">
                                <div class="small text-muted">Completed</div>
                                <div class="h3 mb-0"><?= text((string)$completedResponses) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card smart-metric">
                            <div class="card-body py-3">
                                <div class="small text-muted">In progress</div>
                                <div class="h3 mb-0"><?= text((string)$inProgressResponses) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="mb-0 mt-2">
                    This view was populated automatically from the patient UUID returned by the OpenEMR SMART EHR launch.
                    It demonstrates the handoff from EHR context to the FHIR Questionnaire and QuestionnaireResponse workflow.
                </p>
            </div>
        </div>

    <?php if ($smartLaunchSource === 'assessment') : ?>
    <?php
    $launchQuestionnaireTitle = is_array($smartLaunchQuestionnaire)
    && is_string($smartLaunchQuestionnaire['title'] ?? null)
        ? $smartLaunchQuestionnaire['title']
        : (is_array($smartLaunchQuestionnaire) && is_string($smartLaunchQuestionnaire['name'] ?? null)
            ? $smartLaunchQuestionnaire['name']
            : 'FHIR Assessment');
    $selectedResponseStatus = is_array($smartSelectedQuestionnaireResponse)
    && is_string($smartSelectedQuestionnaireResponse['status'] ?? null)
        ? $smartSelectedQuestionnaireResponse['status']
        : null;
    // The questionnaire runtime is a static asset of the OpenEMR install hosting the Explorer
    // (same origin as this page). It never talks to the launching EHR; all EHR traffic is FHIR.
    $runtimeRelative = '/interface/forms/questionnaire_assessments/native/openemr_questionnaire';
    $runtimeFileRoot = rtrim((string)($GLOBALS['fileroot'] ?? dirname(__DIR__, 2)), '/\\');
    $runtimeAvailable = is_file($runtimeFileRoot . $runtimeRelative . '.js');
    $runtimeWebBase = OEGlobalsBag::getInstance()->getWebRoot() . $runtimeRelative;
    $canRender = is_array($smartLaunchQuestionnaire) && $runtimeAvailable;
    $initialMode = ($smartLaunchQuestionnaireResponseId !== null && is_array($smartSelectedQuestionnaireResponse))
        ? 'continue'
        : 'new';
    ?>
        <?php if ($runtimeAvailable) : ?>
            <link rel="stylesheet" href="<?= attr($runtimeWebBase . '.css') ?>">
            <script src="<?= attr($runtimeWebBase . '.js') ?>"></script>
        <?php endif; ?>
        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <strong><?= text($launchQuestionnaireTitle) ?></strong>
                    <span class="badge badge-success ml-2">Launch context</span>
                    <span class="badge badge-info ml-1" id="smart-assessment-status-badge"
                        <?= $selectedResponseStatus === null ? 'hidden' : '' ?>><?= text((string)$selectedResponseStatus) ?></span>
                </div>
                <div class="btn-group btn-group-sm mt-2 mt-md-0">
                    <button type="button" class="btn btn-outline-primary" id="smart-assessment-start-new"
                        <?= $canRender ? '' : 'disabled' ?>>Start New</button>
                    <?php if (is_array($smartSelectedQuestionnaireResponse)) : ?>
                        <button type="button" class="btn btn-outline-warning" id="smart-assessment-continue"
                            <?= $canRender ? '' : 'disabled' ?>>Continue / Edit</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" id="smart-assessment-save-draft" disabled>Save Draft</button>
                    <button type="button" class="btn btn-primary" id="smart-assessment-complete" disabled>Complete</button>
                    <button type="button" class="btn btn-outline-secondary" id="smart-assessment-close" disabled>Close</button>
                </div>
            </div>
            <div class="card-body">
                <div id="smart-assessment-status" class="d-none" role="alert"></div>
                <?php if (!is_array($smartLaunchQuestionnaire)) : ?>
                    <div class="alert alert-danger mb-0">
                        Questionnaire/<code><?= text((string)$smartLaunchQuestionnaireId) ?></code> from the launch
                        context could not be read from <code><?= text(smartFhirBase()) ?></code>.
                    </div>
                <?php elseif (!$runtimeAvailable) : ?>
                    <div class="alert alert-danger mb-0">
                        The questionnaire runtime is not installed on this Explorer host
                        (<code><?= text($runtimeRelative) ?>.js</code>).
                    </div>
                <?php else : ?>
                    <div class="small text-muted mb-2" id="smart-assessment-working"></div>
                    <div id="smart-assessment-form" class="d-none"></div>
                <?php endif; ?>
            </div>
        </div>
    <?php else : ?>
        <div class="row">
            <div class="col-lg-7 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Available Assessment Questionnaires</strong>
                        <span class="badge badge-primary"><?= text((string)count($smartQuestionnaires)) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($smartQuestionnaires === []) : ?>
                            <div class="alert alert-warning mb-0">
                                <?php if ($smartLaunchQuestionnaireId !== null) : ?>
                                    The assessment launch context was received, but
                                    Questionnaire/<code><?= text($smartLaunchQuestionnaireId) ?></code>
                                    was not returned by the supported Questionnaire <code>_id</code> search.
                                <?php else : ?>
                                    No Questionnaire resources were returned for the granted scope.
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <div class="accordion" id="smartQuestionnaireAccordion">
                                <?php foreach ($smartQuestionnaires as $index => $questionnaire) : ?>
                                    <?php
                                    $questionnaireId = is_string($questionnaire['id'] ?? null)
                                        ? $questionnaire['id']
                                        : '';
                                    $title = is_string($questionnaire['title'] ?? null)
                                        ? $questionnaire['title']
                                        : (is_string($questionnaire['name'] ?? null)
                                            ? $questionnaire['name']
                                            : "Questionnaire {$questionnaireId}");
                                    $description = is_string($questionnaire['description'] ?? null)
                                        ? $questionnaire['description']
                                        : '';
                                    $items = smartFlattenQuestionnaireItems($questionnaire);
                                    $collapseId = 'smart-questionnaire-' . $index;
                                    $isLaunchQuestionnaire = $smartLaunchQuestionnaireId !== null
                                        && $questionnaireId === $smartLaunchQuestionnaireId;
                                    ?>
                                    <div class="card mb-2">
                                        <div class="card-header py-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <button
                                                    class="btn btn-link text-left p-0"
                                                    type="button"
                                                    data-toggle="collapse"
                                                    data-target="#<?= attr($collapseId) ?>"
                                                    aria-expanded="<?= $isLaunchQuestionnaire ? 'true' : 'false' ?>"
                                                >
                                                    <?= text($title) ?>
                                                </button>
                                                <div>
                                                    <?php if ($isLaunchQuestionnaire) : ?>
                                                        <span class="badge badge-success mr-1">Launch context</span>
                                                    <?php endif; ?>
                                                    <span class="badge badge-light"><?= text((string)count($items)) ?> items</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div
                                            id="<?= attr($collapseId) ?>"
                                            class="collapse<?= $isLaunchQuestionnaire ? ' show' : '' ?>"
                                            data-parent="#smartQuestionnaireAccordion"
                                        >
                                            <div class="card-body">
                                                <?php if ($description !== '') : ?>
                                                    <p><?= text($description) ?></p>
                                                <?php endif; ?>

                                                <?php if ($items === []) : ?>
                                                    <p class="text-muted">This Questionnaire has no rendered items.</p>
                                                <?php else : ?>
                                                    <?php foreach ($items as $item) : ?>
                                                        <?php
                                                        $depth = is_int($item['_depth'] ?? null) ? $item['_depth'] : 0;
                                                        $itemText = is_string($item['text'] ?? null)
                                                            ? $item['text']
                                                            : (is_string($item['linkId'] ?? null) ? $item['linkId'] : 'Question item');
                                                        $itemType = is_string($item['type'] ?? null) ? $item['type'] : 'item';
                                                        ?>
                                                        <div
                                                            class="smart-questionnaire-item"
                                                            style="margin-left: <?= attr((string)($depth * 14)) ?>px"
                                                        >
                                                            <strong><?= text($itemText) ?></strong>
                                                            <span class="badge badge-secondary ml-1"><?= text($itemType) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>

                                                <div class="mt-3">
                                                    <a
                                                        class="btn btn-sm btn-outline-primary"
                                                        href="?client=smart&amp;grant=authorization_code&amp;api=fhir&amp;resource=Questionnaire&amp;query=_id=<?= attr(rawurlencode($questionnaireId)) ?>"
                                                    >
                                                        View raw Questionnaire
                                                    </a>
                                                    <a
                                                        class="btn btn-sm btn-outline-secondary"
                                                        href="?client=smart&amp;grant=authorization_code&amp;api=fhir&amp;resource=QuestionnaireResponse&amp;query=patient=<?= attr(rawurlencode($smartPatientId)) ?>"
                                                    >
                                                        View patient responses
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Closed-loop Response Status</strong>
                        <span class="badge badge-info"><?= text((string)count($smartQuestionnaireResponses)) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($smartQuestionnaireResponses === []) : ?>
                            <div class="alert alert-info">
                                No QuestionnaireResponse resources were returned for this patient.
                            </div>
                            <p class="mb-0">
                                Complete an SDOH assessment in OpenEMR, then relaunch this SMART app to demonstrate the
                                response appearing here through FHIR.
                            </p>
                        <?php else : ?>
                            <?php foreach ($smartQuestionnaireResponses as $questionnaireResponse) : ?>
                                <?php
                                $responseId = is_string($questionnaireResponse['id'] ?? null)
                                    ? $questionnaireResponse['id']
                                    : '';
                                $status = is_string($questionnaireResponse['status'] ?? null)
                                    ? $questionnaireResponse['status']
                                    : 'unknown';
                                $authored = is_string($questionnaireResponse['authored'] ?? null)
                                    ? $questionnaireResponse['authored']
                                    : 'Date not supplied';
                                $questionnaireReference = is_string($questionnaireResponse['questionnaire'] ?? null)
                                    ? $questionnaireResponse['questionnaire']
                                    : 'Questionnaire not supplied';
                                $badgeClass = match ($status) {
                                    'completed' => 'badge-success',
                                    'in-progress' => 'badge-warning',
                                    'amended' => 'badge-info',
                                    'stopped', 'entered-in-error' => 'badge-danger',
                                    default => 'badge-secondary',
                                };
                                ?>
                                <div class="border rounded p-3 mb-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <strong><?= text($questionnaireReference) ?></strong>
                                        <span class="badge <?= attr($badgeClass) ?>"><?= text($status) ?></span>
                                    </div>
                                    <div class="small text-muted mt-1"><?= text($authored) ?></div>
                                    <?php if ($responseId !== '') : ?>
                                        <a
                                            class="btn btn-sm btn-link px-0 mt-1"
                                            href="?client=smart&amp;grant=authorization_code&amp;api=fhir&amp;resource=QuestionnaireResponse&amp;query=/<?= attr(rawurlencode($responseId)) ?>"
                                        >
                                            Inspect response
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php explorer_panel_close(); ?>
    <?php endif; ?>

    <?php if ($smartLaunchSource === 'assessment' && ($canRender ?? false)) : ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const questionnaire = <?= json_encode($smartLaunchQuestionnaire, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const existingResponse = <?= json_encode($smartSelectedQuestionnaireResponse, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const initialMode = <?= js_escape($initialMode) ?>;
                const saveUrl = 'oeApiExplorer.php?smart_action=save_questionnaire_response';
                const csrfToken = <?= js_escape((string)$_SESSION['smart_qr_csrf']) ?>;

                const container = document.getElementById('smart-assessment-form');
                const workingLabel = document.getElementById('smart-assessment-working');
                const statusElement = document.getElementById('smart-assessment-status');
                const statusBadge = document.getElementById('smart-assessment-status-badge');
                const startNewButton = document.getElementById('smart-assessment-start-new');
                const continueButton = document.getElementById('smart-assessment-continue');
                const saveDraftButton = document.getElementById('smart-assessment-save-draft');
                const completeButton = document.getElementById('smart-assessment-complete');
                const closeButton = document.getElementById('smart-assessment-close');

                let runtime = null;
                let currentResponse = null;

                const showStatus = (message, type) => {
                    statusElement.className = 'alert alert-' + type;
                    statusElement.textContent = message;
                };
                const clearStatus = () => {
                    statusElement.className = 'd-none';
                    statusElement.textContent = '';
                };
                const setBusy = (busy) => {
                    [saveDraftButton, completeButton, closeButton].forEach((button) => { button.disabled = busy || runtime === null; });
                };
                const describeWorking = () => {
                    workingLabel.textContent = currentResponse?.id
                        ? 'Working response: QuestionnaireResponse/' + currentResponse.id
                        : 'A new QuestionnaireResponse will be created for this patient on first save.';
                    if (currentResponse?.status) {
                        statusBadge.textContent = currentResponse.status;
                        statusBadge.hidden = false;
                    }
                };

                const mount = (response) => {
                    clearStatus();
                    container.replaceChildren();
                    container.classList.remove('d-none');
                    currentResponse = response ? structuredClone(response) : null;
                    runtime = OpenEMRQuestionnaire.mount({
                        questionnaire,
                        questionnaireResponse: currentResponse,
                        container,
                        options: { questionLayout: 'vertical' },
                    });
                    describeWorking();
                    setBusy(false);
                };

                const save = async (status) => {
                    if (!runtime) {
                        return;
                    }
                    clearStatus();
                    if (status === 'completed') {
                        const validation = runtime.validate();
                        if (!validation.valid) {
                            const message = validation.issues.map((issue) => issue.message).join(' ');
                            showStatus(message || 'Please correct the highlighted assessment fields.', 'danger');
                            return;
                        }
                    }

                    const payload = runtime.getQuestionnaireResponse();
                    payload.status = status;
                    if (currentResponse?.id) {
                        payload.id = currentResponse.id;
                        if (currentResponse.questionnaire) {
                            payload.questionnaire = currentResponse.questionnaire;
                        }
                    }

                    setBusy(true);
                    try {
                        const response = await fetch(saveUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Explorer-Csrf': csrfToken,
                            },
                            body: JSON.stringify(payload),
                        });
                        const result = await response.json().catch(() => ({}));
                        if (!response.ok || result.success !== true) {
                            throw new Error(result.message || ('Save failed (HTTP ' + response.status + ').'));
                        }
                        // Keep editing the saved resource so the next save is a PUT, not a new POST.
                        const saved = result.resource && result.resource.resourceType === 'QuestionnaireResponse'
                            ? result.resource
                            : { ...payload, id: result.response_id };
                        currentResponse = saved;
                        describeWorking();
                        showStatus(
                            (status === 'completed' ? 'Assessment completed' : 'Draft saved')
                            + ' as QuestionnaireResponse/' + (saved.id ?? '?') + ' via FHIR.',
                            'success'
                        );
                    } catch (error) {
                        showStatus(error instanceof Error ? error.message : 'Save failed.', 'danger');
                    } finally {
                        setBusy(false);
                    }
                };

                startNewButton?.addEventListener('click', () => mount(null));
                continueButton?.addEventListener('click', () => mount(existingResponse));
                saveDraftButton.addEventListener('click', () => save('in-progress'));
                completeButton.addEventListener('click', () => save('completed'));
                closeButton.addEventListener('click', () => {
                    container.replaceChildren();
                    container.classList.add('d-none');
                    runtime = null;
                    workingLabel.textContent = '';
                    clearStatus();
                    setBusy(false);
                });

                mount(initialMode === 'continue' ? existingResponse : null);
            });
        </script>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['smart_issuer']) || !empty($_SESSION['token_response'])) : ?>
        <?php explorer_panel_open('smartDiagnostics', 'SMART launch diagnostics', [
            'subtitle' => 'issuer, token response, app context',
        ]); ?>
                <p><strong>Issuer:</strong> <?= text((string)($_SESSION['smart_issuer'] ?? '')) ?></p>
                <?php if (!empty($_SESSION['smart_configuration'])) : ?>
                    <details>
                        <summary>SMART discovery document</summary>
                        <pre><?= text(json_encode($_SESSION['smart_configuration'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                    </details>
                <?php endif; ?>
                <?php if (!empty($_SESSION['token_response'])) : ?>
                    <?php
                    $smartContext = $_SESSION['token_response'];
                    unset($smartContext['access_token'], $smartContext['refresh_token'], $smartContext['id_token']);
                    $decodedAppContext = null;
                    if (isset($smartContext['appContext']) && is_string($smartContext['appContext'])) {
                        $decoded = json_decode($smartContext['appContext'], true);
                        $decodedAppContext = is_array($decoded) ? $decoded : null;
                    }
                    ?>
                    <h6>Token response context</h6>
                    <pre><?= text(json_encode($smartContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                    <?php if ($decodedAppContext !== null) : ?>
                        <h6>Decoded appContext</h6>
                        <pre><?= text(json_encode($decodedAppContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($accessToken) : ?>
                    <h6>Access token</h6>
                    <pre class="small smart-token mb-0"><?= text($accessToken) ?></pre>
                <?php endif; ?>
        <?php explorer_panel_close(); ?>
    <?php endif; ?>


    <?php explorer_panel_open('help', 'Help / read me', ['subtitle' => 'what this tool does']); ?>
        <?php include_once 'readme.php'; ?>
    <?php explorer_panel_close(); ?>

    <?php if ($errors) : ?>
        <div class="alert alert-danger">
            <ul><?php foreach ($errors as $err) : ?>
                    <li><?= text($err) ?></li>
                <?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    </div>
</body>
</html>
