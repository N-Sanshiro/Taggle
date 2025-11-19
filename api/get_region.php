<?php
// ★ デバッグ用：画面にエラーを全部出す
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();

/* ログインチェック（とりあえず uid 無くても動くようにしておく） */
$uid = (int)($_SESSION['uid'] ?? 0);
// デバッグ中は未ログインでも動かしたいのでコメントアウト
// if ($uid <= 0) {
//     echo json_encode(['ok' => false, 'error' => 'not logged in']);
//     exit;
// }

/* DB接続 */
$mysqli = new mysqli('127.0.0.1', 'root', '1toclass!SH0', 'taggledb');
if ($mysqli->connect_errno) {
    echo json_encode([
        'ok'    => false,
        'error' => 'db connect failed: ' . $mysqli->connect_error,
    ]);
    exit;
}
$mysqli->set_charset('utf8mb4');

/* regions から 1件取得（get_result を使わない版） */
$sql = "SELECT prefecture, latitude, longitude, timezone
        FROM regions
        LIMIT 1";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'ok'    => false,
        'error' => 'prepare: ' . $mysqli->error,
    ]);
    exit;
}

if (!$stmt->execute()) {
    echo json_encode([
        'ok'    => false,
        'error' => 'execute: ' . $stmt->error,
    ]);
    $stmt->close();
    $mysqli->close();
    exit;
}

/* bind_result + fetch */
$stmt->bind_result($prefecture, $latitude, $longitude, $timezone);
$row = null;
if ($stmt->fetch()) {
    $row = [
        'prefecture' => $prefecture,
        'latitude'   => $latitude,
        'longitude'  => $longitude,
        'timezone'   => $timezone,
    ];
}

$stmt->close();
$mysqli->close();

/* レコードが無いとき */
if (!$row) {
    echo json_encode(['ok' => false, 'row' => null]);
    exit;
}

/* 正常レスポンス */
echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
