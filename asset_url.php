<?php

/**
 * A stylesheet or script URL stamped with the file's own modification time.
 *
 * Without the stamp a changed asset keeps being served from the browser cache,
 * so a fix only reaches people who know to hard-refresh -- which is not
 * something to ask of the offices using this site.
 */
function asset_url($path){
    $path = ltrim((string) $path, '/');
    $full = __DIR__ . '/' . $path;
    $stamp = is_file($full) ? filemtime($full) : 0;
    return htmlspecialchars($path . '?v=' . $stamp, ENT_QUOTES);
}
