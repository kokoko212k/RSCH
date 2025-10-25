<?php
session_start();

/**
 * Buat notifikasi untuk surat_pengajuan (create / update).
 * Target:
 *  - Semua Super Admin
 *  - Semua Sekretariat pada unit yang sama dengan pengajuan
 *  - Pemilik (NIK pengajuan)
 */
function buat_notif_dari_surat_pengajuan(PDO $pdo, int $suratId, string $aksi, ?string $ownerNik, ?string $unit): void
{
    // Ambil ringkasan surat untuk judul/body
    $q = $pdo->prepare("SELECT no_surat, tanggal FROM surat_pengajuan WHERE id=? LIMIT 1");
    $q->execute([$suratId]);
    $sp = $q->fetch(PDO::FETCH_ASSOC);
    if (!$sp) return;

    $no_surat = (string)($sp['no_surat'] ?? '');
    $title = $aksi === 'create' ? 'Pengajuan baru dibuat' : 'Pengajuan diperbarui';
    $body  = $no_surat !== '' ? ('No. Surat: ' . $no_surat) : null;
    // Arahkan ke halaman detail/update kamu (ubah sesuai rute detail kamu)
    $actionUrl = 'update_surat_pengajuan.php?id=' . $suratId;

    // Gunakan event_key unik per aksi supaya update juga munculkan notif baru
    // contoh: surat_pengajuan:create:{id}  dan  surat_pengajuan:update:{id}:{YYYYMMDDHHIISS}
    $suffix   = $aksi === 'create' ? '' : (':' . date('YmdHis'));
    $eventKey = "surat_pengajuan:{$aksi}:{$suratId}{$suffix}";

    // Insert notifikasi (idempotent via UNIQUE(event_key))
    $ins = $pdo->prepare("
      INSERT INTO notifikasi (title, body, action_url, type, event_key, created_by)
      VALUES (:title, :body, :url, 'surat_pengajuan', :ek, NULL)
      ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
    ");
    $ins->execute([
      ':title' => $title,
      ':body'  => $body,
      ':url'   => $actionUrl,
      ':ek'    => $eventKey,
    ]);
    $notifId = (int)$pdo->lastInsertId();
    if ($notifId <= 0) return;

    // Cari target user
    $userIds = [];

    // Super Admin
    $st = $pdo->query("SELECT id FROM users WHERE status='Super Admin'");
    $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    // Sekretariat unit sama
    if (!empty($unit)) {
      $st = $pdo->prepare("SELECT id FROM users WHERE status='Sekretariat' AND unit=?");
      $st->execute([$unit]);
      $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    // Pemilik berdasarkan NIK
    if (!empty($ownerNik)) {
      $st = $pdo->prepare("SELECT id FROM users WHERE nik=?");
      $st->execute([$ownerNik]);
      $userIds = array_merge($userIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    $userIds = array_values(array_unique(array_filter($userIds, fn($v)=>$v>0)));
    if (!$userIds) return;

    // Simpan target + set unread
    $insT = $pdo->prepare("
      INSERT IGNORE INTO notifikasi_targets (notifikasi_id, user_id, status, unit)
      VALUES (?, ?, NULL, ?)
    ");
    foreach ($userIds as $uid) {
      $insT->execute([$notifId, $uid, ($unit ?: null)]);
      $pdo->prepare("INSERT IGNORE INTO detail_notifikasi (notifikasi_id, user_id, read_at) VALUES (?, ?, NULL)")
          ->execute([$notifId, $uid]);
    }
}


$host = "localhost";
$user = "root";
$pass = "";
$db = "rsch";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

include 'config.php';

$user  = $_SESSION['user'] ?? null;
$role  = $user['status'] ?? null;      // 'Super Admin', 'Sekretariat', 'Direktur', 'Admin', 'Member'
$nik   = $user['nik']   ?? null;
$nama  = $user['nama']  ?? null;
$unit  = $user['unit']  ?? null;
$status= $user['status']?? null;
$scopeWhere  = '1=0';
$scopeParams = [];

switch ($role) {
  case 'Super Admin':
    $scopeWhere = '1=1';
    break;
  case 'Sekretariat':
    $scopeWhere  = 'pengajuan_unit = ?';
    $scopeParams = [$unit];
    break;
  case 'Direktur':
  case 'Admin':
  case 'Member':
  default:
    $scopeWhere  = 'pengajuan_nik = ?';
    $scopeParams = [$nik];
    break;
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    if ($role === 'Super Admin') {
        $sqlDel = "DELETE FROM surat_pengajuan WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sqlDel);
        mysqli_stmt_bind_param($stmt, "i", $id);
    } elseif ($role === 'Sekretariat') {
        $sqlDel = "DELETE FROM surat_pengajuan WHERE id = ? AND pengajuan_unit = ?";
        $stmt = mysqli_prepare($conn, $sqlDel);
        mysqli_stmt_bind_param($stmt, "is", $id, $unit);
    } else { // Direktur/Admin/Member: hanya boleh hapus milik sendiri
        $sqlDel = "DELETE FROM surat_pengajuan WHERE id = ? AND pengajuan_nik = ?";
        $stmt = mysqli_prepare($conn, $sqlDel);
        mysqli_stmt_bind_param($stmt, "is", $id, $nik);
    }

    mysqli_stmt_execute($stmt);
    header("Location: surat_pengajuan.php");
    exit();
}


// Cek apakah user punya akses ke fitur E-Office
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
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$sqlList = "SELECT * FROM surat_pengajuan WHERE $scopeWhere ORDER BY id DESC";
$stmtList = mysqli_prepare($conn, $sqlList);
if (!empty($scopeParams)) {
  $types = str_repeat('s', count($scopeParams));
  mysqli_stmt_bind_param($stmtList, $types, ...$scopeParams);
}
mysqli_stmt_execute($stmtList);
$result = mysqli_stmt_get_result($stmtList);

// DROPDOWN DISTINCT: No. Surat
$sqlNoSurat = "SELECT DISTINCT no_surat FROM surat_pengajuan WHERE $scopeWhere ORDER BY no_surat";
$stmtNoSurat = mysqli_prepare($conn, $sqlNoSurat);
if (!empty($scopeParams)) {
  $types = str_repeat('s', count($scopeParams));
  mysqli_stmt_bind_param($stmtNoSurat, $types, ...$scopeParams);
}
mysqli_stmt_execute($stmtNoSurat);
$noSurat = mysqli_stmt_get_result($stmtNoSurat);

// DROPDOWN DISTINCT: Dari
$sqlDari = "SELECT DISTINCT dari FROM surat_pengajuan WHERE $scopeWhere ORDER BY dari";
$stmtDari = mysqli_prepare($conn, $sqlDari);
if (!empty($scopeParams)) {
  $types = str_repeat('s', count($scopeParams));
  mysqli_stmt_bind_param($stmtDari, $types, ...$scopeParams);
}
mysqli_stmt_execute($stmtDari);
$noDari = mysqli_stmt_get_result($stmtDari);

$sqlArahan = "SELECT DISTINCT arahan FROM surat_pengajuan WHERE $scopeWhere ORDER BY arahan";
$stmtArahan = mysqli_prepare($conn, $sqlArahan);
if (!empty($scopeParams)) {
  $types = str_repeat('s', count($scopeParams));
  mysqli_stmt_bind_param($stmtArahan, $types, ...$scopeParams);
}
mysqli_stmt_execute($stmtArahan);
$arahanRes = mysqli_stmt_get_result($stmtArahan);

// Proses input data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil data input
    $tanggal = $_POST['tanggal'];
    $no_surat = $_POST['no_surat'];
    $dari = $_POST['dari'];
    $arahanVal = $_POST['arahan'];    
    // Upload file
    $orig = $_FILES["file_url"]["name"];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { echo "File harus PDF"; exit; }

    $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/','_', pathinfo($orig, PATHINFO_FILENAME));
    $file_name = $safeBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';

    $target_dir  = __DIR__ . "/uploads/";
    $file_path   = $target_dir . $file_name;
    $public_path = "uploads/" . $file_name;


    // Validasi file
    $allowed_types = ['application/pdf'];
    if (in_array($_FILES['file_url']['type'], $allowed_types) && $_FILES['file_url']['size'] <= 5 * 1024 * 1024) { // max 5 MB
        if (move_uploaded_file($_FILES["file_url"]["tmp_name"], $file_path)) {
            $stmt = mysqli_prepare(
              $conn,
              "INSERT INTO surat_pengajuan
              (tanggal, no_surat, dari, arahan, file_url,
                pengajuan_nik, pengajuan_nama, pengajuan_unit, pengajuan_status)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param(
              $stmt, "sssssssss",
              $tanggal, $no_surat, $dari, $arahanVal, $file_name,
              $nik, $nama, $unit, $status
            );
            if (mysqli_stmt_execute($stmt)) {
                $newId = mysqli_insert_id($conn);
                // panggil notifikasi
                buat_notif_dari_surat_pengajuan($pdo, (int)$newId, 'create', $nik, $unit);

                header('Location: surat_pengajuan.php');
                exit();
            }
             else {
                echo "Gagal menyimpan surat: " . mysqli_error($conn);
            }
        } else {
            echo "Gagal mengunggah file.";
        }
    } else {
        echo "File harus PDF dan maksimal 5MB.";
    }
}

$loginUserId = (int)($user['user']['id'] ?? $user['id'] ?? 0);
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Surat Pengajuan</title>
  <link rel="stylesheet" href="style.css" />
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'> <!-- untuk ikon footer -->
  <style>
  @media print {
  .no-export {
    display: none;
  }
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
    margin-bottom: 10px;
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
  display: block;
  width: 20px;
  }

#tabelSuratPengajuan th .no-export select {
  width: 25px;
  height: 22px;
  font-size: 13px;
  border: 1px solid #d7d7d7;
  border-radius: 6px;
  background: #fff;
  box-sizing: border-box;
  cursor: pointer;
}

#tabelSuratPengajuan th .no-export select:focus {
  outline: none;
  border-color: #5b9bff;
  box-shadow: 0 0 0 3px rgba(91,155,255,.15);
}

/* jaga jarak dengan judul kolom */
#tabelSuratPengajuan th .no-export { 
  margin-top: 6px;
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

/* === Export dropdown === */
.export-dropdown { position: relative; display: inline-block; }
.export-toggle { cursor: pointer; }
.export-menu {
  position: absolute; top: 100%; left: 0;
  display: none; min-width: 210px; padding: 6px;
  background:#fff; border:1px solid #ddd; border-radius:8px;
  box-shadow:0 6px 20px rgba(0,0,0,.08); z-index: 10;
}
.export-item {
  display:block; width:100%; text-align:left;
  padding:8px 10px; background:transparent; border:none; cursor:pointer;
}
.export-item:hover { background:#f2f6ff; }
.export-dropdown.open .export-menu { display:block; }
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

.btn-chat{ background:#eef4ff; border:1px solid #cfe0ff; padding:6px 10px; border-radius:6px; cursor:pointer; }
.chat-container{ display:flex; flex-direction:column; gap:10px; padding:10px; }
.chat-bubble{ max-width:60%; padding:10px 14px; border-radius:14px; margin-bottom:8px; }
.chat-bubble.left{ background:#f3f4f6; border-top-left-radius:4px; }
.chat-bubble.right{ background:#dbeafe; border-top-right-radius:4px; margin-left:auto; }
.chat-time{ font-size:.75em; color:#6b7280; margin-top:4px; text-align:right; }

  </style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.5/xlsx.full.min.js"></script>
</head>
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
          <i class="bx bxs-user-circle user-icon" onclick="toggleUserDropdown()"></i>
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
    </div>
  </nav>

<!-- Judul di luar kontainer -->
<h2 class="judul-surat-luar">Daftar Surat Pengajuan</h2>

<div class="kontainer-balok">
    <!-- Balok 1: Tombol Upload dan Export -->
    <div class="export-dropdown">
      <a href="buat_surat_pengajuan.php" class="btn-tambah">Upload</a>
      <button type="button" class="btn-tambah export-toggle">Export ▾</button>
      <div class="export-menu">
        <button type="button" class="export-item" onclick="exportTableToExcel(false)">Export All</button>
        <button type="button" class="export-item" onclick="exportTableToExcel(true)">Export 3 Bulan Terakhir</button>
      </div>
    </div>


    <!-- Balok 2: Search Bar -->
    <div class="balok-2">
        <div class="search-bar">
            <input type="text" placeholder="Cari..." id="searchInput" oninput="searchTable()" />
            <button onclick="searchTable()">Cari</button>
        </div>
    </div>

    <!-- Balok 3: Tabel Data Surat -->
    <div class="balok-3">
      <table id="tabelSuratPengajuan" border="1" cellpadding="10" cellspacing="0">
        <thead>
          <tr>
            <th>
              Tanggal
              <div class="form-group">
                <input type="date" id="tanggal" name="tanggal"
                      onchange="filterTable('tanggal', this.value); showResetButton();" />
              </div>
            </th>
            <th>
              No. Surat
              <div class="no-export">
                <select onchange="filterTable('nop', this.value); showResetButton();">
                  <option value="">Semua</option>
                  <?php while ($row = mysqli_fetch_assoc($noSurat)): ?>
                    <option value="<?= htmlspecialchars($row['no_surat']) ?>">
                      <?= htmlspecialchars($row['no_surat']) ?>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>
            </th>
            <th>
              Dari
              <div class="no-export">
                <select onchange="filterTable('dari', this.value); showResetButton();">
                  <option value="">Semua</option>
                  <?php while ($row = mysqli_fetch_assoc($noDari)): ?>
                    <option value="<?= htmlspecialchars($row['dari']) ?>">
                      <?= htmlspecialchars($row['dari']) ?>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>
            </th>
            <th>
              Instruksi
              <div class="no-export">
                <select onchange="filterTable('arahan', this.value); showResetButton();">
                  <option value="">Semua</option>
                  <?php while ($row = mysqli_fetch_assoc($arahanRes)): ?>
                    <option value="<?= htmlspecialchars($row['arahan']) ?>">
                      <?= htmlspecialchars($row['arahan']) ?>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>
            </th>
            <th>File</th>
            <th>Pesan</th>
            <th>Aksi</th>
          </tr>
        </thead>

        <tbody>
        <?php if ($result && mysqli_num_rows($result) > 0): ?>
          <?php while ($row = mysqli_fetch_assoc($result)): ?>
            <tr class="data-row"
                data-tanggal="<?= htmlspecialchars($row['tanggal'] ?? '') ?>"
                data-nop="<?= htmlspecialchars($row['no_surat'] ?? '') ?>"
                data-dari="<?= htmlspecialchars($row['dari'] ?? '') ?>"
                data-arahan="<?= htmlspecialchars($row['arahan'] ?? '') ?>">
              <td><?= htmlspecialchars($row['tanggal'] ?? '') ?></td>
              <td><?= htmlspecialchars($row['no_surat'] ?? '') ?></td>
              <td><?= htmlspecialchars($row['dari'] ?? '') ?></td>
              <td><?= htmlspecialchars($row['arahan'] ?? '') ?></td>

              <!-- File (sekali saja) -->
              <td>
                <?php if (!empty($row['file_url'])): ?>
                  <a href="<?= htmlspecialchars($row['file_url']) ?>" target="_blank">
                    <?= htmlspecialchars(basename($row['file_url'])) ?>
                  </a>
                <?php else: ?>
                  -
                <?php endif; ?>
              </td>
              <td>
              <a class="btn-chat"
                href="pesan_lihat_1.php?no_surat=<?= urlencode($row['no_surat']) ?>"
                title="Buka halaman pesan untuk <?= htmlspecialchars($row['no_surat']) ?>">
                💬
              </a>
              </td>
              <!-- Aksi -->
              <td>
                <a href="update_surat_pengajuan.php?id=<?= (int)($row['id'] ?? 0) ?>">✏️</a><br>
                <a href="surat_pengajuan.php?delete=<?= (int)($row['id'] ?? 0) ?>"
                  onclick="return confirm('Hapus pengajuan ini?')">🗑️</a>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="7" style="text-align:center;opacity:.7;">Data tidak ditemukan.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>



    <!-- Tombol Reset -->
    <div id="reset-container" style="display: none; text-align: right; margin-top: 15px;">
        <button onclick="resetFilters()" style="background-color:#dc3545; color:white; padding: 8px 16px; border:none; border-radius:5px;">Reset</button>
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
  <script src="script.js"></script>
  <script>
    
  function filterTable(attribute, value) {
    const rows = document.querySelectorAll('.data-row');
    const filterValue = (value || '').toLowerCase();
    rows.forEach(row => {
      const cellValue = (row.getAttribute('data-' + attribute) || '').toLowerCase();
      row.style.display = (!filterValue || cellValue.includes(filterValue)) ? "table-row" : "none";
    });
  }

(function(){
  const dd  = document.querySelector('.export-dropdown');
  const btn = dd?.querySelector('.export-toggle');
  if (btn) {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      dd.classList.toggle('open');
    });
    document.addEventListener('click', () => dd.classList.remove('open'));
  }
})();

function exportTableToExcel(last3Months) {
  if (typeof XLSX === 'undefined') { alert('Library XLSX belum ter-load.'); return; }

  const src = document.getElementById("tabelSuratPengajuan");
  if (!src) { alert("Tabel tidak ditemukan"); return; }

  // clone tabel agar aman dimodif
  const table = src.cloneNode(true);

  // buang elemen yang tidak perlu diexport
  table.querySelectorAll(".no-export, select, form, button").forEach(el => el.remove());

  const DATE_ATTR = 'tanggal';
  function parseFlexibleDate(str){
    if (!str) return null;
    str = String(str).trim();
    if (str.length >= 10) str = str.slice(0,10);
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      const [y,m,d] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;
    }
    if (/^\d{2}-\d{2}-\d{4}$/.test(str)) {
      const [d,m,y] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;
    }
    return null;
  }

  if (last3Months) {
    // rolling 90 hari terakhir dari hari ini
    const today = new Date();
    const start = new Date(today.getTime() - 90*24*60*60*1000);
    const end   = today;

    table.querySelectorAll("tr.data-row").forEach(tr => {
      const raw = tr.getAttribute("data-" + DATE_ATTR) || "";
      const dt  = parseFlexibleDate(raw);
      if (!dt || dt < start || dt > end) tr.remove();
    });
  }

  // buang kolom 'File' dan 'Aksi' (berdasarkan judul th)
  const header = table.querySelector("tr");
  const ths = Array.from(header.children);
  const removeIdx = ths
    .map((th, i) => [ (th.textContent || '').trim().split('\n')[0], i ])
    .filter(([t]) => t === "File" || t === "Pesan" || t === "Aksi")
    .map(([, i]) => i);

  table.querySelectorAll("tr").forEach(tr => {
    Array.from(tr.children).forEach((td, i) => {
      if (removeIdx.includes(i)) td.remove();
    });
  });

  // buat workbook dari tabel
  const wb = XLSX.utils.table_to_book(table, { sheet: "Surat Pengajuan" });
  const ws = wb.Sheets["Surat Pengajuan"];

  // auto width kolom sederhana berdasarkan header
  const firstRow = XLSX.utils.sheet_to_json(ws, { header: 1 })[0] || [];
  ws['!cols'] = firstRow.map(h => ({ wch: Math.min(Math.max(String(h||'').length + 2, 12), 40) }));

  XLSX.writeFile(wb, `surat_pengajuan${last3Months ? '_3 bulan terakhir' : '_all'}.xlsx`);

  // tutup dropdown
  document.querySelector('.export-dropdown')?.classList.remove('open');
}

    function resetFilters() {
      // Reset semua dropdown
      const selects = document.querySelectorAll('select');
      selects.forEach(select => {
        select.selectedIndex = 0;
      });

      // Reset input tanggal
      const dateInput = document.getElementById('tanggal');
      if (dateInput) dateInput.value = '';

      // Tampilkan semua baris
      const rows = document.querySelectorAll('.data-row');
      rows.forEach(row => {
        row.style.display = "table-row";
      });

      // Sembunyikan tombol reset
      const resetContainer = document.getElementById("reset-container");
      if (resetContainer) {
        resetContainer.style.display = "none";
      }
    }
  function searchTable() {
  const input = document.getElementById("searchInput").value.toLowerCase();
  const rows = document.querySelectorAll("#tabelSuratPengajuan .data-row");

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

  // Tampilkan tombol reset jika ada input
  const resetContainer = document.getElementById("reset-container");
  if (input.length > 0) {
    resetContainer.style.display = "block";
  }
}

function showResetButton() {
  const resetContainer = document.getElementById('reset-container');
  if (!resetContainer) return;

  const search = document.getElementById('searchInput');
  const selects = document.querySelectorAll('#tabelSuratPengajuan th select');
  const dates   = document.querySelectorAll('#tabelSuratPengajuan th input[type="date"]');

  const hasSearch = !!(search && search.value.trim().length > 0);
  const hasSelect = Array.from(selects).some(s => (s.value || '').trim() !== '');
  const hasDate   = Array.from(dates).some(i => (i.value || '').trim() !== '');

  resetContainer.style.display = (hasSearch || hasSelect || hasDate) ? 'block' : 'none';
}

// Hook semua kontrol agar memanggil showResetButton
document.addEventListener('DOMContentLoaded', () => {
  const search = document.getElementById('searchInput');
  const selects = document.querySelectorAll('#tabelSuratPengajuan th select');
  const dates   = document.querySelectorAll('#tabelSuratPengajuan th input[type="date"]');

  if (search) search.addEventListener('input', showResetButton);
  selects.forEach(s => s.addEventListener('change', showResetButton));
  dates.forEach(i => i.addEventListener('change', showResetButton));

  // panggil sekali saat load untuk set keadaan awal
  showResetButton();
});
  </script>
</script>  
</body>
</html>