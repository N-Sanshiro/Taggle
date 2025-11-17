<?php
// api/signup.php
require __DIR__ . '/db.php';
session_start();

function bad($msg, $code = 400) {
  http_response_code($code);
  echo '<p style="color:red">'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</p>';
  echo '<p><a href="../frontend/signup.html">戻る</a></p>'; // ✅ 修正
  exit;
}

$user_name    = trim($_POST['user_name'] ?? '');
$mail_address = trim($_POST['mail_address'] ?? '');
$password     = (string)($_POST['password'] ?? '');

if ($user_name === '' || !filter_var($mail_address, FILTER_VALIDATE_EMAIL)) {
  bad('入力を確認してください（メール形式／パスワード6文字以上）');
}

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
  $pdo = db();
  $pdo->beginTransaction();

  $st = $pdo->prepare('SELECT id_user FROM user WHERE mail_address = ?');
  $st->execute([$mail_address]);
  if ($st->fetch()) {
    $pdo->rollBack();
    bad('このメールアドレスは既に登録されています');
  }

  $ins = $pdo->prepare('INSERT INTO user (user_name, mail_address, password) VALUES (?, ?, ?)');
  $ins->execute([$user_name, $mail_address, $hash]);

  $uid = (int)$pdo->lastInsertId();
  $pdo->commit();

  session_regenerate_id(true);
  $_SESSION['uid'] = $uid;
  $_SESSION['user_name'] = $user_name;

  header('Location: ../api/mypage.php'); // ✅ フロントエンド側に遷移
  exit;
}catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

  // ✅ ログファイルに詳細を残す
  error_log('[' . date('Y-m-d H:i:s') . '] signup.php: ' . $e->getMessage() . "\n", 3, __DIR__ . '/../error.log');

  // ✅ ユーザーには安全なメッセージのみ表示
  bad('サーバーエラーが発生しました。時間をおいて再度お試しください', 500);
}
