<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

/* ---- セッション確認 ---- */
if (!isset($_SESSION['uid'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false, 'error'=>'not logged in']);
  exit;
}
$uid = (int)$_SESSION['uid'];

/* ---- DB 接続 ---- */
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'taggle';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
  echo json_encode(['ok'=>false, 'error'=>'db connect failed: '.$mysqli->connect_error], JSON_UNESCAPED_UNICODE);
  exit;
}
$mysqli->set_charset('utf8mb4');

/* ---- POST検証 ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'POST only']);
  exit;
}
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  echo json_encode(['ok'=>false,'error'=>'no file']);
  exit;
}

/* ---- パラメータ取得 ---- */
$tag_image_id = isset($_POST['tag_image_id']) ? intval($_POST['tag_image_id']) : 0;
$name_cloth   = trim($_POST['name_cloth'] ?? '');

$tmp   = $_FILES['file']['tmp_name'];
$bytes = file_get_contents($tmp);

/* ---- clothes 保存 ---- */
if ($name_cloth === '') {
  $stmt = $mysqli->prepare('INSERT INTO clothes (id_user, cloth_image) VALUES (?, ?)');
  if (!$stmt) {
    echo json_encode(['ok'=>false,'error'=>'db prepare failed: '.$mysqli->error]);
    exit;
  }
  $null = NULL;
  $stmt->bind_param('ib', $uid, $null);
  $stmt->send_long_data(1, $bytes);
} else {
  $stmt = $mysqli->prepare('INSERT INTO clothes (id_user, cloth_image, name_cloth) VALUES (?, ?, ?)');
  if (!$stmt) {
    echo json_encode(['ok'=>false,'error'=>'db prepare failed: '.$mysqli->error]);
    exit;
  }
  $null = NULL;
  $stmt->bind_param('ibs', $uid, $null, $name_cloth);
  $stmt->send_long_data(1, $bytes);
}

$ok = $stmt->execute();
if (!$ok) {
  echo json_encode(['ok'=>false,'error'=>'db execute failed: '.$stmt->error], JSON_UNESCAPED_UNICODE);
  exit;
}
$cloth_id = $stmt->insert_id;
$stmt->close();

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
echo json_encode([
  'ok'      => true,
  'cloth_id'=> $cloth_id,
  'linked'  => $linked
], JSON_UNESCAPED_UNICODE);

?>
