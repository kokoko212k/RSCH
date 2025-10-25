<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $no_surat = trim($_POST['no_surat'] ?? '');
    $file_url = trim($_POST['file_url'] ?? '');          // <— baru (opsional)
    $pesan    = trim($_POST['pesan'] ?? '');

    $user       = $_SESSION['user'] ?? [];
    $me_nik     = $user['nik']    ?? null;
    $me_nama    = $user['nama']   ?? '';
    $me_status  = $user['status'] ?? '';
    $me_unit    = $user['unit']   ?? '';

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // --- helper aman respon error ---
    $fail = function(string $msg) use ($isAjax, $no_surat) {
        if ($isAjax) {
            header('Content-Type: application/json'); http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>$msg]); exit;
        }
        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=error&msg=" . urlencode($msg));
        exit;
    };

    if ($pesan === '' || !$me_nik) {
        $fail('Data tidak lengkap');
    }

    // === (1) Jika no_surat kosong tapi ada file_url → coba resolve ===
    if ($no_surat === '' && $file_url !== '') {
        // normalisasi dua bentuk: 'uploads/abc.pdf' dan hanya 'abc.pdf'
        $file_raw  = $file_url;
        $file_base = basename($file_url);

        $candidates = [];

        // urutan pencarian no_surat dari berbagai tabel
        $lookups = [
            // tabel -> [kolom_nosurat, kolom_file]
            ['surat_disposisi',                 'no_surat', 'file_url'],
            ['surat_masuk',                     'no_surat', 'file_url'],
            ['surat_keluar',                    'no_surat', 'file_url'],
            ['surat_pengajuan',                 'no_surat', 'file_url'],
            ['surat_notif',                     'no_surat', 'file_url'],
            ['surat_disposisi_tindak_lanjut',   'no_surat', 'file_url'],
        ];

        foreach ($lookups as [$table,$colNo,$colFile]) {
            $q = $pdo->prepare("
                SELECT {$colNo} FROM {$table}
                WHERE {$colFile} = :raw OR {$colFile} = :base
                ORDER BY 1 DESC LIMIT 1
            ");
            $q->execute([':raw'=>$file_raw, ':base'=>$file_base]);
            $v = trim((string)$q->fetchColumn());
            if ($v !== '') { $candidates[] = $v; }
        }

        // pilih kandidat pertama yang ketemu
        if ($candidates) {
            $no_surat = $candidates[0];
        }
    }

    if ($no_surat === '') {
        $fail('Nomor surat tidak dapat ditentukan');
    }

    function resolve_recipients_all(PDO $pdo, string $no_surat, string $me_nik): array {
    $rec = [];

    // helper lokal
    $addUnits = function(array $units) use ($pdo){ return users_by_units($pdo, parse_units(implode(';',$units))); };
    $uniq = function(array $arr, string $me){ return array_values(array_unique(array_filter($arr, fn($n)=>$n && $n!==$me))); };

    // ===== Disposisi Surat → bedakan instruksi
    $q = $pdo->prepare("SELECT instruksi, ditujukan_kepada
                        FROM surat_disposisi
                        WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $q->execute([$no_surat]);
    if ($d = $q->fetch(PDO::FETCH_ASSOC)) {
        $instruksi = mb_strtoupper(trim((string)$d['instruksi']));
        $dituju   = (string)($d['ditujukan_kepada'] ?? '');

        if ($instruksi !== '') {
        if (strpos($instruksi, 'DITERIMA') === 0) {
            // Pesan2: ke unit ditujukan_kepada
            if ($dituju !== '') $rec = array_merge($rec, $addUnits([$dituju]));
        } elseif (strpos($instruksi, 'DITERUSKAN LANGSUNG') === 0) {
            // Pesan5: target langsung (ambil dari surat_notif / target langsung)
            $qn = $pdo->prepare("SELECT target_nik, target_unit FROM surat_notif
                                WHERE no_surat=? ORDER BY id DESC LIMIT 1");
            $qn->execute([$no_surat]);
            if ($n = $qn->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($n['target_nik']) && $n['target_nik'] !== $me_nik) $rec[] = $n['target_nik'];
            if (!empty($n['target_unit'])) $rec = array_merge($rec, $addUnits([$n['target_unit']]));
            }
            // fallback bila tak ada notif → minimal ke unit ditujukan_kepada
            if (!$rec && $dituju !== '') $rec = array_merge($rec, $addUnits([$dituju]));
        } elseif (strpos($instruksi, 'DITERUSKAN') === 0) {
            // Pesan3: ambil dari tindak lanjut (disposisi_kepada)
            $qt = $pdo->prepare("SELECT disposisi_kepada
                                FROM surat_disposisi_tindak_lanjut
                                WHERE no_surat=? ORDER BY id_tindaklanjut DESC LIMIT 1");
            $qt->execute([$no_surat]);
            $to = trim((string)$qt->fetchColumn());
            if ($to !== '') $rec = array_merge($rec, $addUnits([$to]));
            // fallback → unit tujuan awal
            if (!$rec && $dituju !== '') $rec = array_merge($rec, $addUnits([$dituju]));
        }
        }
    }

    // ===== Tindak Lanjut Disposisi → Pesan3 (unit TL), Pesan4 (pengusul)
    $qt = $pdo->prepare("SELECT disposisi_kepada FROM surat_disposisi_tindak_lanjut
                        WHERE no_surat=? ORDER BY id_tindaklanjut DESC LIMIT 1");
    $qt->execute([$no_surat]);
    $toTl = trim((string)$qt->fetchColumn());
    if ($toTl !== '') {
        // Pesan3
        $rec = array_merge($rec, $addUnits([$toTl]));
    }
    // Pesan4 (pengusul/pembuat pengajuan terkait surat ini)
    $qp = $pdo->prepare("SELECT pengajuan_nik, pengajuan_unit
                        FROM surat_pengajuan
                        WHERE no_surat=? OR file_url=? OR file_url=?
                        ORDER BY id DESC LIMIT 1");
    // coba temukan file dari disposisi bila ada
    $fileRow = $pdo->prepare("SELECT file_url FROM surat_disposisi WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $fileRow->execute([$no_surat]);
    $fileUrl = trim((string)$fileRow->fetchColumn());
    $qp->execute([$no_surat, $fileUrl, basename($fileUrl)]);
    if ($p = $qp->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($p['pengajuan_nik']) && $p['pengajuan_nik'] !== $me_nik) $rec[] = $p['pengajuan_nik']; // Pesan4
    }

    // ===== Pengajuan Disposisi → Pesan1 (Sekretariat) + owner pengajuan (jika ada)
    $qd = $pdo->prepare("SELECT file_url FROM surat_disposisi_pengajuan
                        WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    if ($qd->execute([$no_surat]) && ($dp = $qd->fetch(PDO::FETCH_ASSOC))) {
        // Sekretariat (Pesan1)
        $rec = array_merge($rec, users_by_status($pdo, ['Sekretariat']));
        // owner
        $qp2 = $pdo->prepare("SELECT pengajuan_nik, pengajuan_unit
                            FROM surat_pengajuan
                            WHERE no_surat=? OR file_url=? OR file_url=?
                            ORDER BY id DESC LIMIT 1");
        $qp2->execute([$no_surat, (string)$dp['file_url'], basename((string)$dp['file_url'])]);
        if ($p2 = $qp2->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($p2['pengajuan_nik']) && $p2['pengajuan_nik'] !== $me_nik) $rec[] = $p2['pengajuan_nik']; // ikutkan owner
        }
    }

    // ===== Notifikasi Surat → Pesan1, Pesan4, Pesan5
    $qn = $pdo->prepare("SELECT target_nik, target_unit, tipe
                        FROM surat_notif
                        WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $qn->execute([$no_surat]);
    if ($n = $qn->fetch(PDO::FETCH_ASSOC)) {
        // Pesan1 → Sekretariat (umum)
        $rec = array_merge($rec, users_by_status($pdo, ['Sekretariat']));
        // Pesan4 → pengusul (bila ada kaitan pengajuan)
        if (!empty($p['pengajuan_nik']) && $p['pengajuan_nik'] !== $me_nik) $rec[] = $p['pengajuan_nik'];
        // Pesan5 → target langsung
        if (!empty($n['target_nik']) && $n['target_nik'] !== $me_nik) $rec[] = $n['target_nik'];
        if (!empty($n['target_unit'])) $rec = array_merge($rec, $addUnits([$n['target_unit']]));
    }

    // ===== Pengajuan Surat → (owner + unit pengajuan + Sekretariat)  ~ Pesan1 + Pesan4
    $q = $pdo->prepare("SELECT pengajuan_nik, pengajuan_unit
                        FROM surat_pengajuan WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $q->execute([$no_surat]);
    if ($pg = $q->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($pg['pengajuan_nik']) && $pg['pengajuan_nik'] !== $me_nik) $rec[] = $pg['pengajuan_nik'];  // Pesan4
        if (!empty($pg['pengajuan_unit'])) $rec = array_merge($rec, $addUnits([$pg['pengajuan_unit']]));
        $rec = array_merge($rec, users_by_status($pdo, ['Sekretariat'])); // Pesan1
    }

    // ===== Surat Masuk / Surat Keluar → Pesan2 (unit tujuan)
    // (cari kolom tujuan; kalau tak ada, fallback ke ditujukan_kepada di disposisi)
    $sm = $pdo->prepare("SELECT ditujukan_kepada FROM surat_masuk WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $sm->execute([$no_surat]);
    $tujuanSm = trim((string)$sm->fetchColumn());
    if ($tujuanSm !== '') $rec = array_merge($rec, $addUnits([$tujuanSm]));

    $sk = $pdo->prepare("SELECT ditujukan_kepada FROM surat_keluar WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $sk->execute([$no_surat]);
    $tujuanSk = trim((string)$sk->fetchColumn());
    if ($tujuanSk !== '') $rec = array_merge($rec, $addUnits([$tujuanSk]));

    // ===== Partisipan chat yang sudah ada (agar nyambung)
    $q = $pdo->prepare("SELECT DISTINCT nik FROM (
                            SELECT pengirim_nik AS nik FROM pesan WHERE no_surat = :no
                            UNION
                            SELECT penerima_nik     FROM pesan WHERE no_surat = :no
                        ) x
                        WHERE nik IS NOT NULL AND nik <> :me");
    $q->execute([':no'=>$no_surat, ':me'=>$me_nik]);
    $rec = array_merge($rec, array_filter($q->fetchAll(PDO::FETCH_COLUMN,0)));

    // ===== Fallback umum
    if (!$rec) {
        $rec = array_merge($rec, users_by_status($pdo, ['Sekretariat']));
    }

    return $uniq($rec, $me_nik);
    }


    // --- Helpers kecil ---
    function parse_units(string $s): array {
    $a = preg_split('/[;,\/]+/u', $s);
    $out=[]; foreach ($a as $x){ $x=trim($x); if($x!=='') $out[]=mb_strtoupper($x,'UTF-8'); }
    return array_values(array_unique($out));
    }
    function users_by_units(PDO $pdo, array $units): array {
    if(!$units) return [];
    $ph = implode(',', array_fill(0, count($units), '?'));
    $q  = $pdo->prepare("SELECT nik FROM users WHERE UPPER(TRIM(unit)) IN ($ph)");
    $q->execute($units);
    return array_values(array_unique(array_filter($q->fetchAll(PDO::FETCH_COLUMN,0))));
    }
    function users_by_status(PDO $pdo, array $statuses): array {
    if(!$statuses) return [];
    $ph = implode(',', array_fill(0, count($statuses), '?'));
    $q  = $pdo->prepare("SELECT nik FROM users WHERE status IN ($ph)");
    $q->execute($statuses);
    return array_values(array_unique(array_filter($q->fetchAll(PDO::FETCH_COLUMN,0))));
    }

    /**
     * Resolver penerima lintas jenis surat sesuai alur.
     */
    function resolve_recipients_all(PDO $pdo, string $no_surat, string $me_nik): array {
    $rec = [];

    // 1) Tindak Lanjut → disposisi_kepada (Pesan3)
    $q = $pdo->prepare("SELECT disposisi_kepada FROM surat_disposisi_tindak_lanjut
                        WHERE no_surat=? ORDER BY id_tindaklanjut DESC LIMIT 1");
    $q->execute([$no_surat]);
    $v = trim((string)$q->fetchColumn());
    if ($v !== '') $rec = array_merge($rec, users_by_units($pdo, parse_units($v)));

    // 2) Disposisi Surat → ditujukan_kepada (Pesan2), plus ‘diteruskan langsung’ (Pesan5)
    $q = $pdo->prepare("SELECT instruksi, ditujukan_kepada, file_url
                        FROM surat_disposisi WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $q->execute([$no_surat]);
    if ($d = $q->fetch(PDO::FETCH_ASSOC)) {
        $instr = mb_strtoupper(trim((string)$d['instruksi']));
        $tuju  = trim((string)$d['ditujukan_kepada']);
        if ($tuju !== '') $rec = array_merge($rec, users_by_units($pdo, parse_units($tuju)));

        if (strpos($instr,'DITERUSKAN LANGSUNG') === 0) {
        $qn = $pdo->prepare("SELECT target_nik, target_unit FROM surat_notif
                            WHERE no_surat=? ORDER BY id DESC LIMIT 1");
        $qn->execute([$no_surat]);
        if ($n = $qn->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($n['target_nik']) && $n['target_nik'] !== $me_nik) $rec[] = $n['target_nik'];
            if (!empty($n['target_unit'])) $rec = array_merge($rec, users_by_units($pdo, parse_units((string)$n['target_unit'])));
        }
        }
    }

    // 3) Pengajuan Disposisi → Sekretariat + owner pengajuan (Pesan1)
    $q = $pdo->prepare("SELECT file_url FROM surat_disposisi_pengajuan
                        WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    if ($q->execute([$no_surat]) && ($dp = $q->fetch(PDO::FETCH_ASSOC))) {
        $rec = array_merge($rec, users_by_status($pdo, ['Sekretariat']));
        $qp = $pdo->prepare("SELECT pengajuan_nik, pengajuan_unit FROM surat_pengajuan
                            WHERE no_surat=? OR file_url=? OR file_url=? ORDER BY id DESC LIMIT 1");
        $qp->execute([$no_surat, (string)$dp['file_url'], basename((string)$dp['file_url'])]);
        if ($p = $qp->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($p['pengajuan_nik']) && $p['pengajuan_nik'] !== $me_nik) $rec[] = $p['pengajuan_nik'];
        if (!empty($p['pengajuan_unit'])) $rec = array_merge($rec, users_by_units(
            $pdo, [mb_strtoupper(trim((string)$p['pengajuan_unit']), 'UTF-8')]
        ));
        }
    }

    // 4) Pengajuan Surat → owner + unit pengajuan + Sekretariat (Pesan1 & Pesan4)
    $q = $pdo->prepare("SELECT pengajuan_nik, pengajuan_unit FROM surat_pengajuan
                        WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $q->execute([$no_surat]);
    if ($p = $q->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($p['pengajuan_nik']) && $p['pengajuan_nik'] !== $me_nik) $rec[] = $p['pengajuan_nik'];
        if (!empty($p['pengajuan_unit'])) $rec = array_merge($rec, users_by_units(
        $pdo, [mb_strtoupper(trim((string)$p['pengajuan_unit']), 'UTF-8')]
        ));
        $rec = array_merge($rec, users_by_status($pdo, ['Sekretariat']));
    }

    // 5) Notifikasi Surat → target_nik / target_unit (Pesan5),
    //    plus Sekretariat (Pesan1), plus owner jika ada relasi pengajuan (Pesan4)
    $q = $pdo->prepare("SELECT target_nik, target_unit FROM surat_notif
                        WHERE no_surat=? ORDER BY id DESC LIMIT 1");
    $q->execute([$no_surat]);
    if ($n = $q->fetch(PDO::FETCH_ASSOC)) {
        $rec = array_merge($rec, users_by_status($pdo, ['Sekretariat']));
        if (!empty($n['target_nik']) && $n['target_nik'] !== $me_nik) $rec[] = $n['target_nik'];
        if (!empty($n['target_unit'])) $rec = array_merge($rec, users_by_units($pdo, parse_units((string)$n['target_unit'])));
    }

    // 6) Partisipan chat yang sudah ada
    $q = $pdo->prepare("SELECT DISTINCT nik FROM (
                            SELECT pengirim_nik AS nik FROM pesan WHERE no_surat=:no
                            UNION
                            SELECT penerima_nik     FROM pesan WHERE no_surat=:no
                        ) x WHERE nik IS NOT NULL AND nik<>:me");
    $q->execute([':no'=>$no_surat, ':me'=>$me_nik]);
    $rec = array_merge($rec, array_filter($q->fetchAll(PDO::FETCH_COLUMN,0)));

    // 7) Fallback → Sekretariat (+ opsional Direksi/SA)
    if (!$rec) {
        $rec = array_merge($rec, users_by_status($pdo, ['Sekretariat','Direktur','Super Admin']));
    }

    return array_values(array_unique(array_filter($rec, fn($n)=>$n && $n!==$me_nik)));
    }


    try {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        // === (2) Tentukan target penerima ===
        // ganti seluruh logika lama dengan ini:
        $recipients = resolve_recipients_all($pdo, $no_surat, $me_nik);

        // jika tetap kosong, minimal echo ke diri sendiri agar chat terlihat
        if (!$recipients) { $recipients = [$me_nik]; }
        if (!$recipients) {
            // masih juga kosong → stop biar tidak “mengudara” ke semua orang
            throw new RuntimeException('Tidak ada penerima untuk surat ini');
        }

        // === (3) Idempotensi ringan & insert ===
        $pdo->beginTransaction();

        $idemStmt = $pdo->prepare("
            SELECT COUNT(*) FROM pesan
            WHERE no_surat=? AND pengirim_nik=? AND pesan=? AND waktu >= (NOW() - INTERVAL 5 SECOND)
        ");
        $idemStmt->execute([$no_surat, $me_nik, $pesan]);
        if ((int)$idemStmt->fetchColumn() > 0) {
            $pdo->rollBack();
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'inserted'=>0,'dedup'=>true]); exit; }
            header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=success");
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
                ':pesan'          => $pesan,
                ':nik'            => $nik
            ]);
            $total += $ins->rowCount();
        }

        if ($total === 0) {
            $pdo->rollBack();
            throw new RuntimeException('Gagal mengirim pesan (penerima tidak valid)');
        }

        $pdo->commit();

        if ($isAjax) {
            header('Content-Type: application/json'); echo json_encode(['ok'=>true,'inserted'=>$total]); exit;
        }
        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=success");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($isAjax) {
            header('Content-Type: application/json', true, 400);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
        }
        header("Location: pesan_lihat.php?id=" . urlencode($no_surat) . "&status=error&msg=" . urlencode($e->getMessage()));
        exit;
    }
}
