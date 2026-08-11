<?php

/**
 * Minimal .env reader. The project has no Composer/vendor directory, so this
 * stands in for vlucas/phpdotenv. Values are cached after the first read.
 */
function env_all(){
    static $vars = null;
    if($vars !== null){
        return $vars;
    }

    $vars = [];
    $envPath = __DIR__ . "/.env";
    if(!is_readable($envPath)){
        return $vars;
    }

    foreach(file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line){
        $line = trim($line);
        if($line === '' || $line[0] === '#' || strpos($line, '=') === false){
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Strip one matching pair of surrounding quotes.
        $len = strlen($value);
        if($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))){
            $value = substr($value, 1, -1);
        }

        $vars[$key] = $value;
    }

    return $vars;
}

function env_get($key, $default = null){
    $vars = env_all();
    if(array_key_exists($key, $vars) && $vars[$key] !== ''){
        return $vars[$key];
    }

    $fromServer = getenv($key);
    if($fromServer !== false && $fromServer !== ''){
        return $fromServer;
    }

    return $default;
}

function env_bool($key, $default = false){
    $value = env_get($key);
    if($value === null){
        return $default;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}
