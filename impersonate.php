<?php
session_start();
require 'config.php';

// Wajib login & Super Admin
if (!isset($_SESSION['user']) || ($_SESSION['user']['status'] ?? null) !== 'Super Admin') {
  http_response_code(403);
  exit('Forbidden');
}

// Cek CSRF
if (!isset($_GET['csrf']) || !hash_equals($_SESSION['impersonate_csrf'] ?? '', $_GET['csrf'])) {
  http_response_code(400);
  exit('Invalid CSRF token');
}

$nik = $_GET['nik'] ?? '';
if ($nik === '') { header('Location: users.php'); exit; }

// Ambil user target
$stmt = $pdo->prepare("SELECT * FROM users WHERE nik = ?");
$stmt->execute([$nik]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) { header('Location: users.php'); exit; }

// Simpan identitas admin asli agar bisa kembali
$_SESSION['impersonating'] = true;
$_SESSION['original_admin'] = $_SESSION['user'];   // simpan user admin
$_SESSION['user'] = $target;                       // ganti session ke user target

// (Opsional) refresh token CSRF biar link lama tidak bisa dipakai lagi
unset($_SESSION['impersonate_csrf']);

header('Location: sub_beranda.php');
exit;
