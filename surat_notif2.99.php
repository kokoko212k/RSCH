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
function buat_notif_dari_surat_notif(PDO $pdo, int $suratNotifId): void
{
    // Ambil baris surat_notif + info kepemilikan dari surat_pengajuan
    $q = $pdo->prepare("
        SELECT n.id, n.no_surat, n.file_url, n.tanggal, n.waktu,
               sp.pengajuan_nik, sp.pengajuan_unit
        FROM surat_notif n
        LEFT JOIN surat_pengajuan sp ON sp.no_surat = n.no_surat
        WHERE n.id = ?
        LIMIT 1
    ");
    $q->execute([$suratNotifId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return;

    $no_surat = (string)($r['no_surat'] ?? '');
    $file_url = (string)($r['file_url'] ?? '');
    $nik      = (string)($r['pengajuan_nik'] ?? '');
    $unit     = (string)($r['pengajuan_unit'] ?? '');

    // 1) Insert ke notifikasi
    $title = 'Ada surat baru masuk';
    $body  = $no_surat !== '' ? ('No. Surat: ' . $no_surat) : null;
    $actionUrl = $no_surat !== '' ? ('surat_notif.php?no_surat=' . rawurlencode($no_surat)) : 'surat_notif.php';
    $eventKey  = 'surat_notif:' . $suratNotifId;

    $insNotif = $pdo->prepare("
        INSERT INTO notifikasi (title, body, action_url, type, event_key, created_by)
        VALUES (:title, :body, :url, 'surat_notif', :ek, NULL)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)  -- ambil id lama kalau sudah ada
    ");
    $insNotif->execute([
        ':title' => $title,
        ':body'  => $body,
        ':url'   => $actionUrl,
        ':ek'    => $eventKey,
    ]);
    $notifId = (int)$pdo->lastInsertId();
    if ($notifId <= 0) return;

    // 2) Cari target penerima
    //    - Super Admin: semua
    //    - Sekretariat: sesuai unit surat
    //    - Pemilik (nik): user yang nik = pengajuan_nik
    // NOTE: sesuaikan nama tabel/kolom users dengan punyamu
    $userIds = [];

    // Super Admin
    $st = $pdo->query("SELECT id FROM users WHERE status='Super Admin'");
    $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    // Sekretariat unit sama
    if ($unit !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE status='Sekretariat' AND unit = ?");
        $st->execute([$unit]);
        $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    // Pemilik berdasarkan NIK
    if ($nik !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE nik = ?");
        $st->execute([$nik]);
        $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    // Bersihkan duplikat
    $userIds = array_values(array_unique(array_filter($userIds, fn($v)=>$v>0)));

    if (!$userIds) return;

    // 3) Masukkan ke notifikasi_targets (hindari dobel)
    $insT = $pdo->prepare("
        INSERT IGNORE INTO notifikasi_targets (notifikasi_id, user_id, status, unit)
        VALUES (?, ?, NULL, ?)
    ");
    foreach ($userIds as $uid) {
        $insT->execute([$notifId, $uid, ($unit ?: null)]);
        // Optional: inisialisasi detail_notifikasi (status unread)
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

$user = $_SESSION['user'];
$role = $user['status'] ?? null;
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
  'Super Admin' => array_keys($eofficeAll), // semua
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

// Tentukan halaman yang boleh tampil untuk role saat ini
$allowedEofficePages = $rolePages[$role] ?? [];
$can_access_eoffice  = !empty($allowedEofficePages);
$pengirim   = $user['nama'] ?? '';


// ================== SYNC "DITERUSKAN LANGSUNG" KE NOTIF ==================
try {
    // --- blok lama: DITERUSKAN LANGSUNG -> surat_notif ---
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

    // --- tambah baru: DITERUSKAN (khusus yang berasal dari surat_keluar) -> surat_notif ---
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

            // === tambahkan ini agar notifikasi global ikut tercipta ===
            $baruId = (int)$pdo->lastInsertId();
            if ($baruId) buat_notif_dari_surat_notif($pdo, $baruId);
        }
    }
} catch (Throwable $e) {
    // optional: log error
}

// ----------- Hapus notifikasi -----------
if (isset($_GET['delete'])) {
    $id = (int)($_GET['delete']);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM surat_notif WHERE id = ?");
        $stmt->execute([$id]);
    }
    header("Location: surat_notif.php");
    exit;
}

// ================== API (AJAX) ==================
// Ambil daftar surat_notif via JSON (dipakai loadSuratNotif())
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_notif'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $q = $pdo->query("
            SELECT 
                n.id,
                n.tanggal,
                n.no_surat,
                n.file_url,
                n.waktu,
                COALESCE(MAX(NULLIF(tl.disposisi_kepada,'')), '') AS disposisi_kepada
            FROM surat_notif n
            LEFT JOIN surat_disposisi_tindak_lanjut tl
                   ON tl.no_surat = n.no_surat
            GROUP BY n.id, n.tanggal, n.no_surat, n.file_url, n.waktu
            ORDER BY n.waktu DESC, n.id DESC
        ");
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        // normalisasi URL file (pakai helper url_file jika sudah ada)
        foreach ($rows as &$r) {
            $r['file_url'] = url_file($r['file_url'] ?? '');
        }
        unset($r);

        echo json_encode($rows);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal mengambil data', 'detail' => $e->getMessage()]);
    }
    exit;
}
// === List notifikasi ringkas utk panel (10 terbaru) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_notif_panel'])) {
  header('Content-Type: application/json; charset=utf-8');
  $loginUserId = (int)($user['id'] ?? 0);
  try {
    $sql = "
      SELECT n.id, n.title, n.body, n.action_url, n.type, n.created_at,
             (CASE WHEN d.read_at IS NULL THEN 0 ELSE 1 END) AS is_read
      FROM notifikasi n
      JOIN notifikasi_targets t ON t.notifikasi_id = n.id AND t.user_id = :uid
      LEFT JOIN detail_notifikasi d ON d.notifikasi_id = n.id AND d.user_id = :uid
      ORDER BY n.id DESC
      LIMIT 10
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $loginUserId]);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'gagal','detail'=>$e->getMessage()]);
  }
  exit;
}

