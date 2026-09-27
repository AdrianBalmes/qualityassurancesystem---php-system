<?php

/**
 * Start the session with a hardened cookie. Every page includes this instead
 * of calling session_start() itself, so the settings hold under any server
 * rather than depending on each machine's php.ini:
 *
 *  - httponly: page scripts cannot read the session cookie, so an injected
 *    script cannot steal a signed-in session.
 *  - samesite=Lax: other sites cannot make the browser send it on their
 *    forms, the usual route for cross-site request forgery.
 *  - use_strict_mode: PHP refuses a session id it did not issue, so nobody can
 *    plant a known id in a victim's browser and ride the session after they
 *    sign in.
 */

/**
 * True when the visitor's connection is HTTPS, including when TLS ended at a
 * proxy in front of this server.
 *
 * Cloudflare Tunnel terminates TLS and forwards plain HTTP from cloudflared,
 * which runs on this machine, so $_SERVER['HTTPS'] is empty for every visitor.
 * Taken at face value that would strip the Secure flag from the login cookie
 * and build http:// document links on a site served over https://.
 *
 * X-Forwarded-Proto is only believed when the request reaches us from the
 * loopback address: anything arriving from elsewhere could set that header
 * itself, while cloudflared cannot be impersonated from off the machine.
 */
function app_request_is_https(){
    if(!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off'){
        return true;
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $fromLoopback = in_array($remote, ['127.0.0.1', '::1'], true);
    $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

    // A proxy may forward a list: "https, http" -- the first entry is the
    // protocol the visitor actually used.
    if($forwarded !== '' && strpos($forwarded, ',') !== false){
        $forwarded = trim(explode(',', $forwarded)[0]);
    }

    return $fromLoopback && $forwarded === 'https';
}

if(session_status() === PHP_SESSION_NONE){
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => app_request_is_https(),
    ]);
    session_start();
}
