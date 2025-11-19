<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

/* ログインチェック */
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'not logged in']);
    exit;
}

/* DB接続 */
$mysqli = new mysqli('127.0.0.1', 'root', '', 'taggledb');
if ($mysqli->connect_errno) {
    echo json_encode([
        'ok'    => false,
        'error' => 'db connect failed: ' . $mysqli->connect_error,
    ]);
    exit;
}
$mysqli->set_charset('utf8mb4');

/* regions から 1件取得 */
$sql = "SELECT prefecture, latitude, longitude, timezone
        FROM regions
        WHERE id_user = ?
        LIMIT 1";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'ok'    => false,
        'error' => 'prepare: ' . $mysqli->error,
    ]);
    exit;
}

$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

$stmt->close();
$mysqli->close();

/* レコードが無いとき */
if (!$row) {
    echo json_encode(['ok' => false, 'row' => null]);
    exit;
}

/* 正常レスポンス */
echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
