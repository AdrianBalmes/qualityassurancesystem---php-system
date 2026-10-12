<?php
/**
 * Add the review-workflow and accreditation-area columns to
 * audit_recommendations if an older database lacks them, and widen the
 * original status enum.
 *
 * Uses SHOW COLUMNS rather than a SELECT probe: since PHP 8.1 mysqli throws on
 * a failed query instead of returning false, so probing a missing column
 * crashed the page before the ALTER TABLE could run.
 */
function ensure_review_columns($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    // TEXT cannot carry a DEFAULT on MySQL 8 (MariaDB allows it), so none here.
    $columns = [
        'review_remarks' => "text",
        'reviewed_by'    => "varchar(50) DEFAULT ''",
        'reviewed_at'    => "datetime DEFAULT NULL",
        // The external audit files each recommendation under an accreditation
        // area, and under a programme for offices that have them. Definitions
        // match ems_db.sql; a database created before this feature has neither,
        // and every area query selects them by name.
        'area'           => "varchar(120) NOT NULL DEFAULT ''",
        'program'        => "varchar(120) NOT NULL DEFAULT ''",
        'in_charge'      => "text",
        // A QA officer sets the date the office has to submit by. All three
        // timestamps are the deadline's own bookkeeping: when each notice went
        // out, and when the deadline was satisfied. Nullable because most
        // recommendations have no deadline, which is a normal state and not
        // missing data.
        'deadline'                    => "date DEFAULT NULL",
        'deadline_soon_notified_at'   => "datetime DEFAULT NULL",
        'deadline_missed_notified_at' => "datetime DEFAULT NULL",
        // Stamped the first time a status closes the deadline, so an office
        // that submitted on time and was sent back after the date is never
        // told it missed one.
        'deadline_met_at'             => "datetime DEFAULT NULL",
    ];
    foreach($columns as $column => $definition){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM audit_recommendations LIKE '{$column}'");
        if($result && $result->num_rows === 0){
            mysqli_query($conn, "ALTER TABLE audit_recommendations ADD COLUMN `{$column}` {$definition}");
        }
    }

    // The deadline sweep runs every few minutes forever, and this table had
    // no index but its primary key. SHOW INDEX for the same reason SHOW
    // COLUMNS is used above: a duplicate CREATE INDEX would throw.
    $indexResult = mysqli_query($conn, "SHOW INDEX FROM audit_recommendations WHERE Key_name = 'deadline'");
    if($indexResult && $indexResult->num_rows === 0){
        mysqli_query($conn, "CREATE INDEX deadline ON audit_recommendations (deadline)");
    }

    // A year used to be four digits. It can now also be a school year --
    // "2026-2027" -- which varchar(4) would silently cut down to "2026".
    $yearResult = mysqli_query($conn, "SHOW COLUMNS FROM audit_recommendations LIKE 'year'");
    $yearInfo = $yearResult ? $yearResult->fetch_assoc() : null;
    if($yearInfo && preg_match('/varchar\((\d+)\)/i', $yearInfo['Type'], $size) && (int) $size[1] < 9){
        mysqli_query($conn, "ALTER TABLE audit_recommendations MODIFY year VARCHAR(9) NOT NULL DEFAULT ''");
    }

    // College Department reviews one submitted document at a time, so each
    // document carries its own decision and remarks.
    $docTable = mysqli_query($conn, "SHOW TABLES LIKE 'recommendation_documents'");
    if($docTable && $docTable->num_rows > 0){
        $docColumns = [
            'review_status'  => "varchar(20) DEFAULT NULL",
            'review_remarks' => "text",
            'reviewed_by'    => "varchar(50) DEFAULT NULL",
            'reviewed_at'    => "datetime DEFAULT NULL",
        ];
        foreach($docColumns as $column => $definition){
            $result = mysqli_query($conn, "SHOW COLUMNS FROM recommendation_documents LIKE '{$column}'");
            if($result && $result->num_rows === 0){
                mysqli_query($conn, "ALTER TABLE recommendation_documents ADD COLUMN `{$column}` {$definition}");
            }
        }
    }

    $columnResult = mysqli_query($conn, "SHOW COLUMNS FROM audit_recommendations LIKE 'status'");
    if($columnResult){
        $columnInfo = $columnResult->fetch_assoc();
        if($columnInfo && stripos($columnInfo['Type'], 'enum(') === 0 && stripos($columnInfo['Type'], 'Approved') === false){
            mysqli_query($conn, "ALTER TABLE audit_recommendations MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
        }
    }
}
