<?php
session_start();
include 'config.php';

$user = $_SESSION['user'] ?? null;
$user_id = $user['id'] ?? null;
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
date_default_timezone_set('Asia/Jakarta');
$tanggal_hari_ini = date('Y-m-d');

// Reset session
unset($_SESSION['buka_disposisi_pengajuan']);

// Cek login
if (!$user) {
    header("Location: login.php");
    exit;
}

/* =========================================================
   PROSES UPDATE ARAHAN (disposisi_pengajuan & pengajuan SAJA)
   — Tidak ada lagi sinkron ke surat_masuk / surat_disposisi
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['arahan'], $_POST['id'])) {
    $id      = (int)$_POST['id'];
    $arahan  = trim($_POST['arahan']);

    $stmt = $pdo->prepare("SELECT * FROM surat_disposisi_pengajuan WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $data = $stmt->fetch();

    header('Content-Type: text/plain; charset=UTF-8');

    if (!$data) { echo 'not_found'; exit; }

    $file_url = $data['file_url'];

    // Update di tabel disposisi_pengajuan → pakai kolom ARAHAN
    $stmt = $pdo->prepare("
        UPDATE surat_disposisi_pengajuan 
        SET arahan = :a, status = 'Telah Diproses' 
        WHERE id = :id
    ");
    $stmt->execute(['a' => $arahan, 'id' => $id]);

    // Sinkron ke surat_pengajuan (kolom ARAHAN juga)
    $stmt = $pdo->prepare("UPDATE surat_pengajuan SET arahan = :a WHERE file_url = :u");
    $stmt->execute(['a' => $arahan, 'u' => $file_url]);
    // (opsional) simpan jejak ke surat_masuk.arahan kalau Disetujui
    if ($arahan === 'Disetujui') {
        // normalisasi key file
        $file_raw  = $file_url;                      // ex: 'uploads/xxx.pdf' atau 'xxx.pdf'
        $file_base = basename((string)$file_url);    // 'xxx.pdf'

        // --- Ambil fallback dari surat_pengajuan SEKALI di awal (dipakai beberapa tempat) ---
        $src = $pdo->prepare("
            SELECT *
              FROM surat_pengajuan
            WHERE file_url = :file_raw
              OR file_url = :file_base
              OR TRIM(no_surat) = TRIM(:no)
            ORDER BY id DESC
            LIMIT 1
        ");
        $src->execute([
            'file_raw'  => $file_raw,
            'file_base' => $file_base,
            'no'        => $data['no_surat'] ?? ''
        ]);
        $p = $src->fetch(PDO::FETCH_ASSOC) ?: [];

        // ---------------------------
        // 1) sinkron ke SURAT MASUK
        // ---------------------------
        $u = $pdo->prepare("
            UPDATE surat_masuk
              SET arahan = 'Disetujui',
                  tanggal_diterima = COALESCE(tanggal_diterima, CURDATE())
            WHERE file_url = :file_raw
              OR file_url = :file_base
              OR TRIM(no_surat) = TRIM(:no)
            LIMIT 1
        ");
        $u->execute([
            'file_raw'  => $file_raw,
            'file_base' => $file_base,
            'no'        => $data['no_surat'] ?? ''
        ]);

        if ($u->rowCount() === 0) {
            // siapkan nilai aman (fallback)
            $tgl_surat = $data['tanggal'] ?? $p['tanggal'] ?? date('Y-m-d');
            $no_surat  = $data['no_surat'] ?? $p['no_surat'] ?? '';
            $dari      = $data['dari']     ?? $p['dari']     ?? '';
            $perihal   = $p['perihal']     ?? '';

            // simpan file_url konsisten 'uploads/...'
            $file_store = (strpos($file_raw, 'uploads/') === 0) ? $file_raw : ('uploads/'.$file_base);

            $ins = $pdo->prepare("
                INSERT INTO surat_masuk
                    (tanggal_surat, tanggal_diterima, no_surat, no_agenda,
                    perihal, dari, keterangan, note, disposisi_kepada, file_url, arahan)
                VALUES
                    (:tgl_surat, CURDATE(), :no_surat, '', :perihal, :dari,
                    '', '', '', :file_url, 'Disetujui')
            ");
            $ins->execute([
                'tgl_surat' => $tgl_surat,
                'no_surat'  => $no_surat,
                'perihal'   => $perihal,
                'dari'      => $dari,
                'file_url'  => $file_store,
            ]);
        }

        // ----------------------------------------
        // 2) sinkron ke SURAT_DISPOSISI (baru kamu)
        // ----------------------------------------
        $sd_arahan = 'Disetujui';
        $sd_status    = 'Telah Diproses';
        $tgl_disp     = $data['tanggal'] ?? $p['tanggal'] ?? date('Y-m-d');

        // coba UPDATE dulu
        $updSd = $pdo->prepare("
            UPDATE surat_disposisi
              SET arahan = :arahan,
                  status_disposisi = :status,
                  tanggal = COALESCE(NULLIF(tanggal,''), :tgl)
            WHERE file_url = :file_raw
                OR file_url = :file_base
                OR TRIM(no_surat) = TRIM(:no)
            LIMIT 1
        ");
        $updSd->execute([
            'arahan' => $sd_arahan,
            'status'    => $sd_status,
            'tgl'       => $tgl_disp,
            'file_raw'  => $file_raw,
            'file_base' => $file_base,
            'no'        => $data['no_surat'] ?? ''
        ]);

        // jika belum ada → INSERT
        if ($updSd->rowCount() === 0) {
            $no_surat_dispo = $data['no_surat'] ?? ($p['no_surat'] ?? ($p['no_perihal'] ?? ''));
            $ditujukan      = $p['ditujukan_kepada'] ?? ''; // opsional bila ada di pengajuan
            $file_for_sd    = (strpos($file_raw, 'uploads/') === 0) ? $file_raw : ('uploads/'.$file_base);

            $insSd = $pdo->prepare("
                INSERT INTO surat_disposisi
                    (tanggal, no_surat, ditujukan_kepada, arahan, status_disposisi, file_url)
                VALUES
                    (:tgl, :no, :ditujukan, :arahan, :status, :file)
            ");
            $insSd->execute([
                'tgl'       => $tgl_disp,
                'no'        => $no_surat_dispo,
                'ditujukan' => $ditujukan,
                'arahan'    => $sd_arahan,
                'status'    => $sd_status,
                'file'      => $file_for_sd,
            ]);
        }
    }
  



    echo 'success';
    exit();
}

/* =========================================================
   PROSES HAPUS
   ========================================================= */
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM surat_disposisi_pengajuan WHERE id = :id");
    $stmt->execute(['id' => $_GET['delete']]);
    header("Location: surat_disposisi_pengajuan.php");
    exit();
}

