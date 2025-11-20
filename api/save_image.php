<?php // ===== デバッグ用（必ず一番上） ===== //
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
session_start();
/* ---- セッション確認 ---- */ /* * ログイン時に $_SESSION['id_user'] をセットしている想定。
* 別のキーならここを合わせてください（user_id など）。 */
/*if (isset($_SESSION['id_user'])) { $uid = (int)$_SESSION['id_user']; }
elseif (isset($_SESSION['uid'])) { $uid = (int)$_SESSION['uid']; } else 
{ 
$uid = 1; // ← どうしても嫌ならここで 401 にする }*/ 
$uid = 1; /*if (!isset($_SESSION['id_user'])) { http_response_code(401); echo json_encode(['ok'=>false, 'error'=>'not logged in', 'session'=>$_SESSION], JSON_UNESCAPED_UNICODE); exit; }*/
 /* ---- DB 接続 ---- */ 
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
$mysqli->set_charset('utf8mb4'); /* ---- POST検証 ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'POST only'], JSON_UNESCAPED_UNICODE);
  exit;
}

// === ここまで DB 接続は通っている状態 ===

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

// ここまで来たら「画像読み込み」は成功
echo json_encode([
    'ok'        => true,
    'step'      => 'after_read_file',
    'post'      => $_POST,
    'file_size' => strlen($bytes),
    'file_name' => $_FILES['file']['name'] ?? null,
], JSON_UNESCAPED_UNICODE);
exit;
