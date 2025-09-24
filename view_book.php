<?php
// Ambil file dari parameter
if (!isset($_GET['file'])) {
    die("File tidak ditemukan.");
}

$file = $_GET['file'];

// Amankan path (biar tidak bisa keluar dari folder koleksi/files)
$baseDir = realpath("koleksi/files/");
$realPath = realpath($file);

if ($realPath === false || strpos($realPath, $baseDir) !== 0) {
    die("Akses ditolak.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Lihat Buku</title>
  <style>
    body { margin:0; padding:0; }
    iframe {
      width: 100%;
      height: 100vh;
      border: none;
    }
  </style>
</head>
<body>
  <!-- PDF viewer -->
  <iframe src="<?= htmlspecialchars($file) ?>" allow="fullscreen"></iframe>
</body>
</html>
