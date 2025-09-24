<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id         = (int)($_POST['id_pesan'] ?? 0);
    $pesan_baru = trim($_POST['pesan_baru'] ?? '');

    // Ambil user dari session
    $user         = $_SESSION['user'] ?? [];
    $user_nama    = trim($user['nama']   ?? '');
    $user_status  = trim($user['status'] ?? '');

    if ($id <= 0 || $pesan_baru === '' || ($user_nama === '' && $user_status === '')) {
        http_response_code(400);
        echo "Data tidak lengkap";
        exit;
    }

    // Update pesan hanya kalau pengirim = user login (nama ATAU status)
    $stmt = $pdo->prepare("
        UPDATE pesan
        SET pesan = :pesan_baru
        WHERE id = :id
          AND (pengirim = :user_nama OR pengirim = :user_status)
        LIMIT 1
    ");
    $stmt->execute([
        ':pesan_baru'   => $pesan_baru,
        ':id'           => $id,
        ':user_nama'    => $user_nama,
        ':user_status'  => $user_status
    ]);

    if ($stmt->rowCount() > 0) {
        echo "Pesan berhasil diedit";
    } else {
        // Debug ringan agar tahu kenapa gagal
        $q = $pdo->prepare("SELECT pengirim FROM pesan WHERE id = :id");
        $q->execute([':id' => $id]);
        $owner = $q->fetchColumn();

        if ($owner === false) {
            echo "Gagal edit pesan: ID tidak ditemukan";
        } elseif ($owner !== $user_nama && $owner !== $user_status) {
            echo "Gagal edit pesan: bukan milik Anda";
        } else {
            echo "Gagal edit pesan: tidak ada perubahan isi";
        }
    }
}
?>