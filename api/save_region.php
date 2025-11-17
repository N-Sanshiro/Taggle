<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

/* ★ セッションの uid を使う（テスト固定を削除） */
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'not logged in']); exit; }

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) { echo json_encode(['ok'=>false,'error'=>'bad json']); exit; }

$prefecture = trim($in['prefecture'] ?? '');
$latitude   = array_key_exists('latitude',  $in) ? $in['latitude']  : null;
$longitude  = array_key_exists('longitude', $in) ? $in['longitude'] : null;
$timezone   = trim($in['timezone'] ?? 'Asia/Tokyo');

if ($prefecture === '') { echo json_encode(['ok'=>false,'error'=>'prefecture required']); exit; }

/* null/数値の正規化 */
$latitude  = ($latitude  === null ? null : (float)$latitude);
$longitude = ($longitude === null ? null : (float)$longitude);

$mysqli = new mysqli('127.0.0.1', 'root', '', 'taggle');
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'db connect failed: '.$mysqli->connect_error]); exit; }
$mysqli->set_charset('utf8mb4');

/* ★ 初期化しておく */
$sql = "
  INSERT INTO regions (id_user, prefecture, latitude, longitude, timezone)
  VALUES (?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    prefecture = VALUES(prefecture),
    latitude   = VALUES(latitude),
    longitude  = VALUES(longitude),
    timezone   = VALUES(timezone)
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'prepare: '.$mysqli->error]); exit; }

$stmt->bind_param('isdds', $uid, $prefecture, $latitude, $longitude, $timezone);
$ok = $stmt->execute();

if (!$ok) {
  echo json_encode(['ok'=>false,'error'=>'execute: '.$stmt->error]);
} else {
  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
}

$stmt->close();
$mysqli->close();
