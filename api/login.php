<?php
// api/login.php
require __DIR__ . '/db.php';
session_start();

function bad($msg, $code = 400) {
  http_response_code($code);
  // シンプルなエラー表示（必要ならテンプレート化してください）
  echo '<p style="color:red">'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</p>';
  echo '<p><a href="../frontend/login.html">戻る</a></p>';
  exit;
}

$mail_address = trim($_POST['mail_address'] ?? '');
$password     = (string)($_POST['password'] ?? '');

if (!filter_var($mail_address, FILTER_VALIDATE_EMAIL) || $password === '') {
  bad('メールアドレスまたはパスワードが不正です');
}

try {
  $pdo = db();

  // ★ 平文パスワードでの突き合わせ（DB構造に合わせています）
  $st = $pdo->prepare('SELECT id_user, user_name FROM user WHERE mail_address = ? AND password = ? LIMIT 1');
  $st->execute([$mail_address, $password]);
  $row = $st->fetch();

  if (!$row) {
    bad('メールアドレスまたはパスワードが正しくありません');
  }

  // ログイン成立 → セッション確立
  session_regenerate_id(true);
  $_SESSION['uid']       = (int)$row['id_user'];
  $_SESSION['user_name'] = $row['user_name'];
  $_SESSION['mail']      = $mail_address;

  // ログイン後の遷移先（フロントのマイページHTMLに合わせています）
  header('Location: /Taggle/api/mypage.php');
  exit;

} catch (Throwable $e) {
  // 本番では詳細はログへ、ユーザーには安全な文言のみ
  error_log('['.date('Y-m-d H:i:s')."] login.php: ".$e->getMessage()."\n", 3, __DIR__.'/../error.log');
  bad('サーバーエラーが発生しました。時間をおいて再度お試しください', 500);
}
