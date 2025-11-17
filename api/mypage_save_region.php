<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['uid'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login required'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';
$pdo = db();

$uid = (int)$_SESSION['uid'];

try {
    $raw = file_get_contents('php://input');
    $js  = json_decode($raw, true) ?: [];

    $prefecture = trim((string)($js['prefecture'] ?? ''));
    $timezone   = trim((string)($js['timezone'] ?? 'Asia/Tokyo'));
    $lat        = isset($js['latitude'])  ? (float)$js['latitude']  : null;
    $lon        = isset($js['longitude']) ? (float)$js['longitude'] : null;

    if ($prefecture === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'prefecture is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('
      INSERT INTO regions (id_user, prefecture, latitude, longitude, timezone)
      VALUES (:uid, :pref, :lat, :lon, :tz)
      ON DUPLICATE KEY UPDATE
        prefecture = VALUES(prefecture),
        latitude   = VALUES(latitude),
        longitude  = VALUES(longitude),
        timezone   = VALUES(timezone)
    ');
    $stmt->execute([
        ':uid'  => $uid,
        ':pref' => $prefecture,
        ':lat'  => $lat,
        ':lon'  => $lon,
        ':tz'   => $timezone,
    ]);
    $pdo->commit();

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('mypage_save_region error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db error'], JSON_UNESCAPED_UNICODE);
}
