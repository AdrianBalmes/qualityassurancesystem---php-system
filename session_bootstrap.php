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

if(session_status() === PHP_SESSION_NONE){
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
