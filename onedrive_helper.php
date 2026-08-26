<?php

require_once __DIR__ . "/env_helper.php";

/**
 * Microsoft Graph client for the shared OneDrive repository.
 *
 * Uses app-only auth (client credentials). There is no signed-in user, so
 * uploads keep working unattended -- no refresh token to expire, no consent
 * prompt. This requires OneDrive for Business; consumer OneDrive does not
 * support app-only access.
 */

const OD_GRAPH_BASE   = "https://graph.microsoft.com/v1.0";
const OD_SIMPLE_LIMIT = 4194304;  // 4 MB -- Graph's cap for a single PUT.
const OD_CHUNK_SIZE   = 3932160;  // 3.75 MB -- must be a multiple of 320 KiB.

function od_config(){
    static $config = null;
    if($config !== null){
        return $config;
    }

    $config = [
        'enabled'        => env_bool('ONEDRIVE_ENABLED', false),
        'dry_run'        => env_bool('ONEDRIVE_DRY_RUN', false),
        'mode'           => strtolower(env_get('ONEDRIVE_MODE', 'graph')),
        'local_path'     => rtrim(env_get('ONEDRIVE_LOCAL_PATH', ''), "/\\"),
        'tenant_id'      => env_get('ONEDRIVE_TENANT_ID', ''),
        'client_id'      => env_get('ONEDRIVE_CLIENT_ID', ''),
        'client_secret'  => env_get('ONEDRIVE_CLIENT_SECRET', ''),
        'drive_user'     => env_get('ONEDRIVE_DRIVE_USER', ''),
        'root_folder'    => env_get('ONEDRIVE_ROOT_FOLDER', 'QA Repository'),
        'sync_inline'    => env_bool('ONEDRIVE_SYNC_INLINE', true),
        'inline_timeout' => (int) env_get('ONEDRIVE_INLINE_TIMEOUT', 15),
        'max_attempts'   => (int) env_get('ONEDRIVE_MAX_ATTEMPTS', 6),
    ];

    return $config;
}

/** True when the repository is a synced OneDrive folder on this machine. */
function od_is_local_mode(){
    $config = od_config();
    return $config['mode'] === 'local';
}

/**
 * True when sync should run at all. Dry run counts as configured so the queue
 * can be exercised before IT provisions credentials.
 */
function od_is_configured(){
    $config = od_config();
    if(!$config['enabled']){
        return false;
    }
    if($config['dry_run']){
        return true;
    }

    // Local mode needs no credentials -- the OneDrive desktop client does the
    // uploading. All we need is somewhere to write.
    if(od_is_local_mode()){
        return $config['local_path'] !== '' && is_dir($config['local_path']);
    }

    foreach(['tenant_id', 'client_id', 'client_secret', 'drive_user'] as $required){
        if($config[$required] === ''){
            return false;
        }
    }
    return true;
}

