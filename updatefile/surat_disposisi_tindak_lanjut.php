<?php
session_start();
include 'config.php';

// Cek login
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// deteksi AJAX
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_disposisi']) || ($_POST['aksi'] ?? '') === 'update_disposisi')) {
    $id  = (int)($_POST['id_tindaklanjut'] ?? 0);
    $val = trim((string)($_POST['disposisi_kepada'] ?? ''));

    // dukung "lain-lain"
    if ($val === 'lain') {
        $val = trim((string)($_POST['disposisi_kepada_lain'] ?? ''));
    }

    if ($id <= 0 || $val === '') {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'Param tidak lengkap']);
            exit;
        }
        $_SESSION['flash'] = 'Param tidak lengkap';
        header('Location: surat_disposisi_tindak_lanjut.php'); exit;
    }

    try {
        $pdo->beginTransaction();

        // update baris ini
        $pdo->prepare("
            UPDATE surat_disposisi_tindak_lanjut
               SET disposisi_kepada = ?
             WHERE id_tindaklanjut = ?
        ")->execute([$val, $id]);

        // ambil no_surat untuk sync saudara kembar no_surat yang sama (kalau memang ada duplikat baris TL)
        $r = $pdo->prepare("SELECT no_surat, file_url FROM surat_disposisi_tindak_lanjut WHERE id_tindaklanjut=?");
        $r->execute([$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // samakan semua baris dengan no_surat yang sama
            $pdo->prepare("UPDATE surat_disposisi_tindak_lanjut SET disposisi_kepada=? WHERE no_surat=?")
                ->execute([$val, $row['no_surat']]);

            // buat notifikasi (opsional)
            $pdo->prepare("INSERT INTO surat_notif (tanggal,no_surat,file_url,waktu) VALUES (?,?,?,NOW())")
                ->execute([date('Y-m-d'), $row['no_surat'], $row['file_url']]);
        }

        $pdo->commit();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true]);
            exit;
        }

        $_SESSION['flash'] = 'Disposisi diperbarui';
        header('Location: surat_disposisi_tindak_lanjut.php'); exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
            exit;
        }
        $_SESSION['flash'] = 'Gagal: '.$e->getMessage();
        header('Location: surat_disposisi_tindak_lanjut.php'); exit;
    }
}
// ================== UPDATE NOTE VIA AJAX ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['save_note_tl']))
    && isset($_POST['id_tindaklanjut'])) {

    // Supaya respon bukan HTML penuh
    header('Content-Type: text/plain; charset=utf-8');

    $id_tl = (int)($_POST['id_tindaklanjut'] ?? 0);
    $note  = trim(substr($_POST['note'] ?? '', 0, 255));

    if ($id_tl <= 0) {
        echo 'invalid';
        exit;
    }

    try {
        $pdo->beginTransaction();

        // ambil no_surat untuk sync massal
        $q = $pdo->prepare("
            SELECT no_surat
            FROM surat_disposisi_tindak_lanjut
            WHERE id_tindaklanjut = ?
            LIMIT 1
        ");
        $q->execute([$id_tl]);
        $rowNS = $q->fetch(PDO::FETCH_ASSOC);
        $noSuratSync = $rowNS['no_surat'] ?? null;

        // update note baris ini
        $u = $pdo->prepare("
            UPDATE surat_disposisi_tindak_lanjut
               SET note = ?
             WHERE id_tindaklanjut = ?
        ");
        $u->execute([$note, $id_tl]);

        $pdo->commit();
        echo 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo 'error: '.$e->getMessage();
    }

    exit; // WAJIB stop supaya gak kirim HTML halaman penuh
}


if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $pdo->prepare("DELETE FROM surat_disposisi_tindak_lanjut WHERE id_tindaklanjut = ?")
            ->execute([$id]);
        $_SESSION['flash'] = 'Data dihapus';
    }
    header('Location: surat_disposisi_tindak_lanjut.php');
    exit;
}

