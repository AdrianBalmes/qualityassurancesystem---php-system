<?php
function getMicrosoftAccessToken() {
    // Check for cached token first
    $cacheFile = sys_get_temp_dir() . '/ms_token_cache.json';
    
    
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if ($cache && isset($cache['token']) && isset($cache['expiration'])) {
            if (time() < $cache['expiration'] - 300) { // Refresh 5 mins before expiry
                return $cache['token'];
            }
        }
    }
    
    $tenantId = "f710cb6b-9f27-4be9-b68a-8be9ba3e2657";
    $clientId = "b8f85ab7-3fbf-4dc6-99df-256f8cf00fa7";
    $clientSecret = getenv("AZURE_CLIENT_SECRET");

    if ($clientSecret === "your_client_secret_here") {
        throw new Exception("Please replace 'your_client_secret_here' with your actual Azure app client secret.");
    }

    $url = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";

    $data = [
        'client_id'  => $clientId,
        'scope'  => 'https://graph.microsoft.com/.default',
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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

    if (!isset($result['access_token'])) {
        throw new Exception("No access_token in response. HTTP code: " . $httpCode . ". Response: " . $result);
    }

    // Cache the token
    $tokenExpiration = time() + ($result['expires_in'] ?? 3600);
    $cacheData = [
        'token' => $result['access_token'],
        'expiration' => $tokenExpiration
    ];
    file_put_contents($cacheFile, json_encode($cacheData));

    return $result['access_token'];
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        echo getMicrosoftAccessToken();
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}


