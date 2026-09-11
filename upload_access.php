<?php

/**
 * Serving uploaded documents.
 *
 * uploads/ is not reachable directly (router.php for `php -S`, .htaccess for
 * Apache), so every document is streamed by a script that first checks who is
 * asking. This file holds the pieces those scripts share.
 */

const UPLOAD_MIME_TYPES = [
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'pdf'  => 'application/pdf',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];

/**
 * True when this browser may see documents that belong to $office: an admin
 * sees everything, an office sees its own. A browser signed in to several
 * offices at once (office_logins) sees the files of each of them.
 */
function upload_viewer_can_see_office($office){
    if(isset($_SESSION['admin_username'], $_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin'){
        return true;
    }

    $offices = array_keys($_SESSION['office_logins'] ?? []);
    if(isset($_SESSION['office_name'])){
        $offices[] = $_SESSION['office_name'];
    }
    return in_array($office, $offices, true);
}

/**
 * Secret for signed links, created on first use and kept in the database so
 * it never travels through git.
 */
function upload_signing_key($conn){
    static $key = null;
    if($key !== null){
        return $key;
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS app_settings (
        setting_key varchar(64) NOT NULL,
        setting_value text NOT NULL,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $candidate = bin2hex(random_bytes(32));
    $insert = $conn->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('upload_link_key', ?)");
    $insert->bind_param("s", $candidate);
    $insert->execute();

    // Read back rather than trust $candidate: another request may have won.
    $row = mysqli_query($conn, "SELECT setting_value FROM app_settings WHERE setting_key = 'upload_link_key'")->fetch_assoc();
    $key = $row['setting_value'];
    return $key;
}

function upload_signature($conn, $kind, $id, $expires){
    return hash_hmac('sha256', "{$kind}:{$id}:{$expires}", upload_signing_key($conn));
}

/**
 * Query string granting access to one document for $ttl seconds without a
 * session. Only for handing a file to something that cannot carry the login
 * cookie -- Word, opening a document through the ms-word: protocol.
 */
function upload_signed_query($conn, $kind, $id, $ttl = 900){
    $expires = time() + $ttl;
    return "id={$id}&exp={$expires}&sig=" . upload_signature($conn, $kind, $id, $expires);
}

function upload_signature_is_valid($conn, $kind, $id){
    $expires = (string) ($_GET['exp'] ?? '');
    $signature = (string) ($_GET['sig'] ?? '');

    if($signature === '' || !ctype_digit($expires) || (int) $expires < time()){
        return false;
    }
    return hash_equals(upload_signature($conn, $kind, $id, (int) $expires), $signature);
}

/**
 * Stream a stored upload and end the request. $inline asks the browser to show
 * the file rather than save it; that is only honoured for PDFs and images,
 * which a browser can display without running anything.
 */
function upload_send_file($path, $downloadName, $inline = false){
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $inline = $inline && in_array($extension, ['pdf', 'png', 'jpg', 'jpeg'], true);

    // filename= is the plain-ASCII fallback; filename*= carries the real name.
    $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName);
    $disposition = ($inline ? 'inline' : 'attachment')
        . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName);

    header('Content-Type: ' . (UPLOAD_MIME_TYPES[$extension] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . $disposition);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit();
}
