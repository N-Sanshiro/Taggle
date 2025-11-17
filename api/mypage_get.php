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

$uid        = (int)$_SESSION['uid'];
$user_name  = $_SESSION['user_name'] ?? 'ユーザー';

$curRegion = ['prefecture' => null, 'timezone' => 'Asia/Tokyo'];
$tagCount  = 0;

try {
    $st = $pdo->prepare('SELECT prefecture, timezone FROM regions WHERE id_user = :uid LIMIT 1');
    $st->execute([':uid' => $uid]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $curRegion['prefecture'] = $row['prefecture'] ?: null;
        $curRegion['timezone']   = $row['timezone']   ?: 'Asia/Tokyo';
    }
} catch (Throwable $e) {
    // ログだけ出す
    error_log('mypage_get region error: '.$e->getMessage());
}

try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM tags WHERE id_user = :uid');
    $st->execute([':uid' => $uid]);
    $tagCount = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
    error_log('mypage_get tagcount error: '.$e->getMessage());
}

echo json_encode([
    'ok'         => true,
    'user_name'  => $user_name,
    'prefecture' => $curRegion['prefecture'],
    'timezone'   => $curRegion['timezone'],
    'tagCount'   => $tagCount,
], JSON_UNESCAPED_UNICODE);
