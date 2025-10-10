<?php
session_start();
include 'config.php';

// ====== Wajib login ======
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user         = $_SESSION['user'] ?? [];
$user_nik     = trim((string)($user['nik']    ?? ''));
$user_nama    = trim((string)($user['nama']   ?? ''));
$user_status  = trim((string)($user['status'] ?? ''));
$user_unit    = trim((string)($user['unit']   ?? ''));

// Deteksi AJAX
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Pastikan PDO lempar exception
if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo $isAjax ? json_encode(['ok'=>false,'error'=>'Method not allowed']) : 'Method not allowed';
    exit;
}

$id         = (int)($_POST['id_pesan'] ?? 0);
$pesan_baru = trim((string)($_POST['pesan_baru'] ?? ''));

// Validasi dasar
if ($id <= 0 || $pesan_baru === '' || ($user_nik === '' && $user_unit === '' && $user_nama === '' && $user_status === '')) {
    http_response_code(400);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Data tidak lengkap']);
    } else {
        echo 'Data tidak lengkap';
    }
    exit;
}

try {
    // Ambil pesan lama untuk cek kepemilikan dan banding isi
    $q = $pdo->prepare("
        SELECT id, no_surat, pengirim_nik, pengirim_unit, pengirim_nama, pesan
        FROM pesan
        WHERE id = :id
        LIMIT 1
    ");
    $q->execute([':id' => $id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'Pesan tidak ditemukan']);
        } else {
            echo 'Pesan tidak ditemukan';
        }
        exit;
    }

    // Cek kepemilikan: utamakan NIK; fallback unit; lalu nama/status lama (kompatibilitas)
    $bolehEdit = false;
    if (!empty($row['pengirim_nik']) && $user_nik !== '') {
        $bolehEdit = ($row['pengirim_nik'] === $user_nik);
    } elseif (!empty($row['pengirim_unit']) && $user_unit !== '') {
        $bolehEdit = (trim((string)$row['pengirim_unit']) === $user_unit);
    } else {
        // kompat lama: pengirim_nama bisa berisi nama atau status
        $bolehEdit = ($row['pengirim_nama'] === $user_nama || $row['pengirim_nama'] === $user_status);
    }

    if (!$bolehEdit) {
        http_response_code(403);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'Gagal edit pesan: bukan milik Anda']);
        } else {
            echo 'Gagal edit pesan: bukan milik Anda';
        }
        exit;
    }

    // Jika isinya sama, tidak perlu update
    if (trim((string)$row['pesan']) === $pesan_baru) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'Tidak ada perubahan isi']);
        } else {
            echo 'Gagal edit pesan: tidak ada perubahan isi';
        }
        exit;
    }

    // Update isi pesan (jangan sentuh kolom waktu agar urutan tidak berubah)
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
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'message'=>'Pesan berhasil diedit','id'=>$id]);
        } else {
            echo 'Pesan berhasil diedit';
        }
    } else {
        // Barangkali DB melihat sama2 whitespace; sudah ditangani di atas, tapi berjaga-jaga
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'Tidak ada perubahan yang disimpan']);
        } else {
            echo 'Gagal edit pesan: tidak ada perubahan isi';
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Kesalahan server: '.$e->getMessage()]);
    } else {
        echo 'Kesalahan server: '.$e->getMessage();
    }
}
