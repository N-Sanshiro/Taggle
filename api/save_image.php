<?php
// ===== デバッグ用（必ず一番上） =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();
/* ---- セッション確認 ---- */
/*
 * ログイン時に $_SESSION['id_user'] をセットしている想定。
 * 別のキーならここを合わせてください（user_id など）。
 */
/*if (isset($_SESSION['id_user'])) {
    $uid = (int)$_SESSION['id_user'];
} elseif (isset($_SESSION['uid'])) {
    $uid = (int)$_SESSION['uid'];
} else {
    $uid = 1;   // ← どうしても嫌ならここで 401 にする
}*/
 $uid = 1;
/*if (!isset($_SESSION['id_user'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false, 'error'=>'not logged in', 'session'=>$_SESSION], JSON_UNESCAPED_UNICODE);
  exit;
}*/

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
$mysqli->set_charset('utf8mb4');

/* ---- POST検証 ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'POST only'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'no file'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---- パラメータ取得 ---- */
/* ---- パラメータ取得 ---- */
$tag_image_id = isset($_POST['tag_image_id']) ? intval($_POST['tag_image_id']) : 0;
$name_cloth_raw = $_POST['name_cloth'] ?? '';
$name_cloth = trim($name_cloth_raw) === '' ? null : trim($name_cloth_raw);

$tmp   = $_FILES['file']['tmp_name'];
$bytes = file_get_contents($tmp);
if ($bytes === false) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'step'=>'read_file','error'=>'cannot read tmp file'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---- clothes 保存 ---- */
/* ---- clothes 保存 ---- */
try {
  // 常に 3 カラムで INSERT
  $stmt = $mysqli->prepare(
    'INSERT INTO clothes (id_user, cloth_image, name_cloth) VALUES (?, ?, ?)'
  );
  if (!$stmt) {
    throw new Exception('db prepare failed: '.$mysqli->error);
  }

  // ★ ここを変更：send_long_data を使わず、文字列としてそのまま渡す
  //   cloth_image が BLOB カラムでも s で問題ありません
  $stmt->bind_param('iss', $uid, $bytes, $name_cloth);

  if (!$stmt->execute()) {
    throw new Exception('db execute failed: '.$stmt->error);
  }

  $cloth_id = $mysqli->insert_id;
  $stmt->close();

} catch (Exception $e) {
  // エラー内容をログに残しておくとさらに安心
  file_put_contents(
    'error.log',
    date('c').' '.$e->getMessage()."\n",
    FILE_APPEND
  );

  http_response_code(500);
  echo json_encode([
    'ok'   => false,
    'step' => 'insert_clothes',
    'error'=> $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
  exit;
}



/* ---- relative 紐付け保存 ---- */
$linked = null;
if ($tag_image_id > 0 && $cloth_id > 0) {
  $stmt2 = $mysqli->prepare('INSERT IGNORE INTO relative (id_tag, id_cloth, id_user) VALUES (?, ?, ?)');
  if ($stmt2) {
    $stmt2->bind_param('iii', $tag_image_id, $cloth_id, $uid);
    if ($stmt2->execute()) {
      $linked = ['tag_id'=>$tag_image_id, 'cloth_id'=>$cloth_id, 'user_id'=>$uid];
    } else {
      $linked = ['error'=>$stmt2->error];
    }
    $stmt2->close();
  } else {
    $linked = ['error'=>'prepare failed: '.$mysqli->error];
  }
}

/* ---- 完了レスポンス ---- */
http_response_code(200);
echo json_encode([
  'ok'      => true,
  'cloth_id'=> $cloth_id,
  'linked'  => $linked
], JSON_UNESCAPED_UNICODE);