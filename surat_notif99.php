<?php
session_start();

/**
 * Buat notifikasi saat ada baris baru di surat_notif.
 * - Penerima:
 *   • Semua 'Super Admin'
 *   • Sekretariat dengan unit = unit surat (dari surat_pengajuan)
 *   • Pemilik surat (berdasar pengajuan_nik → users.nik)
 * - Unik per baris surat_notif via notifikasi.event_key = "surat_notif:{id}"
 */
function buat_notif_dari_surat_notif(PDO $pdo, int $suratNotifId, ?string $ownerNik=null, ?string $unit=null): void
{
    $q = $pdo->prepare("SELECT id, no_surat FROM surat_notif WHERE id=? LIMIT 1");
    $q->execute([$suratNotifId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return;

    $no_surat  = (string)($r['no_surat'] ?? '');
    $title     = 'Surat baru/diteruskan';
    $body      = $no_surat !== '' ? ('No. Surat: ' . $no_surat) : null;
    $actionUrl = $no_surat !== '' ? ('surat_notif.php?no_surat=' . rawurlencode($no_surat)) : 'surat_notif.php';
    $eventKey  = 'surat_notif:' . $suratNotifId;

    $insNotif = $pdo->prepare("
        INSERT INTO notifikasi (title, body, action_url, type, event_key, created_by)
        VALUES (:title, :body, :url, 'surat_notif', :ek, NULL)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
    ");
    $insNotif->execute([':title'=>$title, ':body'=>$body, ':url'=>$actionUrl, ':ek'=>$eventKey]);
    $notifId = (int)$pdo->lastInsertId();
    if ($notifId <= 0) return;

    $userIds = [];

    // Super Admin
    $st = $pdo->query("SELECT id FROM users WHERE status='Super Admin'");
    $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    // Sekretariat per-unit (kalau ada unit yang diketahui)
    if ($unit) {
        $st = $pdo->prepare("SELECT id FROM users WHERE status='Sekretariat' AND unit=?");
        $st->execute([$unit]);
        $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    // Pemilik (kalau ada owner NIK yang diketahui)
    if ($ownerNik) {
        $st = $pdo->prepare("SELECT id FROM users WHERE nik=?");
        $st->execute([$ownerNik]);
        $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    $userIds = array_values(array_unique(array_filter($userIds, fn($v)=>$v>0)));
    if (!$userIds) return;

    $insT = $pdo->prepare("INSERT IGNORE INTO notifikasi_targets (notifikasi_id, user_id, status, unit) VALUES (?, ?, NULL, ?)");
    foreach ($userIds as $uid) {
        $insT->execute([$notifId, $uid, ($unit ?: null)]);
        $pdo->prepare("INSERT IGNORE INTO detail_notifikasi (notifikasi_id, user_id, read_at) VALUES (?, ?, NULL)")
            ->execute([$notifId, $uid]);
    }
}

require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Cek login
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user        = $_SESSION['user'];
$loginUserId = (int)($user['id'] ?? 0);
$role        = $user['status'] ?? null;
$nik         = $user['nik']   ?? null;
$unit        = $user['unit']  ?? null;

// (opsional) scopeSql disimpan bila dibutuhkan di tempat lain
$scopeSql    = '1=0';
$scopeParams = [];
switch ($role) {
  case 'Super Admin':
    $scopeSql = '1=1';
    break;
  case 'Sekretariat':
    $scopeSql    = 'sp.pengajuan_unit = ?';
    $scopeParams = [$unit];
    break;
  default:
    $scopeSql    = 'sp.pengajuan_nik = ?';
    $scopeParams = [$nik];
    break;
}

$eofficeAll = [
  'surat_masuk.php'                   => 'Surat Masuk',
  'surat_keluar.php'                  => 'Surat Keluar',
  'surat_disposisi_pengajuan.php'     => 'Disposisi Pengajuan',
  'surat_disposisi.php'               => 'Disposisi Surat',
  'surat_disposisi_tindak_lanjut.php' => 'Disposisi Tindak Lanjut',
  'surat_notif.php'                   => 'Surat Notif',
  'surat_pengajuan.php'               => 'Pengajuan',
];

$rolePages = [
  'Super Admin' => array_keys($eofficeAll),
  'Sekretariat' => [
    'surat_masuk.php',
    'surat_keluar.php',
    'surat_disposisi_pengajuan.php',
  ],
  'Direktur' => [
    'surat_disposisi.php',
    'surat_notif.php',
  ],
  'Admin' => [
    'surat_notif.php',
    'surat_pengajuan.php',
  ],
  'Member' => [
    'surat_notif.php',
    'surat_pengajuan.php',
  ],
];

$allowedEofficePages = $rolePages[$role] ?? [];
$can_access_eoffice  = !empty($allowedEofficePages);
$pengirim            = $user['nama'] ?? '';

// ================== SYNC "DITERUSKAN" → surat_notif ==================
try {
    // DITERUSKAN LANGSUNG
    $q = $pdo->prepare("
        SELECT tanggal, no_surat, file_url
        FROM surat_disposisi
        WHERE LOWER(instruksi) = 'diteruskan langsung'
    ");
    $q->execute();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stored = trim((string)$r['file_url']);
        if ($stored !== '' && strpos($stored, '/') === false) $stored = 'uploads/' . $stored;

        $cek = $pdo->prepare("SELECT COUNT(*) FROM surat_notif WHERE no_surat=? AND file_url=?");
        $cek->execute([$r['no_surat'], $stored]);
        if (!$cek->fetchColumn()) {
            $ins = $pdo->prepare("
                INSERT INTO surat_notif (tanggal, no_surat, file_url, waktu)
                VALUES (?, ?, ?, NOW())
            ");
            $ins->execute([$r['tanggal'], $r['no_surat'], $stored]);
            $baruId = (int)$pdo->lastInsertId();
            if ($baruId) buat_notif_dari_surat_notif($pdo, $baruId);
        }
    }

    // DITERUSKAN (dari surat_keluar)
    $q2 = $pdo->prepare("
        SELECT d.tanggal,
               d.no_surat,
               COALESCE(NULLIF(d.file_url,''), sk.file_url) AS file_url
        FROM surat_disposisi d
        JOIN surat_keluar sk
          ON sk.no_surat = d.no_surat
         AND (
              d.ditujukan_kepada IS NULL
              OR d.ditujukan_kepada = ''
              OR sk.ditujukan_kepada = d.ditujukan_kepada
         )
        WHERE LOWER(d.instruksi) = 'diteruskan'
    ");
    $q2->execute();
    foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stored = trim((string)$r['file_url']);
        if ($stored !== '' && strpos($stored, '/') === false) $stored = 'uploads/' . $stored;

        $cek = $pdo->prepare("SELECT COUNT(*) FROM surat_notif WHERE no_surat=? AND file_url=?");
        $cek->execute([$r['no_surat'], $stored]);
        if (!$cek->fetchColumn()) {
            $ins = $pdo->prepare("
                INSERT INTO surat_notif (tanggal, no_surat, file_url, waktu)
                VALUES (?, ?, ?, NOW())
            ");
            $ins->execute([$r['tanggal'], $r['no_surat'], $stored]);

            // (opsional) bila ingin sekaligus membuat notifikasi juga:
            // $baruId = (int)$pdo->lastInsertId();
            // if ($baruId) buat_notif_dari_surat_notif($pdo, $baruId);
        }
    }
} catch (Throwable $e) {
    // log bila perlu
}

// ================== HAPUS (hard) ==================
if (isset($_GET['delete'])) {
  $id = (int)$_GET['delete'];
  if ($id > 0) {
    if ($role === 'Super Admin') {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare("SELECT id FROM notifikasi WHERE event_key = CONCAT('surat_notif:', ?)");
      $stmt->execute([$id]);
      $notifId = (int)$stmt->fetchColumn();

      if ($notifId) {
        $pdo->prepare("DELETE FROM detail_notifikasi WHERE notifikasi_id=?")->execute([$notifId]);
        $pdo->prepare("DELETE FROM notifikasi_targets WHERE notifikasi_id=?")->execute([$notifId]);
        $pdo->prepare("DELETE FROM notifikasi WHERE id=?")->execute([$notifId]);
      }
      $pdo->prepare("DELETE FROM surat_notif WHERE id=?")->execute([$id]);
      $pdo->commit();
    } else {
      // hanya boleh hapus jika ada target untuk dirinya
      $pdo->prepare("
        DELETE n FROM surat_notif n
        JOIN notifikasi notif ON notif.event_key = CONCAT('surat_notif:', n.id)
        JOIN notifikasi_targets t ON t.notifikasi_id = notif.id AND t.user_id = ?
        WHERE n.id = ?
      ")->execute([$loginUserId, $id]);
    }
  }
  header('Location: surat_notif.php'); exit;
}

// ================== API (AJAX) ==================

// Insert notif
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'insert_notif') {
    $no_surat = trim($_POST['no_surat'] ?? '');
    $file_url = trim($_POST['file_url'] ?? '');
    $tanggal  = date('Y-m-d');

    if ($no_surat !== '') {
        $stmt = $pdo->prepare("
          INSERT INTO surat_notif (tanggal, no_surat, file_url, waktu)
          VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$tanggal, $no_surat, $file_url]);
        $baruId = (int)$pdo->lastInsertId();

        // kalau tahu pemilik/owner NIK & unit, kirimkan; kalau tidak, boleh null
        $ownerNik = null;
        $unitNot  = null;
        buat_notif_dari_surat_notif($pdo, $baruId, $ownerNik, $unitNot);
        echo "OK";
    } else {
        echo "ERROR: Data tidak lengkap";
    }
    exit;
}


// Ambil chat by no_surat (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['no_surat'])) {
    $no_surat = $_GET['no_surat'];
    $stmt = $pdo->prepare("SELECT * FROM pesan WHERE no_surat = ? ORDER BY waktu ASC");
    $stmt->execute([$no_surat]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Hapus chat milik sendiri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_chat') {
    $pesan_id = (int) ($_POST['hapus_pesan_id'] ?? 0);

    if ($pesan_id > 0 && $pengirim !== '') {
        $stmt = $pdo->prepare("DELETE FROM pesan WHERE id = ? AND pengirim = ?");
        $stmt->execute([$pesan_id, $pengirim]);
        echo $stmt->rowCount() ? "DELETED" : "ERROR: Tidak bisa hapus pesan orang lain";
    } else {
        echo "ERROR: Data tidak valid";
    }
    exit;
}

// ================== Ambil data Notif untuk tabel (dengan hak akses) ==================
$semuaNotif = [];
try {
  if ($role === 'Super Admin') {
    // SA: lihat SEMUA baris surat_notif, tidak tergantung notifikasi/notifikasi_targets
    $sql = "
      SELECT n.id, n.tanggal, n.no_surat, n.file_url, n.waktu,
             COALESCE(MAX(NULLIF(tl.disposisi_kepada,'')), '') AS disposisi_kepada
      FROM surat_notif n
      LEFT JOIN surat_disposisi_tindak_lanjut tl
             ON tl.no_surat = n.no_surat
      GROUP BY n.id, n.tanggal, n.no_surat, n.file_url, n.waktu
      ORDER BY n.waktu DESC, n.id DESC
    ";
    $stmt = $pdo->query($sql);
  } else {
    // Non-SA: hanya yang memang ditarget ke user login
    $sql = "
      SELECT n.id, n.tanggal, n.no_surat, n.file_url, n.waktu,
             COALESCE(MAX(NULLIF(tl.disposisi_kepada,'')), '') AS disposisi_kepada
      FROM surat_notif n
      JOIN notifikasi notif
        ON notif.event_key = CONCAT('surat_notif:', n.id)
      JOIN notifikasi_targets t
        ON t.notifikasi_id = notif.id AND t.user_id = :uid
      LEFT JOIN surat_disposisi_tindak_lanjut tl
        ON tl.no_surat = n.no_surat
      GROUP BY n.id, n.tanggal, n.no_surat, n.file_url, n.waktu
      ORDER BY n.waktu DESC, n.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $loginUserId]);
  }
  $semuaNotif = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $semuaNotif = [];
}

// Opsi filter
$opsiNoSurat  = array_values(array_unique(array_filter(array_column($semuaNotif, 'no_surat'))));
sort($opsiNoSurat, SORT_NATURAL | SORT_FLAG_CASE);

$opsiTanggal  = array_values(array_unique(array_filter(array_column($semuaNotif, 'tanggal'))));
rsort($opsiTanggal);

// helper URL file
function url_file($s) {
  $s = trim((string)$s);
  if ($s === '') return '';
  return (strpos($s, '/') === false) ? ('uploads/' . $s) : $s;
}

// hitung jumlah notif untuk user yang login (aman jika tidak ada data)
$jumlahNotif = 0;
try {
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifikasi notif
    JOIN notifikasi_targets t ON t.notifikasi_id = notif.id
    WHERE t.user_id = :uid
      AND (t.status IS NULL OR t.status = 'unread')
  ");
  $stmt->execute([':uid' => $loginUserId]);
  $jumlahNotif = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
  $jumlahNotif = 0;
}
// ==== Opsi kanal chat (fallback default) ====
$chatOptions = [
  ['label' => 'From : Super Admin', 'view' => 'pesan_lihat.php', 'post' => 'pesan_buat.php'],
  ['label' => 'From : Sekretariat', 'view' => 'pesan_lihat_2.php', 'post' => 'pesan_buat_2.php'],
  ['label' => 'From : Direktur', 'view' => 'pesan_lihat_3.php', 'post' => 'pesan_buat_3.php'],
  ['label' => 'From : Sekretariat', 'view' => 'pesan_lihat_4.php', 'post' => 'pesan_buat_4.php'],
];

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Ruang Baca Virtual</title> 
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
</head>
<style>
  @media print {
  .no-export {
    display: none;
  }
  }
    .kontainer-balok {
        background-color: #ffffff;
        padding: 30px;
        margin: 40px auto;
        border-radius: 10px;
        max-width: 1200px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }

    /* Judul di luar kontainer */
    .judul-surat-luar {
        text-align: center;
        padding-left: 30px;
        font-size: 24px;
        color: #333;
        margin-top: 20px;
        margin-bottom: 10px;
    }

    /* Header: judul dan tombol export */
    .balok-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .balok-header h2 {
        font-size: 24px;
        color: #333;
    }

    .btn-export {
        padding: 8px 16px;
        background-color: #17a2b8;
        color: white;
        border-radius: 5px;
        text-decoration: none;
    }

    .btn-export:hover {
        background-color: #138496;
    }

    /* Tombol Tambah */
    .balok-1 {
        margin: 20px 0;
        text-align: left;
    }

    .btn-tambah {
      padding: 11px 20px;
      background-color: #28a745;
      color: white;
      text-decoration: none;
      border-radius: 5px;
    }

    .btn-tambah:hover {
      background-color: #218838;
    }

    button.btn-tambah {
    padding:11px 20px;
    background-color: #28a745;
    color: rgb(0, 0, 0);
    font-size: 15px;
    text-decoration: none;
    border-radius: 5px;
    border-style: none;
    }

    /* Search Bar */
    .balok-2 {
        margin-bottom: 20px;
        display: flex;
        justify-content: flex-start;
    }

    .search-bar {
        display: flex;
        gap: 10px;
    }

    .search-bar input {
        padding: 10px;
        font-size: 14px;
        border-radius: 5px;
        border: 1px solid #ddd;
    }

    .search-bar button {
        padding: 10px;
        background-color: #007bff;
        color: white;
        border: none;
        border-radius: 5px;
    }

    .search-bar button:hover {
        background-color: #0056b3;
    }

    /* Tabel */
    .balok-3 {
        margin-top: 20px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background-color: #fff;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        border-radius: 8px;
        overflow: hidden;
    }

    table select {
    width: 20px; 
    font-size: 14px;
    padding: 1px;
    }


    th, td {
        padding: 10px 15px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }

    th {
        background-color: #007bff;
        color: #fff;
        font-size: 16px;
    }

    td {
        background-color: #f9f9f9;
    }

    td a {
        color: #007bff;
        text-decoration: none;
        font-weight: bold;
    }

    td a:hover {
        text-decoration: underline;
    }

  .form-group input[type="date"] {
  display: block;
  width: 20px;
  }
  
   .userMenu {
    transition: all 0.3s ease;
    }
  .kontainer-balok {
      background-color: #ffffff;
      padding: 30px;
      margin: 40px auto;
      border-radius: 10px;
      max-width: 1200px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
  }

  .balok-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
  }

  .balok-header h2 {
      font-size: 24px;
      color: #333;
  }

  .btn-export {
      padding: 8px 16px;
      background-color: #17a2b8;
      color: white;
      border-radius: 5px;
      text-decoration: none;
  }

  .btn-export:hover {
      background-color: #138496;
  }

  .balok-1 {
      margin: 20px 0;
      text-align: left;
  }

  .btn-tambah {
    padding: 11px 20px;
    background-color: #28a745;
    color: white;
    text-decoration: none;
    border-radius: 5px;
  }

  .btn-tambah:hover {
    background-color: #218838;
  }

  button.btn-tambah {
  padding:11px 20px;
  background-color: #28a745;
  color: rgb(0, 0, 0);
  font-size: 15px;
  text-decoration: none;
  border-radius: 5px;
  border-style: none;
  }

  .balok-2 {
  margin-bottom: 20px;
  display: flex;
  justify-content: flex-start;
  }

  .search-bar {
      display: flex;
      gap: 10px;
  }

  .search-bar input {
      padding: 10px;
      font-size: 14px;
      border-radius: 5px;
      border: 1px solid #ddd;
  }

  .search-bar button {
      padding: 10px;
      background-color: #007bff;
      color: white;
      border: none;
      border-radius: 5px;
  }

  .search-bar button:hover {
      background-color: #0056b3;
  }

  /* Tabel Data Surat */
  .balok-3 {
      margin-top: 20px;
      overflow-x: auto;
      width: 100%;
  }

  table {
      width: 100%;
      max-width: 100%;
      table-layout: auto;
      border-collapse: collapse;
      background-color: #fff;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      border-radius: 8px;
      overflow: hidden;
  }

  table select {
  width: 18px;
  font-size: 14px;
  padding: 0.5px;
  }

  th, td {
      font-size: 14px;
      padding: 5px 10px;
      text-align: left;
      border-bottom: 1px solid #ddd;
  }

  th {
      background-color: #007bff;
      color: #fff;
      font-size: 16px;
  }

  td {
      background-color: #f9f9f9;
      word-break: keep-all;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 250px;  
      }

  td a {
      color: #007bff;
      text-decoration: none;
      font-weight: bold;
  }

  td a:hover {
      text-decoration: underline;
  }

  .judul-surat-luar {
      text-align: center;
      margin: 0 auto;
      padding-left: 30px; 
      font-size: 24px;
      color: #333;
      margin-top: 20px;
      margin-bottom: 10px;
  }

  .form-group input[type="date"] {
    width: 10%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 16px;
    height: 10px;
  }
  .no-export {
  display: block; /* Tetap tampil di halaman */
  }

  .user-dropdown {
  position: relative;
  display: inline-block;
}

.user-icon {
  font-size: 30px;
  cursor: pointer;
  color: white; /* atau sesuai warna tema kamu */
}

.user-menu {
  display: none;
  position: absolute;
  right: 0;
  background-color: white;
  min-width: 120px;
  box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
  z-index: 999;
  border-radius: 5px;
}

.user-menu a {
  display: block;
  padding: 10px 15px;
  color: #333;
  text-decoration: none;
}

.user-menu a:hover {
  background-color: #f0f0f0;
}

.dropdown-instruksi {
    width: 19px;
    padding: 5px;
}

.chat-container {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 15px;
}

.chat-bubble {
    max-width: 60%;
    padding: 10px 15px;
    border-radius: 15px;
    position: relative;
    word-wrap: break-word;
    margin-bottom: 10px;
}

.chat-bubble.left {
    background-color: #f1f0f0;
    align-self: flex-start;
    border-top-left-radius: 0;
}

.chat-bubble.right {
    background-color: #cce5ff;
    align-self: flex-end;
    border-top-right-radius: 0;
}

.chat-time {
    font-size: 0.75em;
    color: gray;
    margin-top: 5px;
    text-align: right;
}

/* ===== Dropdown filter header: tabel Notif Surat ===== */
#tabelSuratNotif th .no-export select {
  width: 20px;
  font-size: 13px;
  border: 1px solid #d7d7d7;
  border-radius: 6px;
  background: #fff;
  box-sizing: border-box;
  cursor: pointer;
}

#tabelSuratNotif th .no-export input[type="date"] {
  width: 20px;
  font-size: 13px;
  border: 1px solid #d7d7d7;
  border-radius: 6px;
  background: #fff;
  box-sizing: border-box;
  cursor: pointer;
}

#tabelSuratNotif th .no-export select {
  outline: none;
  border-color: #5b9bff;
  box-shadow: 0 0 0 3px rgba(91,155,255,.15);
}
#tabelSuratNotif th .no-export { 
  margin-top: 6px;
}