$user  = $_SESSION['user'];
$role  = $user['status'] ?? null;
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
$pengirim = $user['nama'] ?? '';


// ================== SYNC DATA DARI SURAT_DISPOSISI ==================
// 1. Ambil kolom note juga
$stmtDisposisi = $pdo->prepare("
    SELECT id, tanggal, no_surat, file_url, instruksi, note
    FROM surat_disposisi
    WHERE LOWER(instruksi) LIKE 'diteruskan'
");
$stmtDisposisi->execute();
$dataDisposisi = $stmtDisposisi->fetchAll(PDO::FETCH_ASSOC);

foreach ($dataDisposisi as $row) {
    $cek = $pdo->prepare("SELECT COUNT(*) FROM surat_disposisi_tindak_lanjut WHERE no_surat = ?");
    $cek->execute([$row['no_surat']]);

    if ($cek->fetchColumn() == 0) {
        // 2. Masukkan note ke tabel tindak lanjut
        $insert = $pdo->prepare("
            INSERT INTO surat_disposisi_tindak_lanjut (tanggal, no_surat, file_url, note)
            VALUES (?, ?, ?, ?)
        ");
        $insert->execute([
            $row['tanggal'],
            $row['no_surat'],
            $row['file_url'],
            $row['note'] ?? '' // kalau null, simpan string kosong
        ]);
    }
}


$noSuratTL = $pdo->query("SELECT DISTINCT no_surat FROM surat_disposisi_tindak_lanjut ORDER BY no_surat ASC")->fetchAll(PDO::FETCH_COLUMN);
$disposisiTL = $pdo->query("SELECT DISTINCT disposisi_kepada FROM surat_disposisi_tindak_lanjut WHERE disposisi_kepada IS NOT NULL AND disposisi_kepada<>'' ORDER BY disposisi_kepada ASC")->fetchAll(PDO::FETCH_COLUMN);
$stmt = $pdo->query("
    SELECT tl.*,
           d.note AS note_disposisi
    FROM surat_disposisi_tindak_lanjut tl
    LEFT JOIN surat_disposisi d
      ON d.id = (
        SELECT d2.id
        FROM surat_disposisi d2
        WHERE d2.no_surat = tl.no_surat
        ORDER BY d2.id DESC
        LIMIT 1
      )
    ORDER BY tl.tanggal DESC
");
$semuaData = $stmt->fetchAll(PDO::FETCH_ASSOC);
$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Ruang Baca Virtual</title> 
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
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
    width: 220px; 
    font-size: 14px;
    padding: 4px 6px;
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

#tabelSuratDisposisiTindakLanjut th .no-export select {
  width: 20px;
  height: 22px;
  font-size: 13px;
  border: 1px solid #d7d7d7;
  border-radius: 6px;
  background: #fff;
  box-sizing: border-box;
  cursor: pointer;
}

#tabelSuratDisposisiTindakLanjut th .no-export select:focus {
  outline: none;
  border-color: #5b9bff;
  box-shadow: 0 0 0 3px rgba(91,155,255,.15);
}

/* jaga jarak dengan judul kolom */
#tabelSuratDisposisiTindakLanjut th .no-export { 
  margin-top: 6px;
}
/* Export dropdown */
.export-dropdown{ position:relative; display:inline-block; }
.export-toggle{ cursor:pointer; }

/* Sembunyikan menu saat tidak open */
.export-dropdown:not(.open) .export-menu{ display:none !important; }

.export-menu{
  position:absolute; top:110%; left:0;
  min-width:220px; padding:8px;
  background:#fff; border:1px solid #e5e7eb; border-radius:10px;
  box-shadow:0 12px 30px rgba(0,0,0,.12); z-index:1000;
}

