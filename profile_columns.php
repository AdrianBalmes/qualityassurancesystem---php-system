<?php
function ensure_profile_columns($conn){
    if(!mysqli_query($conn, "SELECT phone FROM users LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE users ADD phone varchar(30) DEFAULT ''");
    }
    if(!mysqli_query($conn, "SELECT profile_photo FROM users LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE users ADD profile_photo varchar(255) DEFAULT ''");
    }
    if(!mysqli_query($conn, "SELECT created_at FROM users LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE users ADD created_at datetime DEFAULT CURRENT_TIMESTAMP");
    }
    if(!mysqli_query($conn, "SELECT last_login FROM users LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE users ADD last_login datetime DEFAULT NULL");
    }
}
