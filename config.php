<?php
$host = 'localhost';
$db = 'rsch';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

function impersonation_banner_html(): string {
  if (!empty($_SESSION['impersonating']) && !empty($_SESSION['original_admin'])) {
    $nama = htmlspecialchars($_SESSION['user']['nama'] ?? $_SESSION['user']['nik'] ?? 'User', ENT_QUOTES, 'UTF-8');
    return '
      <div style="background:#fff3cd;color:#856404;padding:8px 12px;text-align:center;">
        Anda sedang login sebagai <strong>'.$nama.'</strong>.
        <a href="stop_impersonate.php" style="margin-left:8px;text-decoration:underline;">Kembali ke akun Super Admin</a>
      </div>
    ';
  }
  return '';
}

?>
