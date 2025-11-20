<?php
// デバッグ用（上に置く）
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();

// ここでサーバー側にログも残しておく（任意）
file_put_contents(
    '/tmp/save_image_debug.log',
    date('c')." HIT\n".
    print_r($_SERVER, true)."\n".
    print_r($_POST, true)."\n".
    print_r($_FILES, true)."\n\n",
    FILE_APPEND
);

// そのまま中身を返すだけ
echo json_encode([
    'ok'     => true,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'post'   => $_POST,
    'files'  => $_FILES,
], JSON_UNESCAPED_UNICODE);

exit;
