<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

/* ---- セッション確認 ---- */
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  echo json_encode(['ok'=>false, 'error'=>'この機能をしようするにはログインが必要です。ログインしてください。']);
  exit;
}

/* ---- DB 接続 ---- */
$DB_HOST='127.0.0.1'; $DB_USER='root'; $DB_PASS=''; $DB_NAME='taggle';
$mysqli = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
if ($mysqli->connect_errno) {
  echo json_encode(['ok'=>false, 'error'=>'db connect failed: '.$mysqli->connect_error]);
  exit;
}
$mysqli->set_charset('utf8mb4');

/* ---- BLOB -> dataURL（署名でMIME判定） ---- */
function blob_to_data_url(?string $bin): string {
  if ($bin === null || $bin === '') return '';
  $sig = bin2hex(substr($bin, 0, 4));
  $mime = 'image/jpeg';                      // 既定: JPEG
  if ($sig === '89504e47') $mime = 'image/png';
  elseif ($sig === '47494638') $mime = 'image/gif';
  // FFD8(=jpeg) は大小混在があるので既定でカバー
  return 'data:'.$mime.';base64,'.base64_encode($bin);
}

/* ---- result_json の “どんな形でも連想配列化” ---- */
function normalize_result(?string $raw) : array {
  if ($raw === null || $raw === '') return [];
  $x = json_decode($raw, true);

  // 文字列の中にJSONが入っている（二重JSON）ケース
  if (is_string($x)) {
    $y = json_decode($x, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($y)) $x = $y;
  }

  // 配列 [] の場合は {} 相当にする（表示ロジックの都合）
  if (!is_array($x) || !count($x)) return [];
  return $x;
}

/* ---- relative 経由で取得（ユーザーで絞り込み） ---- */
$sql = <<<SQL
SELECT
  t.id_tag       AS tag_id,
  c.id_cloth     AS cloth_id,
  t.tag_image,
  c.cloth_image,
  t.result_json,                           -- ← ここにカンマ必須
  t.created_at   AS created_at,            -- 文字列（日付）
  UNIX_TIMESTAMP(t.created_at)*1000 AS created_ts  -- 数値(ms)
FROM relative r
JOIN tags    t ON r.id_tag   = t.id_tag
JOIN clothes c ON r.id_cloth = c.id_cloth
WHERE r.id_user = ?
ORDER BY t.created_at DESC
SQL;

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
  $rows[] = [
    'tag_id'       => (int)$row['tag_id'],
    'cloth_id'     => (int)$row['cloth_id'],
    'tag_image'    => blob_to_data_url($row['tag_image']),
    'cloth_image'  => blob_to_data_url($row['cloth_image']),
    'result'       => normalize_result($row['result_json'] ?? ''),
    'created_at'   => $row['created_at'] ?? null,           // 例: "2025-01-12 14:03:00"
    'created_ts'   => isset($row['created_ts']) ? (int)$row['created_ts'] : null  // 例: 1736671380000
  ];
}

echo json_encode(['ok'=>true, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
