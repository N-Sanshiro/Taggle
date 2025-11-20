<?php
// ===== デバッグ用（必ず一番上） =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
session_start();

// ユーザーID（本番はセッションに戻す）
$uid = 1;

// ---- DB 接続 ----
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '1toclass!SH0';
$DB_NAME = 'taggledb';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'step'=>'db_connect', 'error'=>$mysqli->connect_error], JSON_UNESCAPED_UNICODE);
  exit;
}
$mysqli->set_charset('utf8mb4');

// ---- POST検証 ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'POST only'], JSON_UNESCAPED_UNICODE);
  exit;
}

// アップロードファイル確認
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode([
        'ok'   => false,
        'step' => 'check_file',
        'error'=> 'no file',
        'files'=> $_FILES,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 画像は読むけど、ここでは DB に使わない（テキストだけ INSERT テスト）
$tmp   = $_FILES['file']['tmp_name'];
$bytes = file_get_contents($tmp);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode([
        'ok'   => false,
        'step' => 'read_file',
        'error'=> 'cannot read tmp file',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// パラメータ
$name_cloth_raw = $_POST['name_cloth'] ?? '';
$name_cloth     = trim($name_cloth_raw) === '' ? null : trim($name_cloth_raw);

// ★★ Step1: テキストだけ INSERT ★★
try {
    $stmt = $mysqli->prepare(
        'INSERT INTO clothes (id_user, cloth_image, name_cloth) VALUES (?, ?, null)'
    );
    if (!$stmt) {
        throw new Exception('db prepare failed: '.$mysqli->error);
    }

    // cloth_image はダミー文字列でもOK（NOT NULLのため）
    $dummy = 'test';

    $stmt->bind_param('iss', $uid, $dummy, $name_cloth);

    if (!$stmt->execute()) {
        throw new Exception('db execute failed: '.$stmt->error);
    }

    $cloth_id = $stmt->insert_id;

    echo json_encode([
        'ok' => true,
        'step' => 'insert_minimum_ok',
        'cloth_id' => $cloth_id,
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok'=>false,
        'step'=>'insert_minimum',
        'error'=>$e->getMessage(),
    ]);
    exit;
}


} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok'   => false,
        'step' => 'insert_text_only',
        'error'=> $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