.export-item{
  display:block; width:100%; text-align:left;
  padding:10px 12px; background:transparent; border:0; cursor:pointer;
}
.export-item:hover{ background:#f3f7ff; }
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
<h2 class="judul-surat-luar">Disposisi Tindak Lanjut</h2>

<!-- Buka kontainer utama -->
<div class="kontainer-balok">

    <!-- Balok 1 -->
    <div class="balok-1">
      <div class="export-dropdown">
        <button type="button" class="btn-tambah export-toggle">Export ▾</button>
        <div class="export-menu">
          <button type="button" class="export-item" onclick="exportTableToExcel(false)">Export All</button>
          <button type="button" class="export-item" onclick="exportTableToExcel(true)">Export 3 Bulan Terakhir</button>
        </div>
      </div>
    </div>


    <!-- Balok 2: Search Bar -->
    <div class="balok-2">
        <div class="search-bar">
            <input type="text" placeholder="Cari surat..." id="searchInput" oninput="searchTable()" />
            <button>Cari</button>
        </div>
    </div>

<!-- TABEL -->
<div class="table-container">
    <table id="tabelSuratDisposisiTindakLanjut" border="1" cellpadding="10" cellspacing="0">
        <thead>
          <tr>
            <th>No</th>
            <th>
              Tanggal
              <div class="form-group no-export">
                <input type="date" id="flt-tanggal-tl"
                      onchange="applyTLFilters(); showTLReset();" />
              </div>
            </th>
            <th>
              No Surat
              <div class="no-export">
                <select id="flt-nosurat-tl" onchange="applyTLFilters(); showTLReset();">
                  <option value=""></option>
                  <?php foreach ($noSuratTL as $ns): ?>
                    <option value="<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($ns) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </th>
            <th>File</th>
            <th>
              Note
              <div class="no-export">
              </div>
            </th>
            <th>Chat</th>
            <th>
              Disposisi Kepada
              <div class="no-export">
                <select id="flt-disposisi-tl" onchange="applyTLFilters(); showTLReset();">
                  <option value=""></option>
                  <?php foreach ($disposisiTL as $dk): ?>
                    <option value="<?= htmlspecialchars($dk) ?>"><?= htmlspecialchars($dk) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($semuaData)): ?>
            <?php $no = 1; foreach ($semuaData as $row): ?>
                <?php
                // tentukan note khusus untuk baris ini
                $noteThisRow = ($row['note'] !== '' && $row['note'] !== null)
                    ? $row['note']
                    : ($row['note_disposisi'] ?? '');
                ?>
                <tr class="data-row"
                    data-tanggal="<?= htmlspecialchars($row['tanggal'] ?? '') ?>"
                    data-no_surat="<?= htmlspecialchars($row['no_surat'] ?? '') ?>"
                    data-disposisi_kepada="<?= htmlspecialchars($row['disposisi_kepada'] ?? '') ?>"
                    data-note="<?= htmlspecialchars($noteForAttr) ?>"    
                    data-id="<?= (int)$row['id_tindaklanjut'] ?>">
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['tanggal'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['no_surat'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($row['file_url'])): ?>
                            <a href="<?= htmlspecialchars($row['file_url']) ?>" target="_blank">Lihat File</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td style="min-width:220px;">
                      <textarea
                        id="note-view-<?= (int)$row['id_tindaklanjut'] ?>"
                        class="note-view-td"
                        readonly
                        placeholder="..."
                        style="width:100%; min-height:60px; resize:vertical; background:#fff;"
                      ><?= htmlspecialchars($noteThisRow) ?></textarea>

                      <div class="no-export" style="margin-top:6px;">
                        <button
                          type="button"
                          style="padding:4px 8px; font-size:12px; border:1px solid #999; border-radius:4px; cursor:pointer;"
                          onclick="editNoteTL(<?= (int)$row['id_tindaklanjut'] ?>)">
                          Edit
                        </button>
                        <button
                          type="button"
                          style="padding:4px 8px; font-size:12px; border:1px solid #28a745; color:#fff; background:#28a745; border-radius:4px; cursor:pointer; display:none;"
                          id="save-note-btn-<?= (int)$row['id_tindaklanjut'] ?>"
                          onclick="saveNoteTL(<?= (int)$row['id_tindaklanjut'] ?>)">
                          Simpan
                        </button>
                      </div>
                    </td>
                    <td>
                      <?php if (!empty($row['disposisi_kepada'])): ?>
                        <a class="btn-chat" href="pesan_lihat.php?no_surat=<?= urlencode($row['no_surat']) ?>">💬</a>
                      <?php else: ?>
                        <button class="btn-chat" disabled title="Isi disposisi dulu">💬</button>
                      <?php endif; ?>
                    </td>
                    <td>
                    <div style="margin-bottom: 5px;">
                      <?= htmlspecialchars($row['disposisi_kepada'] ?? '-') ?>
                    </div>
                      <form method="POST" action="surat_disposisi_tindak_lanjut.php">
                        <input type="hidden" name="id_tindaklanjut" value="<?= $row['id_tindaklanjut'] ?>">
                        <input type="hidden" name="update_disposisi" value="1">
                        <select name="disposisi_kepada" onchange="this.form.requestSubmit()">
                          <option value="">---Pilih---</option>
                          <?php
                          $opsi_disposisi = [
                            "GUDANG FARMASI", "GUDANG LOGISTIK", "GUDANG FIX ASET", "FARMASI RAWAT JALAN", "FARMASI RAWAT INAP",
                            "POLI KLINIK RAWAT JALAN", "INSTALASI GAWAT DARURAT", "RADIOLOGI", "LABORATORIUM", "NS ROSALINA",
                            "NS TERATAI", "NS ANTURIUM", "NS ALAMANDA", "NS BERSALIN", "NS PERINATOLOGI", "UMUM RT", "ICU", "OK",
                            "KEPERAWATAN", "KEUANGAN", "TPP", "IT", "GIZI", "HEMODIALISA", "LAUNDRY + KEBERSIHAN", "KEPEGAWAIAN & DIKLAT",
                            "MARKETING", "INFORMASI & KOMPLAIN", "YANJANGMED", "TIM PMKP", "TIM PPI", "TIM K3", "DIREKSI",
                            "REKAM MEDIS", "AKUNTANSI & PERPAJAKAN", "SEKRETARIAT", "CLEANING SERVICE (CS)", "DRIVER & SECURITY",
                            "KASIR RAWAT INAP", "KASIR RAWAT JALAN", "TIM PENGENDALI BPJS (Casemix)", "NS LOTUS", "NS TULIP",
                            "KOMITE KEPERAWATAN", "TIM PKRS"
                          ];
                          foreach ($opsi_disposisi as $opsi) {
                              $selected = $row['disposisi_kepada'] == $opsi ? 'selected' : '';
                              echo "<option value=\"$opsi\" $selected>$opsi</option>";
                          }
                          ?>
                        </select>
                      </form>
                    </td>
                    <td>
                        <!-- <a href="update_surat_disposisi_tindak_lanjut.php?id=<?= urlencode($row['id_tindaklanjut'] ?? '') ?>">✏️</a><br> -->
                        <a href="surat_disposisi_tindak_lanjut.php?delete=<?= urlencode($row['id_tindaklanjut'] ?? '') ?>" onclick="return confirm('Hapus surat ini?')">🗑️</a>
                    </td>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="7">Data tidak ditemukan</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Reset Button -->
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
(() => {
  const dd  = document.querySelector('.export-dropdown');
  if (!dd) return;
  const btn = dd.querySelector('.export-toggle');
  if (!btn) return;

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    dd.classList.toggle('open');
  });
  document.addEventListener('click', (e) => {
    if (!dd.contains(e.target)) dd.classList.remove('open');
  });
})();



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
  const rows = document.querySelectorAll("#tabelSuratDisposisiTindakLanjut .data-row");

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

