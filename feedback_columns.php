<?php
function ensure_feedback_columns($conn){
    if(!mysqli_query($conn, "SELECT attachment_name FROM feedback LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE feedback ADD attachment_name varchar(255) DEFAULT ''");
    }
    if(!mysqli_query($conn, "SELECT attachment_original_name FROM feedback LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE feedback ADD attachment_original_name varchar(255) DEFAULT ''");
    }
}