// === Tandai 1 notif sebagai dibaca (update badge di UI) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read_notif') {
  header('Content-Type: text/plain; charset=utf-8');
  $loginUserId = (int)($user['id'] ?? 0);
  $nid = (int)($_POST['id'] ?? 0);
  if ($loginUserId > 0 && $nid > 0) {
    $pdo->prepare("
      INSERT INTO detail_notifikasi (notifikasi_id, user_id, read_at)
      VALUES (?, ?, NOW())
      ON DUPLICATE KEY UPDATE read_at = IF(read_at IS NULL, NOW(), read_at)
    ")->execute([$nid, $loginUserId]);
    echo "ok"; exit;
  }
  echo "err"; exit;
}

// Insert notif
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'insert_notif') {
    $no_surat = trim($_POST['no_surat'] ?? '');
    $file_url = trim($_POST['file_url'] ?? '');
    $tanggal  = date('Y-m-d');

    if ($no_surat !== '') {
        $stmt = $pdo->prepare("INSERT INTO surat_notif (tanggal, no_surat, file_url, waktu) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$tanggal, $no_surat, $file_url]);
        $baruId = (int)$pdo->lastInsertId();
        if ($baruId) buat_notif_dari_surat_notif($pdo, $baruId);
        echo "OK";
    } else {
        echo "ERROR: Data tidak lengkap";
    }
    exit;
}

// Insert chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'insert_chat') {
    $no_surat = trim($_POST['no_surat'] ?? '');
    $penerima = trim($_POST['penerima'] ?? '');
    $pesan    = trim($_POST['pesan'] ?? '');

    if ($no_surat !== '' && $penerima !== '' && $pesan !== '' && $pengirim !== '') {
        $stmt = $pdo->prepare("INSERT INTO pesan (no_surat, pengirim, penerima, pesan, waktu) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$no_surat, $pengirim, $penerima, $pesan]);
        echo "OK";
    } else {
        echo "ERROR: Data tidak lengkap untuk chat";
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
        // pastikan hanya pengirim yg sama
        $stmt = $pdo->prepare("DELETE FROM pesan WHERE id = ? AND pengirim = ?");
        $stmt->execute([$pesan_id, $pengirim]);
        echo $stmt->rowCount() ? "DELETED" : "ERROR: Tidak bisa hapus pesan orang lain";
    } else {
        echo "ERROR: Data tidak valid";
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_disposisi'])) {
    $id = (int)($_POST['id'] ?? 0);
    $disposisi_kepada  = trim($_POST['disposisi_kepada'] ?? '');
    $tanggal_disposisi = $_POST['tanggal_disposisi'] ?? date('Y-m-d');

    if ($id > 0 && $disposisi_kepada !== '') {
        // ...update surat_masuk...

        // Ambil no_surat & file_url dari sumber yang valid (mis. surat_masuk)
        $get = $pdo->prepare("SELECT no_surat, file_url FROM surat_masuk WHERE id=?");
        $get->execute([$id]);
        $row = $get->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stored_file = trim((string)$row['file_url']);
            if ($stored_file !== '' && strpos($stored_file, '/') === false) {
                $stored_file = 'uploads/' . $stored_file;
            }

            $stmtNotif = $pdo->prepare("
                INSERT INTO surat_notif (tanggal, no_surat, file_url, waktu)
                VALUES (:tanggal, :no_surat, :file_url, NOW())
            ");
            $stmtNotif->execute([
              'tanggal'  => $tanggal_disposisi,
              'no_surat' => $row['no_surat'],
              'file_url' => $stored_file,
            ]);
            $baruId = (int)$pdo->lastInsertId();
            if ($baruId) buat_notif_dari_surat_notif($pdo, $baruId);
        }
    }
    // exit kalau ini endpoint AJAX
}

// ================== Ambil data untuk TABEL ==================
// ================== Ambil data untuk TABEL ==================
$semuaNotif = [];
try {
    $q = "
        SELECT 
            n.id, n.tanggal, n.no_surat, n.file_url, n.waktu,
            COALESCE(MAX(NULLIF(tl.disposisi_kepada,'')), '') AS disposisi_kepada
        FROM surat_notif n
        LEFT JOIN surat_disposisi_tindak_lanjut tl 
               ON tl.no_surat = n.no_surat
        GROUP BY n.id, n.tanggal, n.no_surat, n.file_url, n.waktu
        ORDER BY n.waktu DESC, n.id DESC
    ";
    $stmt = $pdo->query($q);
    $semuaNotif = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $semuaNotif = [];
}

$opsiNoSurat  = array_values(array_unique(array_filter(array_column($semuaNotif, 'no_surat'))));
sort($opsiNoSurat, SORT_NATURAL | SORT_FLAG_CASE);

$opsiTanggal  = array_values(array_unique(array_filter(array_column($semuaNotif, 'tanggal'))));
// kalau mau urut terbaru dulu:
rsort($opsiTanggal);

function url_file($s) {
  $s = trim((string)$s);
  if ($s === '') return '';
  // kalau tidak ada slash sama sekali, anggap file ada di folder uploads/
  return (strpos($s, '/') === false) ? ('uploads/' . $s) : $s;
}

$loginUserId = (int)($user['id'] ?? 0);
$jumlahNotif = 0;
if ($loginUserId > 0) {
  $stmtCnt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifikasi_targets t
    LEFT JOIN detail_notifikasi d
      ON d.notifikasi_id = t.notifikasi_id
     AND d.user_id       = t.user_id
    WHERE t.user_id = ?
      AND (d.read_at IS NULL)        -- belum dibaca
  ");
  $stmtCnt->execute([$loginUserId]);
  $jumlahNotif = (int)$stmtCnt->fetchColumn();
}
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
/* Panel dropdown notifikasi */
#notifPanel.notif-panel{
  position: absolute;
  right: 90px;            /* sesuaikan dengan layout nav kamu */
  top: 58px;              /* sesuaikan tinggi nav */
  width: 360px;
  max-height: 70vh;
  overflow: auto;
  background:#fff;
  border:1px solid #e6e6e6;
  border-radius: 10px;
  box-shadow: 0 8px 30px rgba(0,0,0,.1);
  z-index: 1001;
}
#notifPanel .notif-head{
  display:flex; justify-content:space-between; align-items:center;
  padding:10px 12px; border-bottom:1px solid #f0f0f0;
}
#notifPanel .lihat-semua{ font-size:12px; }
#notifPanel .notif-item{
  display:block; padding:10px 12px; border-bottom:1px solid #f6f6f6;
  text-decoration:none; color:#222;
}
#notifPanel .notif-item.unread{ background:#f7fbff; }
#notifPanel .notif-item .title{ font-weight:600; margin-bottom:4px; }
#notifPanel .notif-item .meta{ font-size:12px; color:#777; }

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
      <button type="button" class="notif-bell" id="btnNotif" title="Notifikasi">
        <i class='bx bxs-bell'></i>
        <?php if ($jumlahNotif > 0): ?><span class="badge" id="notifBadge"><?= $jumlahNotif ?></span><?php endif; ?>
      </button>

      <!-- Panel dropdown -->
      <div id="notifPanel" class="notif-panel" style="display:none">
        <div class="notif-head">
          <strong>Notifikasi</strong>
          <a href="notifikasi.php" class="lihat-semua">Lihat semua »</a>
        </div>
        <div id="notifList" class="notif-list"><div style="opacity:.6">Memuat...</div></div>
      </div>
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
                      <?php if (!empty($row['disposisi_kepada'])): ?>
                        <button class="btn-chat"
                                type="button"
                                data-no-surat="<?= htmlspecialchars($row['no_surat']) ?>"
                                onclick="toggleChatBoxFromBtn(this)">💬</button>
                      <?php else: ?>
                        <button class="btn-chat" type="button" disabled title="Isi disposisi dulu">💬</button>
                      <?php endif; ?>
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
            <tr><td colspan="5">Data tidak ditemukan</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
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
  const url = 'pesan_lihat_5.php?ajax=1&no_surat=' + encodeURIComponent(noSurat);
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

