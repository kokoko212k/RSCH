<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id         = (int)($_POST['id_pesan'] ?? 0);
    $pesan_baru = trim($_POST['pesan_baru'] ?? '');

    // Ambil user dari session
    $user        = $_SESSION['user'] ?? [];
    $user_nik    = trim($user['nik']    ?? '');
    $user_nama   = trim($user['nama']   ?? '');
    $user_status = trim($user['status'] ?? '');

    if ($id <= 0 || $pesan_baru === '' || ($user_nik === '' && $user_nama === '' && $user_status === '')) {
        http_response_code(400);
        echo "Data tidak lengkap";
        exit;
    }

    // Cek kepemilikan pesan (pakai NIK jika ada, fallback ke kolom lama)
    $q = $pdo->prepare("SELECT pengirim_nik, pengirim FROM pesan WHERE id = :id");
    $q->execute([':id' => $id]);
    $own = $q->fetch(PDO::FETCH_ASSOC);

    $bolehEdit = false;
    if (!empty($own['pengirim_nik']) && $user_nik !== '') {
        $bolehEdit = ($own['pengirim_nik'] === $user_nik);
    } else {
        $bolehEdit = ($own['pengirim'] === $user_nama || $own['pengirim'] === $user_status);
    }

    if (!$bolehEdit) {
        http_response_code(403);
        echo "Gagal edit pesan: bukan milik Anda";
        exit;
    }

    // Lanjut update
    $stmt = $pdo->prepare("
        UPDATE pesan
        SET pesan = :pesan_baru
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':pesan_baru' => $pesan_baru,
        ':id'         => $id
    ]);

    if ($stmt->rowCount() > 0) {
        echo "Pesan berhasil diedit";
    } else {
        echo "Gagal edit pesan: tidak ada perubahan isi";
    }
}
