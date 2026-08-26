<?php

/**
 * Per-role session scopes.
 *
 * An admin sign-in and an office sign-in live in the same PHP session but in
 * separate key namespaces (admin_* and office_*), so one browser can hold both
 * at once -- useful when an administrator also runs an office. Signing out of
 * one must therefore clear only that namespace: session_destroy() would take
 * the other down with it.
 *
 * Offices go one step further. $_SESSION['office_logins'] is keyed by office
 * name, so several offices can be signed in together and switched between with
 * ?office=Name. Signing out of one leaves the rest alone.
 */

function session_scope_has_admin(){
    return isset($_SESSION['admin_username']);
}

function session_scope_has_office(){
    return isset($_SESSION['office_username']) || !empty($_SESSION['office_logins']);
}

/** Forget the admin sign-in, leaving any office sign-ins untouched. */
function session_scope_logout_admin(){
    foreach(['admin_username', 'admin_role', 'admin_office', 'admin_user_id', 'admin_full_name'] as $key){
        unset($_SESSION[$key]);
    }
    session_scope_end_if_empty();
}

/**
 * Forget one office sign-in, or every office when $office is ''.
 *
 * When other offices remain signed in, the active office switches to one of
 * them rather than dropping the user back at the login screen.
 */
function session_scope_logout_office($office = ''){
    if($office !== '' && isset($_SESSION['office_logins'][$office])){
        unset($_SESSION['office_logins'][$office]);
    } else {
        $_SESSION['office_logins'] = [];
    }

    if(!empty($_SESSION['office_logins'])){
        $next = reset($_SESSION['office_logins']);
        $_SESSION['office_full_name'] = $next['full_name'] ?? '';
        $_SESSION['office_username']  = $next['username'];
        $_SESSION['office_role']      = $next['role'];
        $_SESSION['office_name']      = $next['office'];
        $_SESSION['office_user_id']   = $next['id'];
        $_SESSION['office_email']     = $next['email'];
        return $next['office'];
    }

    foreach(['office_username', 'office_role', 'office_name', 'office_user_id',
             'office_email', 'office_full_name', 'office_logins'] as $key){
        unset($_SESSION[$key]);
    }
    session_scope_end_if_empty();
    return '';
}

/** Sign out of everything and dispose of the session properly. */
function session_scope_logout_all(){
    $_SESSION = [];

    // session_destroy() leaves the cookie behind, so the browser keeps
    // presenting a dead session id until it expires. Expire it explicitly.
    if(ini_get('session.use_cookies')){
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'],
                  $params['secure'], $params['httponly']);
    }

    session_destroy();
}

/** Once neither role is signed in there is nothing left worth keeping. */
function session_scope_end_if_empty(){
    if(!session_scope_has_admin() && !session_scope_has_office()){
        session_scope_logout_all();
    }
}
