<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $no_surat = trim($_POST['no_surat'] ?? '');
    $pesan    = trim($_POST['pesan'] ?? '');

    $user       = $_SESSION['user'] ?? [];
    $me_nik     = $user['nik']    ?? null;
    $me_nama    = $user['nama']   ?? '';
    $me_status  = $user['status'] ?? '';
    $me_unit    = $user['unit']   ?? '';

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($no_surat === '' || $pesan === '' || !$me_nik) {
        if ($isAjax) { header('Content-Type: application/json'); http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'Data tidak lengkap']); exit; }
        header("Location: pesan_lihat_4.php?no_surat=" . urlencode($no_surat) . "&status=error&msg=Data%20tidak%20lengkap");
        exit;
    }

    try {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        // 1) Coba ambil disposisi_kepada dari surat_masuk (BOLEH KOSONG)
        $qPref = $pdo->prepare("
            SELECT ditujukan_kepada
            FROM surat_keluar
            WHERE no_surat = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $qPref->execute([$no_surat]);
        $disposisi = trim((string)$qPref->fetchColumn()); // <= tidak di-throw kalau kosong

        // 2) Pecah jadi list unit (kalau ada)
        $targets = [];
        if ($disposisi !== '') {
            $rawUnits = preg_split('/[;,\/]+/u', $disposisi);
            foreach ($rawUnits as $s) {
                $s = trim($s);
                if ($s !== '') $targets[] = mb_strtoupper($s, 'UTF-8');
            }
            $targets = array_values(array_unique($targets));
        }

        // 3) Ambil user penerima berdasarkan unit (kalau targets ada)
        $recipients = [];
        if (!empty($targets)) {
            $placeholders = implode(',', array_fill(0, count($targets), '?'));
            $sqlUsers = "SELECT nik, nama, unit FROM users WHERE UPPER(TRIM(unit)) IN ($placeholders)";
            $qUsers = $pdo->prepare($sqlUsers);
            $qUsers->execute($targets);
            $userRows = $qUsers->fetchAll(PDO::FETCH_ASSOC);

            foreach ($userRows as $row) {
                if (!empty($row['nik']) && $row['nik'] !== $me_nik) {
                    $recipients[] = $row['nik'];
                }
            }
        }

        $recipients = array_values(array_unique($recipients));

        // 4) Fallback WAJIB: jika tidak ada penerima, kirim minimal ke diri sendiri
        if (!$recipients) {
            $recipients = [$me_nik];
        }

        // 5) Idempotensi + INSERT
        $pdo->beginTransaction();
        $idemStmt = $pdo->prepare("
            SELECT COUNT(*) FROM pesan
            WHERE no_surat=? AND pengirim_nik=? AND pesan=? AND waktu >= (NOW() - INTERVAL 5 SECOND)
        ");
        $idemStmt->execute([$no_surat, $me_nik, $pesan]);
        if ((int)$idemStmt->fetchColumn() > 0) {
            $pdo->rollBack();
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'inserted'=>0,'dedup'=>true]); exit; }
            header("Location: pesan_lihat_4.php?no_surat=" . urlencode($no_surat) . "&status=success");
            exit;
        }

        $ins = $pdo->prepare("
            INSERT INTO pesan
              (no_surat,
               pengirim_unit, pengirim_nik, pengirim_nama,
               penerima_unit, penerima_nik, penerima_nama,
               pesan, waktu)
            SELECT :no_surat,
                   :pengirim_unit, :pengirim_nik, :pengirim_nama,
                   COALESCE(u.unit, COALESCE(u.status,'—')), u.nik, COALESCE(u.nama, ''),
                   :pesan, NOW()
            FROM users u
            WHERE u.nik = :nik
        ");

        $total = 0;
        foreach ($recipients as $nik) {
            $ins->execute([
                ':no_surat'       => $no_surat,
                ':pengirim_unit'  => ($me_unit ?: $me_status ?: $me_nama),
                ':pengirim_nik'   => $me_nik,
                ':pengirim_nama'  => $me_nama,
                ':pesan'        => $pesan,
                ':nik'            => $nik
            ]);
            error_log("insert msg2 ns=$no_surat by=$me_nik to=$nik rc=".$ins->rowCount());         
            $total += $ins->rowCount();
        }

        if ($total === 0) { $pdo->rollBack(); throw new RuntimeException('Tidak ada penerima valid'); }
        $pdo->commit();

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'inserted'=>$total]); exit; }
        header("Location: pesan_lihat_4.php?no_surat=" . urlencode($no_surat) . "&status=success");
        exit;

    } catch (Throwable $e) {
        error_log("ERR msg2 ns=$no_surat by=$me_nik : ".$e->getMessage());
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($isAjax) { header('Content-Type: application/json', true, 400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
        header("Location: pesan_lihat_4.php?no_surat=" . urlencode($no_surat) . "&status=error&msg=" . urlencode($e->getMessage()));
        exit;
    }
}
