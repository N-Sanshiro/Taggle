<?php
// === DB 接続テスト ===
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '1toclass!SH0';
$DB_NAME = 'taggledb';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode([
        'ok'   => false,
        'step' => 'db_connect',
        'error'=> $mysqli->connect_error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

// ここまで来たら DB 接続は OK
echo json_encode([
    'ok'   => true,
    'step' => 'after_db_connect',
    'post' => $_POST,
    'files'=> $_FILES,
], JSON_UNESCAPED_UNICODE);
exit;

