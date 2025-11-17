<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'taggle';

$DIFY_API_BASE    = rtrim(getenv('DIFY_API_BASE') ?: 'https://api.dify.ai', '/');
$DIFY_API_KEY     = getenv('DIFY_API_KEY') ?: 'app-s31zJEqrNEGvq9WBv0ire9RU';
$DIFY_WORKFLOW_ID = getenv('DIFY_WORKFLOW_ID') ?: '';
$DIFY_INPUT_VAR   = getenv('DIFY_INPUT_VAR') ?: 'tag_image';
$DIFY_USER        = getenv('DIFY_USER') ?: 'taggle-app';

function fail($msg, $extra = []) {
  http_response_code(200);
  echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only');
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) fail('no file');

$uid = 1;
//isset($_SESSION['uid']) ? intval($_SESSION['uid']) : 0;
$item_name = $_POST['name'] ?? '';
$tmp  = $_FILES['file']['tmp_name'];
$name = $_FILES['file']['name'] ?? 'photo.jpg';
$mime = mime_content_type($tmp) ?: 'image/jpeg';
$bytes = file_get_contents($tmp);

// ---- DB 保存(tags) ----
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) fail('db connect failed: '.$mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

$stmt = $mysqli->prepare('INSERT INTO tags (id_user, tag_image) VALUES (?, ?)');
if (!$stmt) fail('db prepare failed: '.$mysqli->error);
$null = NULL;
$stmt->bind_param('ib', $uid, $null);
$stmt->send_long_data(1, $bytes);
if (!$stmt->execute()) fail('db execute failed: '.$stmt->error);
$image_id = $stmt->insert_id;
$stmt->close();

// ---- Dify 解析 ----
if (!$DIFY_API_KEY) {
  echo json_encode([
    'ok'=>true,
    'image_id'=>$image_id,
    'analyzed'=>false,
    'reason'=>'DIFY_API_KEY not set',
    'result'=>null,
    'raw'=>null
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// (1) ファイルアップロード
$upload_url = $DIFY_API_BASE.'/v1/files/upload';
$file = curl_file_create($tmp, $mime, $name);
$ch = curl_init($upload_url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$DIFY_API_KEY],
  CURLOPT_POSTFIELDS => ['user'=>$DIFY_USER, 'file'=>$file],
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 60,
]);
$resp = curl_exec($ch);
if ($resp === false) fail('upload curl error: '.curl_error($ch), ['image_id'=>$image_id]);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($http !== 201) fail('upload failed: '.$http.' '.$resp, ['image_id'=>$image_id]);

$j = json_decode($resp, true);
$file_id = $j['id'] ?? null;
if (!$file_id) fail('no file id from dify', ['image_id'=>$image_id, 'raw_upload'=>$resp]);

// (2) ワークフロー実行
$run_url = $DIFY_API_BASE.'/v1/workflows/run';
$inputs = [
  $DIFY_INPUT_VAR => [
    'type' => 'image',
    'transfer_method' => 'local_file',
    'upload_file_id' => $file_id
  ],
  'item_name' => $item_name
];
$payload = [
  'inputs' => $inputs,
  'response_mode' => 'blocking',
  'user' => $DIFY_USER
];
if (!empty($DIFY_WORKFLOW_ID)) $payload['workflow_id'] = $DIFY_WORKFLOW_ID;

$ch = curl_init($run_url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer '.$DIFY_API_KEY,
    'Content-Type: application/json',
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 180,
]);
$resp = curl_exec($ch);
if ($resp === false) fail('run curl error: '.curl_error($ch), ['image_id'=>$image_id]);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($http !== 200) fail('workflow run failed: '.$http.' '.$resp, ['image_id'=>$image_id]);

// (3) 結果の抽出
$raw = json_decode($resp, true);
$outputs = $raw['data']['outputs'] ?? [];
$result  = $outputs['result_json'] ?? $outputs['result'] ?? $outputs;

// (4) DBにJSON保存
$stmt2 = $mysqli->prepare('UPDATE tags SET result_json=? WHERE id_tag=?');
if ($stmt2) {
  $json_str = json_encode($result, JSON_UNESCAPED_UNICODE);
  $stmt2->bind_param('si', $json_str, $image_id);
  $stmt2->execute();
  $stmt2->close();
}

echo json_encode([
  'ok'=>true,
  'image_id'=>$image_id,
  'analyzed'=>true,
  'result'=>$result,
  'raw'=>$raw
], JSON_UNESCAPED_UNICODE);
?>
