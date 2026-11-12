<?php

/**
 * fhir_http.php — the one FHIR HTTP call the Explorer's AJAX endpoints share.
 *
 * Used by fhir_write.php. The bearer token is attached here, server side, so it never reaches
 * the browser.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

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
        // No token for the public discovery documents.
        CURLOPT_HTTPHEADER => array_merge($access === '' ? [] : ["Authorization: Bearer {$access}"], $headers),
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
