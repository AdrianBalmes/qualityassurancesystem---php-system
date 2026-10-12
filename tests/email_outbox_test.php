<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

echo "email_notifications: config and outbox table\n";

$config = email_config();
t_equals('config has every key', array_keys($config), [
    'enabled', 'dry_run', 'host', 'port', 'username', 'password',
    'from_name', 'max_attempts', 'batch_size', 'site_url',
]);
t_ok('port is an int', is_int($config['port']));
t_ok('max_attempts defaults above zero', $config['max_attempts'] > 0);
t_ok('enabled is a bool', is_bool($config['enabled']));

ensure_email_outbox_table($conn);
ensure_email_outbox_table($conn);   // idempotent: safe on every page load

$columns = [];
$result = mysqli_query($conn, "SHOW COLUMNS FROM email_outbox");
while($row = $result->fetch_assoc()){
    $columns[] = $row['Field'];
}
t_equals('outbox columns', $columns, [
    'id', 'recommendation_id', 'recipient_email', 'recipient_name',
    'recipient_office', 'event', 'subject', 'body', 'status',
    'attempts', 'last_error', 'created_at', 'sent_at',
]);

t_summary();
