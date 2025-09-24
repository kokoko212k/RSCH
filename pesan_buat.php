<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Ambil data dari form
    $no_surat = trim($_POST['no_surat'] ?? '');
    $pesan    = trim($_POST['pesan'] ?? '');
    $toType   = $_POST['to_type'] ?? 'status'; // 'status' | 'nama'

    // Ambil user dari session (pengirim)
    $user       = $_SESSION['user'] ?? [];
    $me_nik     = $user['nik']    ?? null;
    $me_nama    = $user['nama']   ?? '';
    $me_status  = $user['status'] ?? '';

    if ($no_surat === '' || $pesan === '' || !$me_nik) {
        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=error&msg=Data%20tidak%20lengkap");
        exit;
    }

    // Kumpulkan daftar penerima NIK
    $recipients = [];
    if ($toType === 'status') {
        $toStatus = trim($_POST['to_status'] ?? '');
        if ($toStatus !== '') {
            $q = $pdo->prepare("SELECT nik FROM users WHERE status = ?");
            $q->execute([$toStatus]);
            $recipients = $q->fetchAll(PDO::FETCH_COLUMN);
        }
    } else { // toType === 'nama'
        $recipients = array_filter((array)($_POST['to_nama'] ?? []));
    }

    $recipients = array_values(array_unique(array_filter($recipients)));
    $recipients = array_values(array_filter($recipients, fn($nik) => $nik !== $me_nik));    

    if (empty($recipients)) {
        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=error&msg=Tidak%20ada%20penerima");
        exit;
    }

    try {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        $pdo->beginTransaction();

        // Statement insert 1 baris per penerima
        $ins = $pdo->prepare("
            INSERT INTO pesan
              (no_surat,
               pengirim, pengirim_nik, pengirim_nama,
               penerima, penerima_nik, penerima_nama,
               pesan, waktu)
            SELECT :no_surat,
                   :pengirim_label, :pengirim_nik, :pengirim_nama,
                   COALESCE(u.status, '—'), u.nik, COALESCE(u.nama, ''),
                   :pesan, NOW()
            FROM users u
            WHERE u.nik = :nik
        ");

        foreach ($recipients as $nik) {
            $ins->execute([
                ':no_surat'       => $no_surat,
                ':pengirim_label' => ($me_status ?: $me_nama), // legacy label
                ':pengirim_nik'   => $me_nik,
                ':pengirim_nama'  => $me_nama,
                ':pesan'          => $pesan,
                ':nik'            => $nik
            ]);
        }

        $pdo->commit();

        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=success");
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=error&msg=" . urlencode($e->getMessage()));
        exit;
    }
}


