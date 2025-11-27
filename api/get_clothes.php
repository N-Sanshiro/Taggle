<?php
ini_set('display_errors','1'); 
ini_set('display_startup_errors','1'); 
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
  require_once __DIR__ . '/db.php';
  $pdo = db();
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'step'=>'db','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$limit   = isset($_GET['limit'])   ? max(1, min(1000, (int)$_GET['limit'])) : 100;

// ベースURL（例: http://localhost）とアプリ配下（/Taggle）
$BASE_URL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$APP_BASE = '/Taggle';

function to_abs($path, $BASE_URL, $APP_BASE) {
  if (!$path) return $path;
  if (preg_match('#^https?://#i', $path)) return $path; // 既に絶対URL
  // 先頭スラッシュなければ付与
  if ($path[0] !== '/') $path = "/$path";

  // もし既に /Taggle から始まっていればそのまま
  if (str_starts_with($path, $APP_BASE.'/') || $path === $APP_BASE) {
    return $BASE_URL . $path;
  }
  // 相対パス（/uploads/.. 等）の場合は /Taggle を前につける
  return $BASE_URL . $APP_BASE . $path;
}

try {
  if ($user_id !== null) {
    $stmt = $pdo->prepare('SELECT id_cloth, id_user, cloth_image FROM clothes WHERE id_user = ? ORDER BY id_cloth DESC LIMIT ?');
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit,   PDO::PARAM_INT);
  } else {
    $stmt = $pdo->prepare('SELECT id_cloth, id_user, cloth_image FROM clothes ORDER BY id_cloth DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
  }
  $stmt->execute();
  $rows = $stmt->fetchAll();

  // ★ ここでURL整形してから一度だけecho
  foreach ($rows as &$r) {
  $r['cloth_image'] = to_abs($r['cloth_image'] ?? '', $BASE_URL, $APP_BASE);
  }
  unset($r);

  $data = [
  'ok'    => true,
  'count' => count($rows),
  'rows'  => $rows
  ];

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    http_response_code(500);
    echo json_encode([
    'ok' => false,
    'error' => 'json encode failed',
    'detail' => json_last_error_msg()
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  echo $json;
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'query failed', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
