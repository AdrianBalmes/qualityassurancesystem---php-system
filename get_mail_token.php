<?php
session_start();
require 'PHPMailer-master/vendor/autoload.php';

use League\OAuth2\Client\Provider\Google;

$googleClientId = getenv("GOOGLE_CLIENT_ID") ?: "";
$googleClientSecret = getenv("GOOGLE_CLIENT_SECRET") ?: "";

if ($googleClientId === "" || $googleClientSecret === "") {
    exit("Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in your local environment.");
}

$provider = new Google([
    'clientId'     => $googleClientId,
    'clientSecret' => $googleClientSecret,
    'redirectUri'  => 'http://localhost/qualityassurancesystem---php-system-main/get_mail_token.php',
    'accessType'   => 'offline',
]);

if (!isset($_GET['code'])) {
    // Step 1: Get authorization URL
    $authUrl = $provider->getAuthorizationUrl([
        'scope' => [
            'https://www.googleapis.com/auth/gmail.send'
        ]
    ]);
    $_SESSION['oauth2state'] = $provider->getState();
    header('Location: ' . $authUrl);
    exit;
} elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
    unset($_SESSION['oauth2state']);
    exit('Invalid state');
} else {
    // Step 2: Get access token
    try {
        $token = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);
        
        echo "<h3>Refresh Token obtained!</h3>";
        echo "<p>Save this Refresh Token in your notification script:</p>";
        echo "<pre>" . $token->getRefreshToken() . "</pre>";
    } catch (Exception $e) {
        exit('Error: ' . $e->getMessage());
    }
}
?>
