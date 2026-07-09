<?php
if (file_exists(__DIR__ . "/m365_config.php")) {
    include(__DIR__ . "/m365_config.php");
}

function getM365OneDriveUserEmail() {
    return getenv("M365_ONEDRIVE_USER_EMAIL") ?: "";
}

function getRepositoryMode() {
    return strtolower($GLOBALS['REPOSITORY_MODE'] ?? "local");
}

function getMicrosoftAccessToken($forceRefresh = false) {
    $cacheFile = sys_get_temp_dir() . '/ms_token_cache.json';

    if (!$forceRefresh && file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if ($cache && isset($cache['token']) && isset($cache['expiration'])) {
            if (time() < $cache['expiration'] - 300) {
                return $cache['token'];
            }
        }
    }

    $tenantId = getenv("M365_TENANT_ID") ?: "";
    $clientId = getenv("M365_CLIENT_ID") ?: "";
    $clientSecret = getenv("M365_CLIENT_SECRET") ?: getenv("AZURE_CLIENT_SECRET") ?: ($GLOBALS['M365_CLIENT_SECRET'] ?? "");

    if ($tenantId === "" || $clientId === "") {
        throw new Exception("Set M365_TENANT_ID and M365_CLIENT_ID in your local environment.");
    }

    if (!$clientSecret || $clientSecret === "your_client_secret_here") {
        throw new Exception("Set the Azure App Registration client secret VALUE in M365_CLIENT_SECRET or AZURE_CLIENT_SECRET.");
    }

    $url = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";
    $data = [
        'client_id' => $clientId,
        'scope' => 'https://graph.microsoft.com/.default',
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError) {
        throw new Exception("Failed to get response from server. curl error: " . $curlError);
    }

    $result = json_decode($response, true);
    if ($result === null) {
        throw new Exception("Invalid JSON response. HTTP code: " . $httpCode . ". Server response: " . $response);
    }

    if (isset($result['error']) && $result['error'] === 'invalid_client') {
        throw new Exception(
            "Invalid Azure client secret. Azure needs the App Registration client secret VALUE, not the Secret ID. " .
            "Create a new secret in Azure Portal, set M365_CLIENT_SECRET, then restart Apache."
        );
    }

    if (!isset($result['access_token'])) {
        throw new Exception("No access_token in response. HTTP code: " . $httpCode . ". Response: " . $response);
    }

    $tokenParts = explode(".", $result['access_token']);
    $tokenPayload = isset($tokenParts[1]) ? json_decode(base64_decode(strtr($tokenParts[1], "-_", "+/")), true) : null;
    $roles = $tokenPayload['roles'] ?? [];
    if (!in_array("Files.ReadWrite.All", $roles) && !in_array("Sites.ReadWrite.All", $roles)) {
        throw new Exception(
            "Azure token has no OneDrive write permission. In Azure Portal, open this App Registration, " .
            "go to API permissions, add Microsoft Graph Application permission Files.ReadWrite.All, " .
            "then click Grant admin consent."
        );
    }

    file_put_contents($cacheFile, json_encode([
        'token' => $result['access_token'],
        'expiration' => time() + ($result['expires_in'] ?? 3600)
    ]));

    return $result['access_token'];
}