/* =========================================================
   TAMBAH BARIS DISPOSISI PENGAJUAN DARI SURAT_PENGAJUAN
   (kolom arahan dikosongkan)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cari_surat'])) {
    $no_surat = $_POST['no_surat'] ?? '';
    $dari     = $_POST['dari'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM surat_pengajuan WHERE no_surat = :no_surat AND dari = :dari");
    $stmt->execute(['no_surat' => $no_surat, 'dari' => $dari]);
    $data = $stmt->fetch();

    if ($data) {
        $stmt = $pdo->prepare("
            INSERT INTO surat_disposisi_pengajuan (tanggal, no_surat, dari, arahan, status, file_url)
            VALUES (:tanggal, :no_surat, :dari, '', 'Belum Diproses', :file_url)
        ");
        $stmt->execute([
            'tanggal'   => $data['tanggal'],
            'no_surat'  => $data['no_surat'],
            'dari'      => $data['dari'],
            'file_url'  => $data['file_url']
        ]);

        $_SESSION['buka_disposisi_pengajuan'] = true;
        $_SESSION['flash'] = "Disposisi berhasil ditambahkan.";
        header('Location: surat_disposisi_pengajuan.php');
        exit();
    } else {
        $_SESSION['flash'] = "Data tidak ditemukan.";
    }
}

// Ambil data untuk ditampilkan
$stmt = $pdo->query("SELECT * FROM surat_disposisi_pengajuan ORDER BY id DESC");
$surat_pengajuan = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
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

  /* .form-group input[type="date"] {
    width: 10%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 16px;
    height: 10px;
  } */
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