function setDisposisi(selectElement, rowId) {
    const selectedValue   = selectElement.value;
    const dataRow         = selectElement.closest('.data-row');                 
    const noSurat         = dataRow.dataset.noSurat;                            
    const idSurat         = rowId;                                             
    const lainnyaContainer= document.getElementById('lainnya-container-' + rowId);
    const inputLain       = document.getElementById('disposisi_kepada_lain-' + rowId);


    if (selectedValue === 'lain') {
        lainnyaContainer.style.display = 'block';
        inputLain.setAttribute('required', 'required');
        
        // Tambahkan event listener untuk input "lain-lain"
        inputLain.onchange = function() {
            updateDisposisi(idSurat, this.value, noSurat, dataRow);
        };
    } else {
        lainnyaContainer.style.display = 'none';
        inputLain.removeAttribute('required');
        inputLain.value = '';
        
        // Langsung update ke server untuk pilihan selain "lain-lain"
        updateDisposisi(idSurat, selectedValue, noSurat, dataRow);
    }
}

function updateDisposisi(idSurat, disposisiValue, noSurat, dataRow) {
    fetch('surat_disposisi_tindak_lanjut.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `aksi=update_disposisi&id_tindaklanjut=${encodeURIComponent(idSurat)}&disposisi_kepada=${encodeURIComponent(disposisiValue)}`
    })
    .then(async (res) => {
        const raw = await res.text();
        const clean = raw.replace(/^\uFEFF/, '').trim();
        let data;
        try { data = JSON.parse(clean); } 
        catch { data = { ok: (clean === 'OK') }; } 
        return data;
    })
    .then(data => {
        if (data.ok) {
            const disposisiTd = dataRow.querySelector('td:nth-child(6)');
            disposisiTd.innerHTML = '';

            const valueDisplay = document.createElement('span');
            valueDisplay.textContent = disposisiValue;
            valueDisplay.style.marginRight = '10px';
            valueDisplay.style.fontWeight = 'bold';
            disposisiTd.appendChild(valueDisplay);

            const editButton = document.createElement('button');
            editButton.textContent = '✏️';
            editButton.onclick = function() {
                showEditForm(disposisiTd, idSurat, noSurat, dataRow, disposisiValue);
            };
            disposisiTd.appendChild(editButton);

            const chatBtn = dataRow.querySelector('.btn-chat');
            if (chatBtn && chatBtn.disabled) {
                chatBtn.disabled = false;
                chatBtn.removeAttribute('title');
                chatBtn.onclick = function () { toggleChatBoxFromBtn(chatBtn); };
            }

            alert('Disposisi berhasil diperbarui!');
        } else {
            alert('Gagal memperbarui disposisi: ' + (data.error || 'unknown'));
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan jaringan: ' + err.message);
    });
}

