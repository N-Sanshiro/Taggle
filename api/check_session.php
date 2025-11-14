<?php
// /Taggle/api/check_session.php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok'        => true,
  'logged_in' => true,//isset($_SESSION['id_user']),
  'id_user'   => 1//$_SESSION['id_user'] ?? null,
], JSON_UNESCAPED_UNICODE);
