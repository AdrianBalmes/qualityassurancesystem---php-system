<?php
// Simple script to get Microsoft Graph access token using authorization code flow
// Requires League OAuth2 Client library (already in PHPMailer-master/vendor if installed)

session_start();
require 'PHPMailer-master/vendor/autoload.php'; // Adjust path if needed

use Stevenmaguire\OAuth2\Client\Provider\Microsoft;

$microsoftClientId = getenv("MICROSOFT_CLIENT_ID") ?: "";
$microsoftClientSecret = getenv("MICROSOFT_CLIENT_SECRET") ?: "";

if ($microsoftClientId === "" || $microsoftClientSecret === "") {
    exit("Set MICROSOFT_CLIENT_ID and MICROSOFT_CLIENT_SECRET in your local environment.");
}

$provider = new Microsoft([
    'clientId'     => $microsoftClientId,
    'clientSecret' => $microsoftClientSecret,
    'redirectUri'  => 'http://localhost/qualityassurancesystem---php-system-main/get_token_interactive.php', // Adjust to your URL
]);

if (!isset($_GET['code'])) {
    // Step 1: Get authorization URL
    $authUrl = $provider->getAuthorizationUrl([
        'scope' => ['https://graph.microsoft.com/User.Read', 'https://graph.microsoft.com/Mail.Read'] // Adjust scopes as needed
    ]);
    $_SESSION['oauth2state'] = $provider->getState();
    echo '<a href="' . $authUrl . '">Click here to authorize and get token</a>';
    exit;
} elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
    unset($_SESSION['oauth2state']);
    echo 'Invalid state';
    exit;
} else {
    // Step 2: Get access token
    try {
        $token = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);
        echo 'Access Token: ' . $token->getToken();
        // Optionally save refresh token: $token->getRefreshToken()
    } catch (Exception $e) {
        echo 'Error: ' . $e->getMessage();
    }
}
?>