function showEditForm(disposisiTd, idSurat, noSurat, dataRow, currentValue) {
    disposisiTd.innerHTML = '';
    
    const select = document.createElement('select');
    select.innerHTML = `
        <option value="GUDANG FARMASI">GUDANG FARMASI</option>
        <option value="GUDANG LOGISTIK">GUDANG LOGISTIK</option>
        <option value="GUDANG FIX ASET">GUDANG FIX ASET</option>
        <option value="FARMASI RAWAT JALAN">FARMASI RAWAT JALAN</option>
        <option value="FARMASI RAWAT INAP">FARMASI RAWAT INAP</option>
        <option value="POLI KLINIK RAWAT JALAN">POLI KLINIK RAWAT JALAN</option>
        <option value="INSTALASI GAWAT DARURAT">INSTALASI GAWAT DARURAT</option>
        <option value="RADIOLOGI">RADIOLOGI</option>
        <option value="LABORATORIUM">LABORATORIUM</option>
        <option value="NS ROSALINA">NS ROSALINA</option>
        <option value="NS TERATAI">NS TERATAI</option>
        <option value="NS ANTURIUM">NS ANTURIUM</option>
        <option value="NS ALAMANDA">NS ALAMANDA</option>
        <option value="NS BERSALIN">NS BERSALIN</option>
        <option value="NS PERINATOLOGI">NS PERINATOLOGI</option>
        <option value="UMUM RT">UMUM RT</option>
        <option value="ICU">ICU</option>
        <option value="OK">OK</option>
        <option value="KEPERAWATAN">KEPERAWATAN</option>
        <option value="KEUANGAN">KEUANGAN</option>
        <option value="TPP">TPP</option>
        <option value="IT">IT</option>
        <option value="GIZI">GIZI</option>
        <option value="HEMODIALISA">HEMODIALISA</option>
        <option value="LAUNDRY + KEBERSIHAN">LAUNDRY + KEBERSIHAN</option>
        <option value="KEPEGAWAIAN & DIKLAT">KEPEGAWAIAN & DIKLAT</option>
        <option value="MARKETING">MARKETING</option>
        <option value="INFORMASI & KOMPLAIN">INFORMASI & KOMPLAIN</option>
        <option value="YANJANGMED">YANJANGMED</option>
        <option value="TIM PMKP">TIM PMKP</option>
        <option value="TIM PPI">TIM PPI</option>
        <option value="TIM K3">TIM K3</option>
        <option value="DIREKSI">DIREKSI</option>
        <option value="REKAM MEDIS">REKAM MEDIS</option>
        <option value="AKUNTANSI & PERPAJAKAN">AKUNTANSI & PERPAJAKAN</option>
        <option value="SEKRETARIAT">SEKRETARIAT</option>
        <option value="CLEANING SERVICE (CS)">CLEANING SERVICE (CS)</option>
        <option value="DRIVER & SECURITY">DRIVER & SECURITY</option>
        <option value="KASIR RAWAT INAP">KASIR RAWAT INAP</option>
        <option value="KASIR RAWAT JALAN">KASIR RAWAT JALAN</option>
        <option value="TIM PENGENDALI BPJS (Casemix)">TIM PENGENDALI BPJS (Casemix)</option>
        <option value="NS LOTUS">NS LOTUS</option>
        <option value="NS TULIP">NS TULIP</option>
        <option value="KOMITE KEPERAWATAN">KOMITE KEPERAWATAN</option>
        <option value="TIM PKRS">TIM PKRS</option>
        <option value="lain">Lain-lain</option>
    `;
    select.value = currentValue;
    
    select.onchange = function() {
        setDisposisi(this, dataRow.dataset.id);
    };
    
    disposisiTd.appendChild(select);
    if (currentValue === 'lain') {
        const lainContainer = document.createElement('div');
        lainContainer.innerHTML = `
            <input type="text" 
                value="${currentValue}" 
                placeholder="Masukkan tujuan lain..." 
                onchange="updateDisposisi('${idSurat}', this.value, '${noSurat}', ${JSON.stringify(dataRow.dataset).replace(/"/g, '&quot;')})" />
        `;
        disposisiTd.appendChild(lainContainer);
    }
}

