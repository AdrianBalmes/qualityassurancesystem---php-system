<?php

/**
 * Router for PHP's built-in web server. Start the app with:
 *
 *     php -S localhost:8080 router.php
 *
 * Without this file, `php -S` hands out every file in the project as-is --
 * ems_db.sql (with passwords), .env (with the OneDrive secret), the whole .git
 * history, and every uploaded compliance document -- to anyone, signed in or
 * not. .htaccess does the same job under Apache; the built-in server ignores it.
 *
 * This is an allow-list, not a block-list: PHP pages, the site images in
 * assets/, and profile photos are reachable directly. Everything else 404s.
 * Uploaded documents are served by serve_upload.php and download_document.php,
 * which check who is asking.
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// Windows ignores case, accepts \ as a separator, drops trailing dots and
// understands name::$DATA. Rather than normalise every way of spelling a path,
// refuse anything that could be read more than one way.
$ambiguous = preg_match('#[\\\\:\x00]|\.\.|/\.|\.(?:/|$)|\s(?:/|$)#', $path);

$allowed = !$ambiguous && ($path === '/' || preg_match(
    '#^/(?:'
        . '[A-Za-z0-9_-]+\.php'                                     // pages
        . '|assets/[A-Za-z0-9._-]+\.(?:png|jpe?g|gif|svg|webp|ico|css|js)'
        . '|style\.css'
        . '|uploads/avatars/[A-Za-z0-9._-]+\.(?:png|jpe?g|gif|webp)' // profile photos
    . ')$#i',
    $path
));

if(!$allowed){
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

// "/" is the public front door, not the login form. The built-in server would
// otherwise reach for index.php; Apache is told the same thing by the
// DirectoryIndex line in .htaccess, so both servers agree on the entry point.
if($path === '/'){
    require __DIR__ . '/landing.php';
    return true;
}

// Let the built-in server run the page or send the file as it normally would.
return false;
