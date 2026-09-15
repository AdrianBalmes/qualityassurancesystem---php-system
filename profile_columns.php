<?php
/**
 * Add the profile columns to users if an older database lacks them.
 *
 * Uses SHOW COLUMNS rather than a SELECT probe: since PHP 8.1 mysqli throws on
 * a failed query instead of returning false, so probing a missing column
 * crashed the page before the ALTER TABLE could run.
 */
function ensure_profile_columns($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    $columns = [
        'phone'         => "varchar(30) DEFAULT ''",
        'profile_photo' => "varchar(255) DEFAULT ''",
        'created_at'    => "datetime DEFAULT CURRENT_TIMESTAMP",
        'last_login'    => "datetime DEFAULT NULL",
    ];
    foreach($columns as $column => $definition){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '{$column}'");
        if($result && $result->num_rows === 0){
            mysqli_query($conn, "ALTER TABLE users ADD COLUMN `{$column}` {$definition}");
        }
    }
}
