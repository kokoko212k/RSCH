<?php
include 'config.php';
session_start();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Ambil data dari form
    $no_surat = trim($_POST['no_surat'] ?? '');
    $pengirim = trim($_POST['pengirim'] ?? '');
    $penerima = trim($_POST['penerima'] ?? '');
    $pesan    = trim($_POST['pesan'] ?? '');

    if ($no_surat === '' || $pengirim === '' || $penerima === '' || $pesan === '') {
        header("Location: pesan_lihat.php?id=$no_surat&status=error&msg=Pesan tidak boleh kosong");
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO pesan (no_surat, pengirim, penerima, pesan, waktu)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$no_surat, $pengirim, $penerima, $pesan]);

        header("Location: pesan_lihat.php?id=$no_surat&status=success");
        exit;
    } catch (PDOException $e) {
        header("Location: pesan_lihat.php?id=$no_surat&status=error&msg=" . urlencode($e->getMessage()));
        exit;
    }
}
?>
