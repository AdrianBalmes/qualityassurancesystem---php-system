<?php

// Command line only. tests/ is denied in .htaccess as well, for the same
// reason tools/ is: every file here runs against the live ems_db,
// so reached over the web they would let anyone alter it.
if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}

/**
 * The project has no test framework and no Composer, so this stands in for
 * PHPUnit. Each test file requires it, calls the helpers, then t_summary().
 */

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function t_ok($label, $condition){
    if($condition){
        $GLOBALS['t_pass']++;
        echo "  PASS  {$label}\n";
    } else {
        $GLOBALS['t_fail']++;
        echo "  FAIL  {$label}\n";
    }
}

function t_equals($label, $actual, $expected){
    $same = json_encode($actual) === json_encode($expected);
    t_ok($label, $same);
    if(!$same){
        echo "        expected " . json_encode($expected) . "\n";
        echo "        got      " . json_encode($actual) . "\n";
    }
}

/** Exit non-zero when anything failed, so a batch run stops. */
function t_summary(){
    echo "\n  {$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
    exit($GLOBALS['t_fail'] === 0 ? 0 : 1);
}
