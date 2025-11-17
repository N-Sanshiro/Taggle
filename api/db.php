<?php
function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;

  $dsn  = 'mysql:host=localhost;dbname=taggle;charset=utf8mb4';
  $user = 'root';  // phpMyAdminのユーザー名
  $pass = '';      // パスワード（XAMPPなら空）

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

$pdo = db();  // ← 互換用。昔の「$pdoを直接使う」コードも動く