function handleLainTL(selectEl, inputId) {
  const input = document.getElementById(inputId);
  if (!input) return;
  if (selectEl.value === 'lain') {
    input.style.display = 'block';
    input.required = true;
    selectEl.onchange = null;
  } else {
    input.style.display = 'none';
    input.required = false;
  }
}

function onChangeDisposisiTL(selectEl, inputId) {
  const input = document.getElementById(inputId);
  if (!input) return;

  if (selectEl.value === 'lain') {
    input.style.display = 'block';
    input.required = true;
    input.focus();
  } else {
    input.style.display = 'none';
    input.required = false;
    if (typeof selectEl.form.requestSubmit === 'function') {
      selectEl.form.requestSubmit();
    } else {
      selectEl.form.submit();
    }
  }
}

function toggleChatBoxFromBtn(btn) {
  const dataRow = btn.closest('tr');
  if (!dataRow) return;

  // Baris chat harus tepat setelah baris data
  const chatRow = dataRow.nextElementSibling;
  if (!chatRow) return;

  const chatBox = chatRow.querySelector('.chat-box');
  if (!chatBox) return;

  const currentlyHidden = (chatRow.style.display === 'none' || !chatRow.style.display);
  chatRow.style.display = currentlyHidden ? 'table-row' : 'none';

  if (currentlyHidden) {
    const noSurat = btn.dataset.noSurat || chatRow.dataset.noSurat || dataRow.dataset.noSurat;
    if (noSurat) loadChat(noSurat, chatBox);
  }
}
  </script>  