.export-dropdown { position: relative; display: inline-block; margin-bottom: 10px;}
.export-toggle { cursor: pointer; }

.export-menu {
  position: absolute; top: 100%; left: 0;
  min-width: 210px; padding: 6px;
  background: #fff; border: 1px solid #ddd; border-radius: 8px;
  box-shadow: 0 6px 20px rgba(0,0,0,.08);
  z-index: 10; display: none;
}
.export-item {
  display: block; width: 100%; text-align: left;
  padding: 8px 10px; background: transparent; border: none; cursor: pointer;
}
.export-item:hover { background: #f2f6ff; }
.export-dropdown.open .export-menu { display: block; }
.notif-bell{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  margin: 0 12px;
  font-size: 28px;       /* ukuran ikon */
  color: white;          /* samakan dengan tema navbar */
  text-decoration: none;
}
.notif-bell:hover{ opacity:.85; }

/* (opsional) badge jumlah notif */
.notif-bell .badge{
  position:absolute;
  top:13px; right:64px;
  min-width:18px; height:18px;
  padding:0 5px;
  border-radius:999px;
  background:#ff3b30; color:#fff;
  font-size:12px; line-height:18px;
}


/* Pusatkan benar2 di tengah layar */
.chat-dock{
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 1000;
  pointer-events: none; 
  border      /* biar klik tembus di luar kartu */
}

/* Biar tetap enak dipakai di tengah */
.chat-dock-inner{
  pointer-events: auto;
  min-width: 400px;
  max-width: 680px;
  max-height: 70vh;          /* kalau tinggi konten banyak, bisa scroll */
  overflow: auto;
  border: 1px solid #e6e6e6;
  box-shadow: 0 12px 36px rgba(0,0,0,.15);
  border-radius: 14px;
  background: #fff;
  /* …(style kamu yang lain tetap) */
}


.dock-close{
  margin-left: auto;   /* dorong ke kanan */
  margin-right: -2px;  /* opsional: fine-tune jarak tepi kanan */
  margin-top: -2px;
  border: none; background: transparent;
  cursor: pointer; font-size: 18px; line-height: 1; color: #666;
}

.dock-close:hover{ color:#000; }

.dock-item{
  display: flex;                 /* baris item berisi ikon + label */
  align-items: center;
  gap: 8px;
  width: 100%;                   /* full width */
  padding: 12px 14px;
  border-radius: 10px;
  text-decoration: none;
  border: 1px solid #ececec;
  background: #f9fafc;
  color: #111;
}
.dock-item:hover{ background:#f2f6ff; }

.dock-emoji{ font-size:18px; line-height:1; }
.dock-label{ font-weight:600; font-size:14px; }

.dock-badge{
  margin-left: auto;             /* badge nempel di kanan */
  min-width: 18px; height: 18px; padding: 0 6px;
  border-radius: 999px; background: #ff3b30; color: #fff; font-size: 12px;
  display:inline-flex; align-items:center; justify-content:center;
}

/* opsional: judul kecil di atas daftar */
.chat-dock-title{
  font-weight:700; font-size:16px; margin: 2px 2px 6px;
}
.chat-dock.hidden { display: none !important; }

</style>
<body>
  <?= impersonation_banner_html(); ?>
  <!-- Latar Belakang -->
  <div class="background-fade"></div>
  <!-- Konten Utama -->
  <div class="main-content">
  <!-- Navbar Atas -->
  <div class="navbar-top">
    <div class="logo">
      <img src="Properti/LOGO_RSCH.png" alt="Logo" class="logo-img" />
      <div class="logo-text">
        <div class="main-title">RUANG BACA VIRTUAL</div>
        <!-- <div class="sub-title">Rumah Sakit Citra Husada</div> -->
      </div>
    </div>
    <div class="top-buttons">
      <?php if (in_array($role, ['Super Admin', 'Admin', 'Sekretariat', 'Member', 'Direktur'])): ?>
        <a href="sub_beranda.php" class="jelajahi-portal">Layanan</a>
        <a href="notifikasi.php" class="notif-bell" title="Notifikasi">
          <i class='bx bxs-bell'></i>
          <?php if ($jumlahNotif > 0): ?>
            <span class="badge"><?= $jumlahNotif ?></span>
          <?php endif; ?>
        </a>
      <?php endif; ?>
      <?php if (isset($_SESSION['user'])): ?>
        <div class="user-dropdown">
          <i class="bx bxs-user-circle user-icon"></i>
          <div class="user-menu" id="userMenu">
            <a href="profil.php">Profil</a>
            <?php if ($role === 'Super Admin'): ?>
              <a href="users.php">Data User</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
          </div>
        </div>
      <?php else: ?>
        <a href="login.php" class="login-btn">Login</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Navbar Bawah -->
  <nav class="navbar-bottom">
    <div class="navbar-bottom-container">
      <ul>
        <li><a href="1_trial.php" class="fitur-nav">Beranda</a></li>
        <li class="dropdown">
          <a class="fitur-nav" href="javascript:void(0);" onclick="toggleDropdown()">Berita</a>
          <div class="dropdown-content">
            <a href="https://www.goal.com" target="_blank">Bola</a>
            <a href="https://sport.detik.com/" target="_blank">Sport</a>
            <a href="https://www.liputan6.com/showbiz" target="_blank">Showbiz</a>
            <a href="https://www.viva.co.id/gaya-hidup" target="_blank">LifeStyle</a>
            <a href="https://www.oto.com/berita" target="_blank">Otomotif</a>
          </div>
        </li>  
        <li><a href="koleksi.php" class="fitur-nav">Koleksi</a></li>
        <?php if (in_array($role, ['Super Admin', 'Admin', 'Sekretariat', 'Member', 'Direktur'])): ?>
          <li><a href="bacaan.php" class="fitur-nav">Bacaan</a></li>
        <?php endif; ?>
        <!-- <li><a href="masukan.php" class="fitur-nav">Masukan</a></li> -->
        <?php if ($can_access_eoffice): ?>
          <li class="dropdown">
            <a class="fitur-nav" href="javascript:void(0);">E-Office</a>
            <div class="dropdown-content">
              <?php foreach ($allowedEofficePages as $href): ?>
                <a href="<?= $href ?>"><?= $eofficeAll[$href] ?></a>
              <?php endforeach; ?>
            </div>
          </li>
        <?php endif; ?>
        <?php if (in_array($role, ['Super Admin', 'Admin', 'Sekretariat', 'Member', 'Direktur'])): ?>
          <li><a href="artikel.php" class="fitur-nav">Artikel</a></li>
          <li><a href="video.php" class="fitur-nav">Video</a></li>          
        <?php endif; ?>
      </ul>
      <!-- <div class="search-bar-bottom">
        <input type="text" placeholder="Cari..." />
        <button>Cari</button>
      </div> -->
    </div>
  </nav>

<!-- Judul di luar kontainer -->
<h2 class="judul-surat-luar">Notif Surat</h2>

<!-- Buka kontainer utama -->
<div class="kontainer-balok">

    <!-- Balok 1 -->
    <div class="export-dropdown">
      <button type="button" class="btn-tambah export-toggle">Export ▾</button>
      <div class="export-menu">
        <button type="button" class="export-item" onclick="exportNotifToExcel(false)">Export All</button>
        <button type="button" class="export-item" onclick="exportNotifToExcel(true)">Export 3 Bulan Terakhir</button>
      </div>
    </div>


    <!-- Balok 2: Search Bar -->
    <div class="balok-2">
        <div class="search-bar">
            <input type="text" placeholder="Cari surat..." id="searchInput" oninput="searchTable()" />
            <button>Cari</button>
        </div>
    </div>

    <!-- TABEL NOTIF SURAT -->
<div class="table-container">
    <table id="tabelSuratNotif" border="1" cellpadding="10" cellspacing="0">
        <thead>
          <tr>
            <th>
              Tanggal
              <div class="no-export">
                <input
                  type="date"
                  id="flt-notif-tanggal"
                  onchange="applyNotifFilters(); showNotifReset();"
                />
              </div>
            </th>
            <th>
              No Surat
              <div class="no-export">
                <select id="flt-notif-nosurat" onchange="applyNotifFilters(); showNotifReset();">
                  <option value=""></option>
                  <?php foreach ($opsiNoSurat as $ns): ?>
                    <option value="<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($ns) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </th>
            <th>File</th>
            <th>Chat</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($semuaNotif)): ?>
            <?php foreach ($semuaNotif as $row): ?>
                <tr class="data-row" 
                data-id="<?= htmlspecialchars($row['id']) ?>"
                data-tanggal="<?= htmlspecialchars($row['tanggal'] ?? '') ?>"
                data-no_surat="<?= htmlspecialchars($row['no_surat'] ?? '') ?>">
                <td><?= htmlspecialchars($row['tanggal'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['no_surat'] ?? '') ?></td>
                    <td>
                      <?php $href = url_file($row['file_url'] ?? ''); ?>
                      <?php if ($href !== ''): ?>
                        <a href="<?= htmlspecialchars($href) ?>" target="_blank">Lihat File</a>
                      <?php else: ?>-<?php endif; ?>
                    </td>
                    <td>
                        <button type="button"
                                class="btn-chat"
                                data-no-surat="<?= htmlspecialchars($row['no_surat']) ?>">
                          💬
                        </button>
                    </td>
                    <td>
                        <a href="update_surat_notif.php?id=<?= urlencode($row['id']) ?>">✏️</a><br>
                        <a href="surat_notif.php?delete=<?= urlencode($row['id']) ?>" onclick="return confirm('Hapus surat ini?')">🗑️</a>
                    </td>
                </tr>
                <tr id="chatbox-<?= htmlspecialchars($row['no_surat']) ?>"
                    data-no_surat="<?= htmlspecialchars($row['no_surat']) ?>"
                    style="display:none;">
                  <td colspan="5">
                    <div class="chat-box" data-no_surat="<?= htmlspecialchars($row['no_surat']) ?>"></div>
                    <form class="form-chat" data-no_surat="<?= htmlspecialchars($row['no_surat']) ?>" method="POST">
                      <input type="hidden" name="pengirim" value="<?= htmlspecialchars($user['nama'] ?? '') ?>">
                      <input type="hidden" name="penerima" value="<?= ($user['status'] === 'Super Admin' ? 'Sekretariat' : 'Super Admin') ?>">
                      <input type="hidden" name="no_surat" value="<?= htmlspecialchars($row['no_surat']) ?>">
                      <textarea name="pesan" placeholder="Ketik pesan..." required style="width: 50%;"></textarea>
                      <button type="submit">Kirim</button>
                    </form>
                  </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="5" style="text-align:center; opacity:.7;">Data tidak ditemukan.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <!-- === Chat Dock (fixed di bawah) === -->
    <div id="chatDock" class="chat-dock hidden" aria-hidden="true">
      <div class="chat-dock-inner">
        <button type="button" class="dock-close" title="Tutup">✕</button>

        <?php foreach ($chatOptions as $opt): ?>
          <a class="dock-item"
            data-view="<?= htmlspecialchars($opt['view']) ?>"
            href="#"
            title="<?= htmlspecialchars($opt['label']) ?>">
            <span class="dock-emoji">💬</span>
            <span class="dock-label"><?= htmlspecialchars($opt['label']) ?></span>
            <!-- contoh badge unread per kanal (opsional) -->
            <?php if (!empty($opt['unread'])): ?>
              <span class="dock-badge"><?= (int)$opt['unread'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>

      </div>
    </div>
</div>

<div id="reset-container" style="display:none; text-align:right; margin-top:15px;">
    <button onclick="resetFilters()" style="background-color:#dc3545;color:#fff;padding:8px 16px;border:none;border-radius:5px;">Reset</button>
</div>
</div>

  <!-- Footer -->
  <footer>
    <div class="footer-container">
      <div class="footer-section">
        <h3>Tautan Lainnya</h3>
        <ul>
          <li><a href="faq.html" class="footer-link">FAQ</a></li>
          <li><a href="panduan.html" class="footer-link">Panduan/Tutorial</a></li>
          <li><a href="tentang kami.html" class="footer-link">Tentang Kami</a></li>          
        </ul>
      </div>
      <div class="footer-section">
        <h3>Media Sosial</h3>
        <ul>
          <a href="https://www.youtube.com/channel/UCWrutgBiaPK0vCk_pYxwGhw" ><i class="bx bxl-youtube sosmed-icon"></i></a>
          <a href="https://www.facebook.com/rscitrahusadajember/" ><i class="bx bxl-facebook sosmed-icon"></i></a>
          <a href="https://www.tiktok.com/@rscitrahusadajember?_t=ZS-8ssxXvGOz9G&_r=1" ><i class="bx bxl-tiktok sosmed-icon"></i></a>
          <a href="https://www.instagram.com/rscitrahusadajember/" ><i class="bx bxl-instagram sosmed-icon"></i></a>
          <a href="https://rscitrahusada.com/" ><i class='bx bxs-home sosmed-icon'></i></a>
      <div class="footer-section">
      <div class="map-container">
        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1690.947685920273!2d113.6812076459426!3d-8.169000958621156!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2dd694127910c383%3A0x42956e9612f6b07a!2sCitra%20Husada%20Hospital!5e0!3m2!1sen!2sid!4v1750478359122!5m2!1sen!2sid" width="300" height="200" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>      </div>
      </div>

        </ul>
      </div>
      <div class="footer-section">
        <h3>Kontak Kami</h3>
        <p>(+62 331) 486200 ext: 142          
        <br>08979049176<br>
        <p>Jalan Teratai No. 22, Patrang. Kab. Jember<br>
        Jawa Timur, Indonesia 68117</p>
      </div>
    </div>
  </footer>
  <footer>
    <div class="footer-bottom">
      <p>© Copyright IT Support Citra Husada.</p>
    </div>
  </footer>
  <script src="script.js"></script>
  <script>
  const userIcon = document.querySelector(".user-icon");
  const userMenu = document.getElementById("userMenu");
  if (userIcon) {
    userIcon.addEventListener("click", function (e) {
      e.stopPropagation();
      userMenu.style.display = userMenu.style.display === "block" ? "none" : "block";
    });
  }

  document.addEventListener("click", function (e) {
    if (userMenu && !userIcon.contains(e.target)) {
      userMenu.style.display = "none";
    }
  });
  </script>
  <script>

function loadSuratNotif() {
    fetch('surat_notif.php?get_notif=1')
    .then(res => res.json())
    .then(data => {
        const tbody = document.querySelector("#tabelSuratNotif tbody");
        tbody.innerHTML = "";

        if (data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5">Data tidak ditemukan</td></tr>`;
            return;
        }

        data.forEach(row => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${row.tanggal}</td>
                <td>${row.no_surat}</td>
                <td>${row.file_url ? `<a href="${row.file_url}" target="_blank">Lihat File</a>` : '-'}</td>
                <td><button onclick="toggleChatBox('${row.no_surat}')">💬</button></td>
                <td>
                    <a href="update_surat_notif.php?id=${row.id}">✏️</a><br>
                    <a href="surat_notif.php?delete=${row.id}" onclick="return confirm('Hapus surat ini?')">🗑️</a>
                </td>
            `;
            tbody.appendChild(tr);
        });
    });
}



function filterTable(attribute, value) {
  const rows = document.querySelectorAll('.data-row');
  rows.forEach(row => {
    if (value === "" || row.dataset[attribute.toLowerCase()] === value) {
      row.style.display = "table-row";
    } else {
      row.style.display = "none";
    }
  });
}

// Toggle dropdown Export
(function initNotifExportDropdown(){
  const dd  = document.querySelector('.export-dropdown');
  const btn = dd?.querySelector('.export-toggle');
  if (!btn) return;

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    dd.classList.toggle('open');
  });
  document.addEventListener('click', () => dd.classList.remove('open'));
})();


window.exportNotifToExcel = function (last3Months) {
  if (typeof XLSX === 'undefined') { 
    alert('Library XLSX belum ter-load.'); 
    return; 
  }

  const src = document.getElementById('tabelSuratNotif');
  if (!src) { alert('Tabel tidak ditemukan'); return; }

  // Clone agar aman dimodifikasi
  const table = src.cloneNode(true);

  // 1) Hilangkan elemen interaktif dari salinan (header filter/select/button)
  table.querySelectorAll('.no-export, select, form, button').forEach(el => el.remove());

  // 2) Pertahankan hanya baris yang SEDANG TAMPIL (mengikuti filter/search user)
  const origRows  = Array.from(src.querySelectorAll('tbody tr.data-row'));
  const cloneRows = Array.from(table.querySelectorAll('tbody tr.data-row'));
  cloneRows.forEach((tr, i) => {
    // kalau baris aslinya sudah tidak ada (harusnya tidak sih), aman-kan:
    const ref = origRows[i];
    if (!ref) return;
    const hidden = window.getComputedStyle(ref).display === 'none';
    if (hidden) tr.remove();
  });

  // 3) Opsi filter 3 bulan terakhir berdasarkan atribut data-tanggal
  function parseFlexibleDate(str){
    if (!str) return null;
    str = String(str).trim();
    if (str.length >= 10) str = str.slice(0,10); // potong waktu jika ada

    // YYYY-MM-DD
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      const [y,m,d] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;   // <-- perbaikan penting
    }
    // DD-MM-YYYY
    if (/^\d{2}-\d{2}-\d{4}$/.test(str)) {
      const [d,m,y] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;   // <-- perbaikan penting
    }
    return null;
  }

  if (last3Months) {
    const today = new Date();
    const start = new Date(today.getTime() - 90*24*60*60*1000);
    const end   = today;

    table.querySelectorAll('tbody tr.data-row').forEach(tr => {
      const raw = tr.getAttribute('data-tanggal') || '';
      const dt  = parseFlexibleDate(raw);
      if (!dt || dt < start || dt > end) tr.remove();
    });
  }

  // 4) Hapus kolom yang tidak perlu: File, Chat, Aksi
  const headerRow = table.querySelector('thead tr') || table.querySelector('tr');
  const ths = Array.from(headerRow.children);
  const removeIdx = ths
    .map((th, i) => [ (th.textContent||'').trim().split('\n')[0], i ])
    .filter(([t]) => ['File','Chat','Aksi'].includes(t))
    .map(([, i]) => i);

  table.querySelectorAll('tr').forEach(tr => {
    Array.from(tr.children).forEach((td, i) => { if (removeIdx.includes(i)) td.remove(); });
  });

  // 5) Buat workbook dari tabel
  const wb = XLSX.utils.table_to_book(table, { sheet: 'Notif Surat' });
  const ws = wb.Sheets['Notif Surat'];

  // Lebar kolom berdasar header
  const firstRow = XLSX.utils.sheet_to_json(ws, { header: 1 })[0] || [];
  ws['!cols'] = firstRow.map(h => ({ wch: Math.min(Math.max(String(h||'').length + 2, 12), 40) }));

  XLSX.writeFile(wb, `surat_notif_${last3Months ? '3bulan' : 'all'}.xlsx`);

  // tutup dropdown setelah export
  document.querySelector('.export-dropdown')?.classList.remove('open');
};

function resetFilters() {
  const selects = document.querySelectorAll('select');
  selects.forEach(select => {
    select.selectedIndex = 0;
  });

  const dateInput = document.getElementById('tanggalSurat');
  if (dateInput) dateInput.value = '';

  const rows = document.querySelectorAll('.data-row');
  rows.forEach(row => {
    row.style.display = "table-row";
  });

  const resetContainer = document.getElementById("reset-container");
  if (resetContainer) {
    resetContainer.style.display = "none";
  }
}

function searchTable() {
  const input = document.getElementById("searchInput").value.toLowerCase();
  const rows = document.querySelectorAll("#tabelSuratNotif .data-row");

  rows.forEach(row => {
    const cells = row.querySelectorAll("td");
    let found = false;

    cells.forEach(cell => {
      if (cell.textContent.toLowerCase().includes(input)) {
        found = true;
      }
    });

    row.style.display = found ? "table-row" : "none";
  });

  const resetContainer = document.getElementById("reset-container");
  if (input.length > 0) {
    resetContainer.style.display = "block";
  }
}

function loadChat(noSurat, container) {
  const url = 'pesan_lihat.php?ajax=1&no_surat=' + encodeURIComponent(noSurat);
  container.innerHTML = '<div style="opacity:.6">Memuat percakapan...</div>';

  fetch(url, {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(async res => {
    const text = await res.text();
    if (!res.ok) throw new Error('HTTP ' + res.status + ' — ' + text.slice(0, 200));
    return text;
  })
  .then(html => {
    container.innerHTML = html && html.trim()
      ? '<div class="chat-container">' + html + '</div>'
      : '<div style="opacity:.6">Belum ada pesan.</div>';
    container.scrollTop = container.scrollHeight;
  })
  .catch(err => {
    container.innerHTML = '<div style="color:#b00020">Gagal memuat chat: ' + err.message + '</div>';
  });
}

// function toggleChatBoxFromBtn(btn) {
//   const dataRow = btn.closest('tr');
//   if (!dataRow) return;

//   const chatRow = dataRow.nextElementSibling; // baris setelahnya
//   if (!chatRow) return;

//   const chatBox = chatRow.querySelector('.chat-box');
//   if (!chatBox) return;

//   const willOpen = (chatRow.style.display === 'none' || !chatRow.style.display);
//   chatRow.style.display = willOpen ? 'table-row' : 'none';

//   if (willOpen) {
//     const noSurat = btn.dataset.noSurat || chatRow.dataset.noSurat || dataRow.dataset.noSurat;
//     if (noSurat) loadChat(noSurat, chatBox);
//   }
// }

// Submit form chat via AJAX
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.form-chat').forEach(form => {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      const formData = new FormData(form);
      const noSurat = form.dataset.noSurat;
      const chatRow = form.closest('tr');
      const chatBox = chatRow.querySelector('.chat-box');

      fetch('pesan_buat.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.text())
      .then(() => {
        form.reset();
        loadChat(noSurat, chatBox);
      })
      .catch(err => {
        alert('Gagal mengirim pesan: ' + err.message);
      });
    });
  });
});

// Auto-refresh 10 detik SEKALI, hanya untuk chatbox yang TERBUKA
setInterval(function () {
  document.querySelectorAll('[id^="chatbox-"]').forEach(function (chatRow) {
    if (chatRow.style.display === 'none') return; // hanya yang terlihat
    const noSurat = chatRow.dataset.noSurat;
    const chatBoxDiv = chatRow.querySelector('.chat-box');
    if (noSurat && chatBoxDiv) {
      fetch('pesan_lihat.php?ajax=1&no_surat=' + encodeURIComponent(noSurat))
      .then(r => r.text())
      .then(html => {
        chatBoxDiv.innerHTML = html && html.trim()
          ? '<div class="chat-container">' + html + '</div>'
          : '<div style="opacity:.6">Belum ada pesan.</div>';
        chatBoxDiv.scrollTop = chatBoxDiv.scrollHeight;
      })
      .catch(()=>{ /* diamkan agar tidak ganggu UI */ });
    }
  });
}, 10000);

// Edit pesan (opsional, panggil dari HTML di pesan_lihat.php)
function editPesan(idPesan, isiLama) {
  const pesanBaru = prompt("Edit pesan:", isiLama);
  if (pesanBaru && pesanBaru !== isiLama) {
    fetch("pesan_edit.php", {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded"},
      body: "id_pesan=" + encodeURIComponent(idPesan) +
            "&pesan_baru=" + encodeURIComponent(pesanBaru)
    })
    .then(res => res.text())
    .then(msg => {
      if (msg.toLowerCase().includes("berhasil")) {
        alert("Pesan berhasil diedit");
        // reload chat yang sedang terbuka
        const openRow = document.querySelector('[id^="chatbox-"]:not([style*="display: none"])');
        if (openRow) {
          const noSurat = openRow.dataset.noSurat;
          const chatBoxDiv = openRow.querySelector('.chat-box');
          if (noSurat && chatBoxDiv) loadChat(noSurat, chatBoxDiv);
        }
      } else {
        alert("Gagal mengedit pesan: " + msg);
      }
    })
    .catch(err => {
      alert("Terjadi kesalahan: " + err.message);
    });
  }
}

function showNotifReset(){
  const hasTgl = !!(document.getElementById('flt-notif-tanggal')?.value || '');
  const hasNo  = !!(document.getElementById('flt-notif-nosurat')?.value || '');
  const box = document.getElementById('reset-container');
  if (box) box.style.display = (hasTgl || hasNo) ? 'block' : 'none';
}

function applyNotifFilters(){
  const vTgl = (document.getElementById('flt-notif-tanggal')?.value || '').toLowerCase();
  const vNo  = (document.getElementById('flt-notif-nosurat')?.value || '').toLowerCase();

  document.querySelectorAll('#tabelSuratNotif .data-row').forEach(tr => {
    const tTgl = (tr.getAttribute('data-tanggal') || '').toLowerCase();
    const tNo  = (tr.getAttribute('data-no_surat') || '').toLowerCase();

    let visible = true;

    // robust utk 'YYYY-MM-DD' *atau* 'YYYY-MM-DD HH:MM:SS'
    if (vTgl && tTgl.slice(0,10) !== vTgl) visible = false;

    // kalau mau lebih longgar lagi: if (vTgl && !tTgl.startsWith(vTgl)) visible = false;

    if (vNo && !tNo.includes(vNo)) visible = false;

    tr.style.display = visible ? 'table-row' : 'none';

    const chatRow = tr.nextElementSibling;
    if (chatRow && chatRow.id?.startsWith('chatbox-') && !visible) chatRow.style.display = 'none';
  });
}


// Extend resetFilters() agar juga reset datepicker Notif
(function(){
  const oldReset = window.resetFilters;
  window.resetFilters = function(){
    if (typeof oldReset === 'function') oldReset();

    const dt = document.getElementById('flt-notif-tanggal');
    const ns = document.getElementById('flt-notif-nosurat');
    if (dt) dt.value = '';
    if (ns) ns.selectedIndex = 0;

    document.querySelectorAll('#tabelSuratNotif .data-row')
      .forEach(tr => tr.style.display = 'table-row');

    showNotifReset();
  };
})();

document.addEventListener('DOMContentLoaded', () => {
  showNotifReset();
});

document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(location.search);
  const no = (params.get('no_surat') || '').toLowerCase();
  if (!no) return;
  document.querySelectorAll('[data-no_surat]').forEach(tr => {
    if ((tr.getAttribute('data-no_surat')||'').toLowerCase() === no) {
      tr.style.outline = '2px solid #28a745';
      tr.scrollIntoView({behavior:'smooth', block:'center'});
    }
  });
});

  </script>
<script>
// Dock elemen
const chatDock = document.getElementById('chatDock');
const dockItems = () => Array.from(document.querySelectorAll('#chatDock .dock-item'));

// buka dock dari tombol chat pada tabel
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-chat[data-no-surat]');
  if (btn) {
    e.preventDefault();
    const noSurat = btn.dataset.noSurat || btn.closest('tr')?.dataset.no_surat || '';
    if (!noSurat) return;

    // set href tiap kanal
    dockItems().forEach(a => {
      const view = a.dataset.view || 'pesan_lihat.php';
      a.href = view + '?no_surat=' + encodeURIComponent(noSurat);
    });

    chatDock.classList.remove('hidden');
    chatDock.setAttribute('aria-hidden','false');
  }

  // tombol tutup
  if (e.target.closest('.dock-close')) {
    chatDock.classList.add('hidden');
    chatDock.setAttribute('aria-hidden','true');
  }
});

// klik di luar kartu dock → tutup
document.addEventListener('click', (e) => {
  if (!chatDock || chatDock.classList.contains('hidden')) return;
  const inside = e.target.closest('.chat-dock-inner');
  const fromBtn = e.target.closest('.btn-chat[data-no-surat]');
  if (!inside && !fromBtn) {
    chatDock.classList.add('hidden');
    chatDock.setAttribute('aria-hidden','true');
  }
});
</script>
<script>
(function () {
  const chatDock = document.getElementById('chatDock');
  const dockItems = () => Array.from(document.querySelectorAll('#chatDock .dock-item'));

  function resetUI() {
    // Tutup dock
    if (chatDock) {
      chatDock.classList.add('hidden');
      chatDock.setAttribute('aria-hidden','true');
    }
    // Kosongkan href kanal (opsional, biar baru diisi lagi saat tombol chat diklik)
    dockItems().forEach(a => a.removeAttribute('href'));

    // Reset filter tabel & search (sesuaikan dengan id yang kamu pakai)
    const tgl = document.getElementById('flt-notif-tanggal');
    const no  = document.getElementById('flt-notif-nosurat');
    const q   = document.getElementById('searchInput');

    if (tgl) tgl.value = '';
    if (no)  no.selectedIndex = 0;
    if (q)   q.value = '';

    // Tampilkan semua baris lagi
    document.querySelectorAll('#tabelSuratNotif .data-row')
      .forEach(tr => tr.style.display = 'table-row');

    // Sembunyikan tombol reset
    const resetBox = document.getElementById('reset-container');
    if (resetBox) resetBox.style.display = 'none';
  }

  // Load pertama
  document.addEventListener('DOMContentLoaded', resetUI);

  // Saat halaman kembali dari bfcache (Back/Forward) *atau* reload cepat
  window.addEventListener('pageshow', function (e) {
    // e.persisted === true berarti dari bfcache
    resetUI();
  });
})();
</script>
</body>
</html>    