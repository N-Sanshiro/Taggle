<?php
// ===== デバッグ用（必ず一番上） =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();

// 致命的エラーも JSON で返す用
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'step'  => 'fatal',
            'error' => $e['message'] . ' in ' . $e['file'] . ':' . $e['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

// ---- ユーザーID ----
// 本番ではセッションから取りたいが、まずは 1 でテスト
$uid = 1;

// ---- DB 接続 ----
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '1toclass!SH0';
$DB_NAME = 'taggledb';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode([
        'ok'   => false,
        'step' => 'db_connect',
        'error'=> $mysqli->connect_error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

// ---- POST / ファイル検証 ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode([
        'ok'   => false,
        'step' => 'check_file',
        'error'=> 'no file',
        'files'=> $_FILES,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- ファイル読み込み ----
$tmp   = $_FILES['file']['tmp_name'];
$bytes = file_get_contents($tmp);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode([
        'ok'   => false,
        'step' => 'read_file',
        'error'=> 'cannot read tmp file',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- パラメータ ----
$tag_image_id   = isset($_POST['tag_image_id']) ? intval($_POST['tag_image_id']) : 0;
$name_cloth_raw = $_POST['name_cloth'] ?? '';
// name_cloth は NOT NULL なので、空なら空文字列で入れる
$name_cloth     = trim($name_cloth_raw);
if ($name_cloth === '') {
    $name_cloth = '';
}

// ---- clothes に INSERT ----
try {
    $stmt = $mysqli->prepare(
        'INSERT INTO clothes (id_user, cloth_image, name_cloth) VALUES (?, ?, ?)'
    );
    if (!$stmt) {
        throw new Exception('db prepare failed: '.$mysqli->error);
    }

    // cloth_image は BLOB だが、PHP からは文字列として渡してOK
    $stmt->bind_param('iss', $uid, $bytes, $name_cloth);

    if (!$stmt->execute()) {
        throw new Exception('db execute failed: '.$stmt->error);
    }

    $cloth_id = $mysqli->insert_id;
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    // サーバーログにも残しておく
    @file_put_contents(
        '/tmp/save_image_error.log',
        date('c')." insert_clothes: ".$e->getMessage()."\n",
        FILE_APPEND
    );

    echo json_encode([
        'ok'   => false,
        'step' => 'insert_clothes',
        'error'=> $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- relative 紐付け（tag_image_id がある場合だけ） ----
$linked = null;
if ($tag_image_id > 0 && $cloth_id > 0) {
    $stmt2 = $mysqli->prepare(
        'INSERT IGNORE INTO relative (id_tag, id_cloth, id_user) VALUES (?, ?, ?)'
    );
    if ($stmt2) {
        $stmt2->bind_param('iii', $tag_image_id, $cloth_id, $uid);
        if ($stmt2->execute()) {
            $linked = [
                'tag_id'   => $tag_image_id,
                'cloth_id' => $cloth_id,
                'user_id'  => $uid,
            ];
        } else {
            $linked = ['error' => $stmt2->error];
        }
        $stmt2->close();
    } else {
        $linked = ['error' => 'prepare failed: '.$mysqli->error];
    }
}

// ---- 完了レスポンス ----
http_response_code(200);
echo json_encode([
    'ok'       => true,
    'step'     => 'done',
    'cloth_id' => $cloth_id,
    'linked'   => $linked,
], JSON_UNESCAPED_UNICODE);
exit;