.dropdown-arahan {
    width: 19px;
    padding: 5px;
}

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
/* tombol chat di kolom Pesan */
.btn-chat{ background:#eef4ff; border:1px solid #cfe0ff; padding:6px 10px; border-radius:6px; cursor:pointer; }
.chat-container{ display:flex; flex-direction:column; gap:10px; padding:10px; }
.chat-bubble{ max-width:60%; padding:10px 14px; border-radius:14px; margin-bottom:8px; }
.chat-bubble.left{ background:#f3f4f6; border-top-left-radius:4px; }
.chat-bubble.right{ background:#dbeafe; border-top-right-radius:4px; margin-left:auto; }
.chat-time{ font-size:.75em; color:#6b7280; margin-top:4px; text-align:right; }

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
      <!-- <div class="search-bar-bottom">
        <input type="text" placeholder="Cari..." />
        <button>Cari</button>
      </div> -->
    </div>
  </nav>

  <h2 class="judul-surat-luar">Disposisi Pengajuan</h2>
  <div class="kontainer-balok">
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
          <input type="text" placeholder="Cari..." id="searchInput" oninput="searchTable()" />
          <button>Cari</button>
      </div>
  </div>
  <!-- Balok 3: Tabel Data Disposisi -->
  <div class="balok-3">
    <table id="tabelSuratDisposisiPengajuan" border="1" cellpadding="10" cellspacing="0">
      <thead>
          <tr>
              <th>No</th>
              <th>No. Surat</th>
              <th>Tanggal</th>
              <th>Dari</th>
              <th>
                  Instruksi
                  <div class="no-export">
                      <select onchange="filterTable('arahan', this.value); showResetButton();">
                          <option value=""></option>
                          <option value="Disetujui">Disetujui</option>
                          <option value="Batal">Batal</option>
                      </select>
                  </div>
              </th>
              <th>Status</th>
              <th>File</th>
              <th>Pesan</th>
              <th>Aksi</th>
          </tr>
      </thead>
      <tbody>
          <?php if (count($surat_pengajuan) === 0): ?>
              <tr><td colspan="8" align="center">Tidak ada data pengajuan masuk.</td></tr>
          <?php else: ?>
          <?php foreach ($surat_pengajuan as $i => $row): ?>
          <tr class="data-row"
              data-tanggal="<?= htmlspecialchars($row['tanggal']) ?>"
              data-arahan="<?= htmlspecialchars($row['arahan']) ?>">
              <!-- data-no_surat="<?= htmlspecialchars($row['no_surat']) ?>"> -->
              <td><?= $i + 1 ?></td>
              <td><?= htmlspecialchars($row['no_surat']) ?></td>
              <td><?= htmlspecialchars($row['tanggal']) ?></td>
              <td><?= htmlspecialchars($row['dari']) ?></td>
              <td>
                <span id="label-arahan-<?= $row['id'] ?>">
                  <?= htmlspecialchars($row['arahan']) ?>
                </span>
                <select class="dropdown-arahan" onchange="updateArahan(<?= $row['id'] ?>, this.value)">
                  <option value="">Belum Diproses</option>
                  <option value="Disetujui" <?= $row['arahan'] == 'Disetujui' ? 'selected' : '' ?>>Disetujui</option>
                  <option value="Batal"  <?= $row['arahan'] == 'Batal'  ? 'selected' : '' ?>>Batal</option>
                </select>
              </td>
              <td id="status-<?= $row['id'] ?>"><?= htmlspecialchars($row['status']) ?></td>
              <td>
                <?php if (!empty($row['file_url'])): ?>
                  <a href="<?= $row['file_url'] ?>" target="_blank"><?= basename($row['file_url']) ?></a>
                <?php else: ?>(Kosong)<?php endif; ?>
              </td>
              <td>
                <?php if (!empty($row['arahan'])): ?>
                  <a class="btn-chat"
                    href="pesan_lihat_1.php?no_surat=<?= urlencode($row['no_surat']) ?>"
                    title="Buka halaman pesan untuk <?= htmlspecialchars($row['no_surat']) ?>">
                    💬
                  </a>
                <?php else: ?>
                  <button class="btn-chat" disabled title="Isi instruksi dulu">💬</button>
                <?php endif; ?>
              </td>
              <td>
                <a href="update_surat_disposisi_pengajuan.php?id=<?= $row['id'] ?>">✏️</a><br>
                <a href="surat_disposisi_pengajuan.php?delete=<?= $row['id'] ?>" onclick="return confirm('Hapus disposisi ini?')">🗑️</a>
              </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
      </tbody>
    </table>
    </div>

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

window.exportTableToExcel = function (last3Months) {
  if (typeof XLSX === 'undefined') { alert('Library XLSX belum dimuat.'); return; }
  const src = document.getElementById('tabelSuratDisposisiPengajuan');
  if (!src) { alert('Tabel tidak ditemukan'); return; }

  // clone tabel agar aman dimodifikasi
  const table = src.cloneNode(true);

  // hapus elemen interaktif/yang tidak diekspor
  table.querySelectorAll('.no-export, select, form, button').forEach(el => el.remove());

  // --- nama atribut tanggal yang dipakai untuk filter 3 bulan ---
  const dateAttr = 'tanggal';

  // parser tanggal fleksibel (YYYY-MM-DD atau DD-MM-YYYY)
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
    // Rolling 90 hari ke belakang
    const today = new Date();
    const end   = new Date(today.getFullYear(), today.getMonth()+1, 0); // akhir bulan ini
    const start = new Date(today.getFullYear(), today.getMonth()-2, 1); // awal 2 bulan lalu


    // filter berdasarkan data-tanggal pada TR
    table.querySelectorAll('tbody tr').forEach(tr => {
      const raw = tr.getAttribute('data-' + dateAttr) || '';
      const dt  = parseFlexibleDate(raw);
      if (!dt || dt < start || dt > end) tr.remove();
    });
  }

  // Temukan index kolom File & Aksi untuk dibuang di export
  const headerRow = table.querySelector('thead tr') || table.querySelector('tr');
  const ths = Array.from(headerRow.children);
  const removeIdx = ths
    .map((th, i) => [ (th.textContent||'').trim().split('\n')[0], i ])
    .filter(([t]) => t === 'File' || t === 'Aksi')
    .map(([, i]) => i);

  // Hapus kolom tersebut dari semua baris
  table.querySelectorAll('tr').forEach(tr => {
    Array.from(tr.children).forEach((td, i) => { if (removeIdx.includes(i)) td.remove(); });
  });

  // Buat workbook dari tabel
  const wb = XLSX.utils.table_to_book(table, { sheet: 'Disposisi Pengajuan' });
  const ws = wb.Sheets['Disposisi Pengajuan'];

  // Lebar kolom otomatis (berdasar header)
  const firstRow = XLSX.utils.sheet_to_json(ws, { header: 1 })[0] || [];
  ws['!cols'] = firstRow.map(h => ({ wch: Math.min(Math.max(String(h||'').length + 2, 12), 40) }));

  // Nama file
  XLSX.writeFile(wb, `surat_disposisi_pengajuan_${last3Months ? '3bulan' : 'all'}.xlsx`);

  document.querySelector('.export-dropdown')?.classList.remove('open');
};


    function resetFilters() {
      // Reset semua dropdown
      const selects = document.querySelectorAll('select');
      selects.forEach(select => {
        select.selectedIndex = 0;
      });

      // Reset input tanggal
      const dateInput = document.getElementById('tanggalSurat');
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
  const rows = document.querySelectorAll("#tabelSuratDisposisiPengajuan .data-row");

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

function updateArahan(id, arahan) {
  fetch('surat_disposisi_pengajuan.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'id=' + encodeURIComponent(id) + '&arahan=' + encodeURIComponent(arahan)
  })
  .then(r => r.text())
  .then(t => {
    t = t.trim();
    if (t === 'success') {
      const label = document.getElementById('label-arahan-'+id);
      if (label) label.innerText = arahan || 'Belum Diproses';
      const st = document.getElementById('status-'+id);
      if (st) st.innerText = arahan ? 'Telah Diproses' : 'Belum Diproses';
    } else {
      alert('Gagal memperbarui: ' + t);
    }
  })
  .catch(err => alert('Error jaringan: ' + err.message));
}
  </script>
</body>
</html>