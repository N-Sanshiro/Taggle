<?php
function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;

  // ★ DB名だけ taggledb に変更
  $dsn  = 'mysql:host=localhost;dbname=taggledb;charset=utf8mb4';

  // ここはサーバー側のユーザー/パスに合わせてください
  $user = 'root';      // まだ作っていなければ一時的に 'root' でも動く
  $pass = '1toclass!SH0';  // MySQL のパスワード

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
  return $pdo;
}

// 互換用（昔の $pdo 直書きコード向け）
$pdo = db();
