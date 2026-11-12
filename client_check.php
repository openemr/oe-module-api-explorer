<?php

/**
 * client_check.php — asks the selected site whether each client this Explorer saved for it is
 * still registered and enabled.
 *
 * Uses the RFC 7592 client read endpoint (registration_client_uri + registration_access_token,
 * both saved by Register Clients). OpenEMR answers 403 for a client it doesn't have and for one
 * that is disabled, so the two read the same here: either way the fix is Register Clients, then
 * enable the client in Admin > System > API Clients. A database rebuilt since registration is
 * the usual cause, and it shows up as an EHR launch that never offers the Explorer, or an
 * authorize request answered 400.
 *
 * OpenEMR rotates registration_access_token on every successful read, so the new token is
 * written back to the client file. Without that the next check would be refused.
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

$site = (string) $api_site;
$results = [];
foreach (glob(__DIR__ . '/clients_keys/client_' . $site . '_*.json') ?: [] as $file) {
    $role = substr(basename($file, '.json'), strlen('client_' . $site . '_'));
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        $results[] = ['role' => $role, 'state' => 'unreadable', 'detail' => 'The client file is not JSON.'];
        continue;
    }
    $clientId = is_string($data['client_id'] ?? null) ? $data['client_id'] : '';
    $uri = is_string($data['registration_client_uri'] ?? null) ? $data['registration_client_uri'] : '';
    $token = is_string($data['registration_access_token'] ?? null) ? $data['registration_access_token'] : '';
    $row = ['role' => $role, 'clientId' => $clientId, 'name' => $data['client_name'] ?? null];
    if ($uri === '' || $token === '') {
        $results[] = $row + ['state' => 'unknown', 'detail' => 'No registration_client_uri saved; run Register Clients to make it checkable.'];
        continue;
    }
    // The bearer token only ever goes to the server that issued it.
    if (stripos($uri, $base_path . '/') !== 0) {
        $results[] = $row + ['state' => 'unknown', 'detail' => 'The saved registration URI is not on ' . $base_path . '.'];
        continue;
    }

    $ch = curl_init($uri);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : null;

    if ($code === 200 && is_array($decoded) && ($decoded['client_id'] ?? null) === $clientId) {
        if (is_string($decoded['registration_access_token'] ?? null) && $decoded['registration_access_token'] !== '') {
            $data['registration_access_token'] = $decoded['registration_access_token'];
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $results[] = $row + ['state' => 'ok', 'detail' => 'Registered and enabled.'];
        continue;
    }
    $reason = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? '') : '';
    $results[] = $row + [
        'state' => $code === 0 ? 'unreachable' : 'missing',
        'code' => $code,
        'detail' => $code === 0
            ? 'The server did not answer.'
            : 'Not registered, or not enabled' . ($reason !== '' ? ' (' . $reason . ')' : '')
                . '. Run Register Clients, then enable it in Admin > System > API Clients.',
    ];
}

echo json_encode([
    'site' => $site,
    'base' => $base_path,
    'clients' => $results,
    'hint' => $results === [] ? 'No clients saved for this site yet. Run Register Clients.' : null,
]);
