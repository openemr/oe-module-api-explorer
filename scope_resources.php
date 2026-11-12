<?php

// scope_resources.php
session_start();

// Guarded: this file answers JSON, and an "Undefined array key" notice would be emitted
// into the response body, breaking the parse rather than producing a readable error.
if (!empty($_GET['api_site'])) {
    $api_site = $_GET['api_site'];
    $_SESSION['selectedSite'] = $api_site;
}

require_once 'config.php';

$scopes = null;
$fhir = [];
$standard = [];
$public = [];

// Fallback: use known scopes if not set
if (!$scopes) {
    $scopes = '';
    $grantType = $_GET['grant_type'] ?? '';
    $clientType = $_GET['client_type'] ?? '';
    if ($grantType === 'client_credentials') {
        $scopes = SYSTEM_SCOPES;
    } elseif ($clientType === 'smart') {
        $scopes = SMART_SCOPES;
    } elseif ($clientType === 'public') {
        $scopes = PUBLIC_SCOPES;
    } else {
        $scopes = LIMITED_SCOPES;
    }
}

// Detect FHIR/standard resources from legacy and SMART v2 granular scopes.
preg_match_all('/(?:user|system|patient)\/([A-Za-z][A-Za-z0-9]*)\.(?:read|r|rs|crus|cruds)/', $scopes, $matches);
foreach ($matches[1] as $resource) {
    if (ctype_upper($resource[0])) {
        $fhir[] = $resource;
    } else {
        $standard[] = $resource;
    }
}

// If system grant, include $export scopes as FHIR resources
if (($_GET['grant_type'] ?? '') === 'client_credentials') {
    preg_match_all('/system\/([A-Za-z*]+)\.\$export/', SYSTEM_SCOPES, $matchesExport);
    foreach ($matchesExport[1] as $res) {
        // Group/$export needs a real group id; the Bulk $export panel lists them and runs it.
        if ($res === 'Group') {
            continue;
        }
        $fhir[] = ($res === '*') ? '$export' : "$res/\$export";
    }
}

// Public
preg_match_all('/patient\/([A-Za-z]+)\.read/', PUBLIC_SCOPES, $matchesPublic);
$public = array_unique($matchesPublic[1]);

echo json_encode([
    'fhir' => array_values(array_unique($fhir)),
    'standard' => array_values(array_unique($standard)),
    'public' => $public,
]);