function od_ensure_token_table($conn){
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS onedrive_tokens (
        id tinyint(1) NOT NULL DEFAULT 1,
        access_token text NOT NULL,
        expires_at datetime NOT NULL,
        updated_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/**
 * Fetch an app-only access token, reusing the cached one until it is close to
 * expiring. Graph tokens last about an hour; we refresh with 5 minutes to
 * spare so a long upload never runs past the expiry.
 */
function od_get_access_token($conn, $timeout = 20){
    $config = od_config();
    od_ensure_token_table($conn);

    $cached = mysqli_query($conn, "SELECT access_token FROM onedrive_tokens WHERE id = 1 AND expires_at > DATE_ADD(NOW(), INTERVAL 5 MINUTE)");
    if($cached && ($row = $cached->fetch_assoc())){
        return $row['access_token'];
    }

    $url = "https://login.microsoftonline.com/" . rawurlencode($config['tenant_id']) . "/oauth2/v2.0/token";
    $body = http_build_query([
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]);

    $response = od_http_request('POST', $url, [
        'Content-Type: application/x-www-form-urlencoded',
    ], $body, $timeout);

    if($response['status'] !== 200){
        throw new RuntimeException("Token request failed (HTTP {$response['status']}): " . od_describe_error($response['body']));
    }

    $payload = json_decode($response['body'], true);
    if(!isset($payload['access_token'], $payload['expires_in'])){
        throw new RuntimeException("Token response was missing access_token.");
    }

    $expiresAt = date('Y-m-d H:i:s', time() + (int) $payload['expires_in']);
    $stmt = $conn->prepare("INSERT INTO onedrive_tokens (id, access_token, expires_at) VALUES (1, ?, ?)
                            ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), expires_at = VALUES(expires_at), updated_at = NOW()");
    $stmt->bind_param("ss", $payload['access_token'], $expiresAt);
    $stmt->execute();

    return $payload['access_token'];
}

/**
 * Build the repository path for a department file:
 *   QA Repository/Finance/2026/Rec-12_report.pdf
 */
function od_build_remote_path($office, $year, $fileName){
    $config = od_config();

    $segments = [];
    foreach([$config['root_folder'], $office, $year] as $segment){
        $segment = od_sanitize_segment((string) $segment);
        if($segment !== ''){
            $segments[] = $segment;
        }
    }
    $segments[] = od_sanitize_segment($fileName);

    return implode('/', $segments);
}

/**
 * OneDrive rejects " * : < > ? / \ | in names, and rejects leading/trailing
 * whitespace and trailing dots.
 */
function od_sanitize_segment($segment){
    $segment = preg_replace('/[\x00-\x1F"\*:<>\?\/\\\\\|]+/', '_', $segment);
    $segment = trim($segment);
    $segment = rtrim($segment, '.');
    return $segment;
}

/** Percent-encode each path segment while leaving the separators intact. */
function od_encode_path($path){
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function od_drive_root_url(){
    $config = od_config();
    return OD_GRAPH_BASE . "/users/" . rawurlencode($config['drive_user']) . "/drive/root";
}

/**
 * Upload a local file to the repository. Returns the created item's id, web
 * URL and name. Throws RuntimeException on failure so callers can queue a
 * retry.
 */
function od_upload_file($conn, $localPath, $remotePath, $timeout = 60){
    $config = od_config();

    if(!is_readable($localPath)){
        throw new RuntimeException("Local file is not readable: {$localPath}");
    }

    $size = filesize($localPath);

    if($config['dry_run']){
        error_log("[onedrive][dry-run] would upload {$localPath} ({$size} bytes) to {$remotePath}");
        return [
            'id'      => 'dry-run',
            'web_url' => '',
            'name'    => basename($remotePath),
            'dry_run' => true,
        ];
    }

    if(od_is_local_mode()){
        return od_copy_to_local($localPath, $remotePath);
    }

    $token = od_get_access_token($conn, $timeout);

    return $size > OD_SIMPLE_LIMIT
        ? od_upload_large($token, $localPath, $remotePath, $size, $timeout)
        : od_upload_simple($token, $localPath, $remotePath, $timeout);
}

/**
 * Copy the file into the locally synced OneDrive folder and let the OneDrive
 * desktop client upload it. No credentials, no Graph, no admin consent -- but
 * it only works on a machine where that client is installed and signed in.
 *
 * The copy lands on a temporary name in the destination directory first, then
 * is renamed into place, so OneDrive never sees a half-written file and start
 * uploading it.
 */
function od_copy_to_local($localPath, $remotePath){
    $config = od_config();
    $root = $config['local_path'];

    if($root === '' || !is_dir($root)){
        throw new RuntimeException("ONEDRIVE_LOCAL_PATH is not a directory: {$root}");
    }

    $destination = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $remotePath);
    $directory = dirname($destination);

    if(!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)){
        throw new RuntimeException("Could not create repository folder: {$directory}");
    }

    $temp = $destination . '.qatmp';
    if(!@copy($localPath, $temp)){
        throw new RuntimeException("Could not copy into the OneDrive folder: {$destination}");
    }

    // rename() will not overwrite on Windows, so clear any previous copy first.
    if(file_exists($destination)){
        @unlink($destination);
    }

    if(!@rename($temp, $destination)){
        @unlink($temp);
        throw new RuntimeException("Could not finalise the repository copy: {$destination}");
    }

    return [
        'id'      => 'local',
        'web_url' => '',
        'name'    => basename($destination),
        'dry_run' => false,
    ];
}

function od_upload_simple($token, $localPath, $remotePath, $timeout){
    $url = od_drive_root_url() . ":/" . od_encode_path($remotePath) . ":/content";

    $contents = file_get_contents($localPath);
    if($contents === false){
        throw new RuntimeException("Could not read {$localPath}");
    }

    $response = od_http_request('PUT', $url, [
        "Authorization: Bearer {$token}",
        "Content-Type: application/octet-stream",
    ], $contents, $timeout);

    if($response['status'] !== 200 && $response['status'] !== 201){
        throw new RuntimeException("Upload failed (HTTP {$response['status']}): " . od_describe_error($response['body']));
    }

    return od_item_from_response($response['body']);
}

/**
 * Files over 4 MB must go through an upload session, sent as ordered chunks
 * whose size is a multiple of 320 KiB.
 */
function od_upload_large($token, $localPath, $remotePath, $size, $timeout){
    $sessionUrl = od_drive_root_url() . ":/" . od_encode_path($remotePath) . ":/createUploadSession";

    $response = od_http_request('POST', $sessionUrl, [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json",
    ], json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'rename']]), $timeout);

    if($response['status'] !== 200){
        throw new RuntimeException("Could not create upload session (HTTP {$response['status']}): " . od_describe_error($response['body']));
    }

    $session = json_decode($response['body'], true);
    if(empty($session['uploadUrl'])){
        throw new RuntimeException("Upload session response was missing uploadUrl.");
    }

    $handle = fopen($localPath, 'rb');
    if(!$handle){
        throw new RuntimeException("Could not open {$localPath} for reading.");
    }

    try {
        $offset = 0;
        while($offset < $size){
            $chunk = fread($handle, OD_CHUNK_SIZE);
            if($chunk === false || $chunk === ''){
                throw new RuntimeException("Unexpected end of file at offset {$offset}.");
            }

            $chunkLength = strlen($chunk);
            $end = $offset + $chunkLength - 1;

            $chunkResponse = od_http_request('PUT', $session['uploadUrl'], [
                "Content-Length: {$chunkLength}",
                "Content-Range: bytes {$offset}-{$end}/{$size}",
            ], $chunk, $timeout);

            // 202 = chunk accepted, more expected. 200/201 = final chunk.
            if(!in_array($chunkResponse['status'], [200, 201, 202], true)){
                od_http_request('DELETE', $session['uploadUrl'], [], null, 10);
                throw new RuntimeException("Chunk upload failed at byte {$offset} (HTTP {$chunkResponse['status']}): " . od_describe_error($chunkResponse['body']));
            }

            if($chunkResponse['status'] === 200 || $chunkResponse['status'] === 201){
                return od_item_from_response($chunkResponse['body']);
            }

            $offset += $chunkLength;
        }
    } finally {
        fclose($handle);
    }

    throw new RuntimeException("Upload session finished without returning an item.");
}

function od_item_from_response($body){
    $item = json_decode($body, true);
    return [
        'id'      => $item['id'] ?? '',
        'web_url' => $item['webUrl'] ?? '',
        'name'    => $item['name'] ?? '',
        'dry_run' => false,
    ];
}

/** Pull the human-readable message out of a Graph error envelope. */
function od_describe_error($body){
    $decoded = json_decode($body, true);
    if(isset($decoded['error']['message'])){
        $code = $decoded['error']['code'] ?? '';
        return trim($code . ' ' . $decoded['error']['message']);
    }
    if(isset($decoded['error_description'])){
        return $decoded['error_description'];
    }
    return substr((string) $body, 0, 300);
}

function od_http_request($method, $url, array $headers, $body, $timeout){
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if($body !== null){
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    if($responseBody === false){
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Network error contacting Microsoft: {$error}");
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $responseBody];
}
