<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Ambil data form
    $no_surat = trim($_POST['no_surat'] ?? '');
    $pesan    = trim($_POST['pesan'] ?? '');

    // Ambil user dari session
    $user       = $_SESSION['user'] ?? [];
    $me_nik     = $user['nik']    ?? null;
    $me_nama    = $user['nama']   ?? '';
    $me_status  = $user['status'] ?? '';

    if ($no_surat === '' || $pesan === '') {
        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=error&msg=Pesan%20tidak%20boleh%20kosong");
        exit;
    }

    try {
        // Tentukan penerima berdasar status (contoh sederhana)
        $them_status = ($me_status === 'Super Admin') ? 'Sekretariat' : 'Super Admin';

        // Cari satu akun penerima (jika tabel users tersedia)
        $them_nik  = null;
        $them_nama = $them_status; // fallback label
        $stmtU = $pdo->prepare("SELECT nik, nama FROM users WHERE status = ? LIMIT 1");
        $stmtU->execute([$them_status]);
        if ($u = $stmtU->fetch(PDO::FETCH_ASSOC)) {
            $them_nik  = $u['nik']  ?? null;
            $them_nama = $u['nama'] ?? $them_status;
        }

        // Insert: kolom lama (pengirim/penerima) tetap diisi sebagai legacy label
        $stmt = $pdo->prepare("
            INSERT INTO pesan
              (no_surat,
               pengirim, pengirim_nik, pengirim_nama,
               penerima, penerima_nik, penerima_nama,
               pesan, waktu)
            VALUES
              (:no_surat,
               :pengirim, :pengirim_nik, :pengirim_nama,
               :penerima, :penerima_nik, :penerima_nama,
               :pesan, NOW())
        ");
        $stmt->execute([
            ':no_surat'      => $no_surat,
            ':pengirim'      => ($me_status ?: $me_nama), // legacy label (boleh status atau nama)
            ':pengirim_nik'  => $me_nik,
            ':pengirim_nama' => $me_nama,
            ':penerima'      => $them_status,            // legacy label
            ':penerima_nik'  => $them_nik,
            ':penerima_nama' => $them_nama,
            ':pesan'         => $pesan
        ]);

        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=success");
        exit;
    } catch (PDOException $e) {
        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=error&msg=" . urlencode($e->getMessage()));
        exit;
    }
}