// Toggle membuka/menutup baris chat tepat di bawah baris data
function toggleChatBoxFromBtn(btn) {
  const dataRow = btn.closest('tr');
  if (!dataRow) return;

  const chatRow = dataRow.nextElementSibling; // baris setelahnya
  if (!chatRow) return;

  const chatBox = chatRow.querySelector('.chat-box');
  if (!chatBox) return;

  const willOpen = (chatRow.style.display === 'none' || !chatRow.style.display);
  chatRow.style.display = willOpen ? 'table-row' : 'none';

  if (willOpen) {
    const noSurat = btn.dataset.noSurat || chatRow.dataset.noSurat || dataRow.dataset.noSurat;
    if (noSurat) loadChat(noSurat, chatBox);
  }
}

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
      fetch('pesan_lihat_5.php?ajax=1&no_surat=' + encodeURIComponent(noSurat))
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

// Edit pesan (opsional, panggil dari HTML di pesan_lihat_5.php)
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
(function(){
  const btn   = document.getElementById('btnNotif');
  const panel = document.getElementById('notifPanel');
  const list  = document.getElementById('notifList');
  const badge = document.getElementById('notifBadge');

  async function fetchPanel() {
    list.innerHTML = '<div style="opacity:.6">Memuat...</div>';
    const r = await fetch('<?= basename(__FILE__) ?>?get_notif_panel=1', {credentials:'same-origin'});
    const data = await r.json();
    if (!Array.isArray(data) || data.length === 0) {
      list.innerHTML = '<div style="opacity:.6;padding:12px">Tidak ada notifikasi.</div>';
      return;
    }
    list.innerHTML = data.map(it => {
      const cls = it.is_read == 1 ? 'notif-item' : 'notif-item unread';
      const url = it.action_url || '#';
      const body = it.body ? it.body : '';
      const meta = it.type ? it.type : '';
      return `
        <a href="${url}" class="${cls}" data-id="${it.id}">
          <div class="title">${it.title || '(tanpa judul)'}</div>
          <div class="body">${body}</div>
          <div class="meta">${meta}</div>
        </a>`;
    }).join('');
  }

  function openPanel() {
    panel.style.display = 'block';
    fetchPanel().catch(()=>{ list.innerHTML = '<div style="color:#b00020;padding:12px">Gagal memuat.</div>'; });
  }
  function closePanel() { panel.style.display = 'none'; }

  if (btn) btn.addEventListener('click', (e) => {
    e.stopPropagation();
    (panel.style.display === 'block') ? closePanel() : openPanel();
  });
  document.addEventListener('click', (e) => {
    if (!panel.contains(e.target) && e.target !== btn) closePanel();
  });

  // Tandai dibaca saat item di-klik (dan update badge di UI)
  list.addEventListener('click', async (e) => {
    const item = e.target.closest('.notif-item'); if (!item) return;
    const id = item.getAttribute('data-id');
    try {
      await fetch('<?= basename(__FILE__) ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'read_notif', id})
      });
      // kurangi badge jika item sebelumnya unread
      if (item.classList.contains('unread') && badge) {
        const now = parseInt(badge.textContent || '0', 10) || 0;
        const next = Math.max(now - 1, 0);
        badge.textContent = next;
        if (next === 0) badge.style.display = 'none';
      }
      item.classList.remove('unread');
      // biarkan navigate ke action_url (default <a>)
    } catch (err) { /* diamkan */ }
  });
})();
e.preventDefault();
// TODO: buka modal/side panel kamu sendiri berdasarkan it.action_url
// Contoh cepat: kalau action_url berisi no_surat, sorot baris di tabel:
const u = new URL(item.href, location.href);
const no = u.searchParams.get('no_surat');
if (no) {
  const tr = document.querySelector('#tabelSuratNotif .data-row[data-no_surat="'+no+'"]');
  if (tr) {
    tr.scrollIntoView({behavior:'smooth', block:'center'});
    tr.style.outline = '2px solid #28a745';
    setTimeout(()=> tr.style.outline='none', 2000);
  }
}
</script>
</body>
</html>    