<script>
window.exportTableToExcel = function (last3Months) {
  if (typeof XLSX === 'undefined') {
    alert('Library XLSX belum dimuat.'); return;
  }
  const src = document.getElementById('tabelSuratDisposisiTindakLanjut');
  if (!src) { alert('Tabel tidak ditemukan.'); return; }

  // clone agar aman dimodifikasi
  const table = src.cloneNode(true);

  // 1) Hilangkan elemen interaktif dari salinan (header filter/select/button)
  table.querySelectorAll('.no-export, select, form, button').forEach(el => el.remove());

  // 2) Pertahankan hanya baris yang SEDANG TAMPIL (mengikuti filter/search user)
  const origRows  = Array.from(src.querySelectorAll('tbody tr.data-row'));
  const cloneRows = Array.from(table.querySelectorAll('tbody tr.data-row'));
  cloneRows.forEach((tr, i) => {
    const hidden = window.getComputedStyle(origRows[i]).display === 'none';
    if (hidden) tr.remove();
  });

  // 3) Jika mode "3 Bulan Terakhir", filter lagi berdasarkan atribut tanggal
  const dateAttr = 'tanggal'; // gunakan data-tanggal di <tr>
  function parseFlexibleDate(str){
    if (!str) return null;
    str = String(str).trim();
    if (str.length >= 10) str = str.slice(0,10);
    // YYYY-MM-DD
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      const [y,m,d] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d); return isNaN(dt) ? null : dt;
    }
    // DD-MM-YYYY
    if (/^\d{2}-\d{2}-\d{4}$/.test(str)) {
      const [d,m,y] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d); return isNaN(dt) ? null : dt;
    }
    return null;
  }
  if (last3Months) {
    const today = new Date();
    const start = new Date(today.getTime() - 90*24*60*60*1000);
    const end   = today;

    table.querySelectorAll('tbody tr.data-row').forEach(tr => {
      const raw = tr.getAttribute('data-' + dateAttr) || '';
      const dt  = parseFlexibleDate(raw);
      if (!dt || dt < start || dt > end) tr.remove();
    });
  }

  // 4) Hapus kolom "File", "Chat", "Aksi" dari hasil export
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
  const wb = XLSX.utils.table_to_book(table, { sheet: 'Disposisi Tindak Lanjut' });
  const ws = wb.Sheets['Disposisi Tindak Lanjut'];

  // Lebar kolom kira-kira (berdasar panjang header)
  const firstRow = XLSX.utils.sheet_to_json(ws, { header: 1 })[0] || [];
  ws['!cols'] = firstRow.map(h => ({ wch: Math.min(Math.max(String(h||'').length + 2, 12), 40) }));

  XLSX.writeFile(wb, `surat_disposisi_tindak_lanjut_${last3Months ? '3bulan' : 'all'}.xlsx`);

  document.querySelector('.export-dropdown')?.classList.remove('open');
};


function showTLReset(){
  const hasDate = !!document.getElementById('flt-tanggal-tl')?.value;
  const hasNo   = !!document.getElementById('flt-nosurat-tl')?.value;
  const hasDisp = !!document.getElementById('flt-disposisi-tl')?.value;
  const box = document.getElementById('reset-container');
  if (box) box.style.display = (hasDate || hasNo || hasDisp) ? 'block' : 'none';
}

