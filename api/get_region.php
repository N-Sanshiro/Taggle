<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
$uid = 1;
//(int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'not logged in']); exit; }

$DB_HOST='127.0.0.1'; $DB_USER='root'; $DB_PASS=''; $DB_NAME='taggle';
$mysqli = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'db connect failed']); exit; }
$mysqli->set_charset('utf8mb4');

$sql = "SELECT id_region, id_user, prefecture, latitude, longitude, timezone
        FROM regions WHERE id_user = ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

echo json_encode(['ok'=>true, 'row'=>$row ?: null], JSON_UNESCAPED_UNICODE);