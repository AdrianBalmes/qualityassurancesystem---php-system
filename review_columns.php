<?php
function ensure_review_columns($conn){
    if(!mysqli_query($conn, "SELECT review_remarks FROM audit_recommendations LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE audit_recommendations ADD review_remarks text DEFAULT ''");
    }
    if(!mysqli_query($conn, "SELECT reviewed_by FROM audit_recommendations LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE audit_recommendations ADD reviewed_by varchar(50) DEFAULT ''");
    }
    if(!mysqli_query($conn, "SELECT reviewed_at FROM audit_recommendations LIMIT 1")){
        mysqli_query($conn, "ALTER TABLE audit_recommendations ADD reviewed_at datetime DEFAULT NULL");
    }

    $columnResult = mysqli_query($conn, "SHOW COLUMNS FROM audit_recommendations LIKE 'status'");
    if($columnResult){
        $columnInfo = $columnResult->fetch_assoc();
        if($columnInfo && stripos($columnInfo['Type'], 'enum(') === 0 && stripos($columnInfo['Type'], 'Approved') === false){
            mysqli_query($conn, "ALTER TABLE audit_recommendations MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
        }
    }
}