// terapkan semua filter
function applyTLFilters(){
  const vTanggal = (document.getElementById('flt-tanggal-tl')?.value || '').toLowerCase(); // format yyyy-mm-dd
  const vNoSurat = (document.getElementById('flt-nosurat-tl')?.value || '').toLowerCase();
  const vDisp    = (document.getElementById('flt-disposisi-tl')?.value || '').toLowerCase();

  document.querySelectorAll('#tabelSuratDisposisiTindakLanjut .data-row').forEach(tr => {
    const tTanggal = (tr.getAttribute('data-tanggal') || '').toLowerCase();
    const tNoSurat = (tr.getAttribute('data-no_surat') || '').toLowerCase();
    const tDisp    = (tr.getAttribute('data-disposisi_kepada') || '').toLowerCase();

    let visible = true;

    // tanggal: cocokkan prefix (yyyy-mm-dd) biar aman walau ada waktu
    if (vTanggal && !tTanggal.startsWith(vTanggal)) visible = false;

    if (vNoSurat && !tNoSurat.includes(vNoSurat)) visible = false;

    if (vDisp && !tDisp.includes(vDisp)) visible = false;

    tr.style.display = visible ? 'table-row' : 'none';
  });
}

// reset semua filter header TL
function resetFilters(){
  // reset filter umum punyamu (kalau ada)
  // ...

  // reset filter TL
  const t = document.getElementById('flt-tanggal-tl'); if (t) t.value = '';
  const n = document.getElementById('flt-nosurat-tl'); if (n) n.selectedIndex = 0;
  const d = document.getElementById('flt-disposisi-tl'); if (d) d.selectedIndex = 0;

  // tampilkan semua baris
  document.querySelectorAll('#tabelSuratDisposisiTindakLanjut .data-row')
    .forEach(tr => tr.style.display = 'table-row');

  showTLReset();
}

// panggil awal (optional)
document.addEventListener('DOMContentLoaded', () => {
  showTLReset();
});
</script>
<script>
// buat textarea bisa diedit
function editNoteTL(idTL){
  const ta   = document.getElementById('note-view-' + idTL);
  const save = document.getElementById('save-note-btn-' + idTL);
  if (!ta || !save) return;

  ta.removeAttribute('readonly');
  ta.style.background = '#fffbe6'; // kasih highlight kuning muda biar kelihatan sedang edit
  ta.focus();

  save.style.display = 'inline-block';
}

// simpan via fetch POST
function saveNoteTL(idTL){
  const ta   = document.getElementById('note-view-' + idTL);
  const save = document.getElementById('save-note-btn-' + idTL);
  if (!ta) return;

  const valNote = ta.value;

  fetch('surat_disposisi_tindak_lanjut.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: 'save_note_tl=1'
        + '&id_tindaklanjut=' + encodeURIComponent(idTL)
        + '&note=' + encodeURIComponent(valNote)
  })
  .then(res => res.text())
  .then(txt => {
    const clean = txt.replace(/^\uFEFF/, '').trim().toLowerCase();
    if (clean.startsWith('ok')) {
      // sukses
      ta.setAttribute('readonly','readonly');
      ta.style.background = '#fff';

      if (save) save.style.display = 'none';

      // update semua baris kembar (same no_surat) di DOM
      // biar UI konsisten tanpa reload
      // cari parent tr buat ambil no_surat
      const tr = ta.closest('tr.data-row');
      if (tr) {
        const noSurat = tr.getAttribute('data-no_surat') || '';
        document.querySelectorAll('#tabelSuratDisposisiTindakLanjut .data-row')
          .forEach(r => {
            if ((r.getAttribute('data-no_surat')||'') === noSurat) {
              r.setAttribute('data-note', valNote);
              const otherTa = r.querySelector('textarea.note-view-td');
              const otherSave = r.querySelector('[id^="save-note-btn-"]');
              if (otherTa) {
                otherTa.value = valNote;
                otherTa.setAttribute('readonly','readonly');
                otherTa.style.background = '#fff';
              }
              if (otherSave) {
                otherSave.style.display = 'none';
              }
            }
          });
      }

      alert('Note tersimpan.');
    } else if (clean.startsWith('error')) {
      alert('Gagal menyimpan note: ' + txt);
    } else if (clean === 'invalid') {
      alert('Data tidak valid.');
    } else {
      // fallback
      alert('Respon tidak dikenal: ' + txt);
    }
  })
  .catch(err => {
    alert('Kesalahan jaringan: ' + err.message);
  });
}
</script>
</body>
</html>