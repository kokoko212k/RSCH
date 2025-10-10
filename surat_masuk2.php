<?php
session_start();
include 'config.php';

// Cek login
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'];
$role = $user['status'] ?? null;
$can_access_eoffice = in_array($role, ['Sekretariat', 'Super Admin']);

// Hapus surat
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM surat_masuk WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $_SESSION['success_message'] = "Surat berhasil dihapus.";
    header("Location: surat_masuk.php");
    exit();
}

// Simpan surat masuk baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_surat'])) {
    $tanggal_surat = $_POST['tanggal_surat'] ?? '';
    $tanggal_diterima = date('Y-m-d'); // Otomatis isi di sini saat upload
    $no_surat = trim($_POST['no_surat'] ?? '');
    $no_agenda = trim($_POST['no_agenda'] ?? '');
    $perihal = trim($_POST['perihal'] ?? '');
    $dari = trim($_POST['dari'] ?? '');
    $instruksi = trim($_POST['instruksi'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $disposisi_kepada = trim($_POST['disposisi_kepada'] ?? '');

    if (!empty($disposisi_kepada)) {
        $stmt_check = $pdo->prepare("SELECT instruksi FROM surat_disposisi WHERE no_surat = ? ORDER BY id DESC LIMIT 1");
        $stmt_check->execute([$no_surat]);
        $instruksi_terakhir = strtolower($stmt_check->fetchColumn());

        if ($instruksi_terakhir !== 'diterima') {
            echo "<script>alert('Isi instruksi terlebih dahulu melalui surat disposisi!'); window.history.back();</script>";
            exit();
        }
    }

    if (isset($_FILES['file_url']) && $_FILES['file_url']['error'] === UPLOAD_ERR_OK) {
        $file_name = time() . '_' . uniqid() . '_' . basename($_FILES['file_url']['name']);
        $target_dir = 'uploads/';
        $file_path = $target_dir . $file_name;

        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        $allowed_types = ['application/pdf'];
        $file_type = mime_content_type($_FILES['file_url']['tmp_name']);

        if (in_array($file_type, $allowed_types) && $_FILES['file_url']['size'] <= 5 * 1024 * 1024) {
            if (move_uploaded_file($_FILES['file_url']['tmp_name'], $file_path)) {
                // INSERT ke surat_masuk
                $stmt = $pdo->prepare("INSERT INTO surat_masuk 
                    (tanggal_surat, tanggal_diterima, no_surat, no_agenda, perihal, dari, keterangan, disposisi_kepada, file_url) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $tanggal_surat,
                    $tanggal_diterima, // otomatis
                    $no_surat,
                    $no_agenda,
                    $perihal,
                    $dari,
                    $keterangan,
                    $disposisi_kepada,
                    $file_name
                ]);

                // Auto insert ke surat_disposisi
                $stmt2 = $pdo->prepare("INSERT INTO surat_disposisi 
                    (tanggal, no_surat, ditujukan_kepada, instruksi, status_disposisi, file_url) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                $tanggal_disposisi = date('Y-m-d');
                $stmt2->execute([
                    $tanggal_disposisi,
                    $no_surat,
                    $dari,
                    'Belum Diproses',
                    'Belum Diproses',
                    $file_name
                ]);

                $_SESSION['success_message'] = "Surat berhasil disimpan.";
                header("Location: surat_masuk.php");
                exit();
            } else {
                echo "Gagal mengunggah file.";
            }
        } else {
            echo "File harus berupa PDF dan maksimal 5MB.";
        }
    } else {
        echo "File tidak ditemukan atau gagal diunggah.";
    }
}

// Ambil semua data surat_masuk
$stmt = $pdo->query("SELECT * FROM surat_masuk ORDER BY id DESC");
$suratList = $stmt->fetchAll(PDO::FETCH_ASSOC);
$result = $suratList;

// Ambil data unik untuk dropdown
function getDropdownData($pdo, $column) {
    $stmt = $pdo->prepare("SELECT DISTINCT $column FROM surat_masuk ORDER BY $column ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$noSuratData = getDropdownData($pdo, 'no_surat');
$noAgendaData = getDropdownData($pdo, 'no_agenda');
$perihalData = getDropdownData($pdo, 'perihal');
$dariData = getDropdownData($pdo, 'dari');
$keteranganData = getDropdownData($pdo, 'keterangan');

// Proses update disposisi_kepada dan tanggal_disposisi jika dikirim dari form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_disposisi'])) {
    $id = $_POST['id'] ?? null;
    $disposisi_kepada = $_POST['disposisi_kepada'] ?? null;
    $tanggal_disposisi = $_POST['tanggal_disposisi'] ?? date('Y-m-d');

    if ($id && $disposisi_kepada) {
        // Update surat_masuk
        $stmt = $pdo->prepare("UPDATE surat_masuk 
                               SET disposisi_kepada = :disposisi_kepada, 
                                   tanggal_disposisi = :tanggal_disposisi 
                               WHERE id = :id");
        $stmt->execute([
            'disposisi_kepada' => $disposisi_kepada,
            'tanggal_disposisi' => $tanggal_disposisi,
            'id' => $id
        ]);

        // 🔔 Insert ke notif_surat
        $stmtGet = $pdo->prepare("SELECT no_surat, file_url FROM surat_masuk WHERE id = ?");
        $stmtGet->execute([$id]);
        $row = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stmtNotif = $pdo->prepare("INSERT INTO surat_notif 
                (tanggal, no_surat, file_url, waktu) 
                VALUES (:tanggal, :no_surat, :file_url, NOW())");

            $stmtNotif->execute([
                'tanggal' => date('Y-m-d'),
                'no_surat' => $row['no_surat'],
                'file_url' => $row['file_url'],
            ]);
        }

        header("Location: surat_masuk.php");
        exit();
    }
}
?>


<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Surat Masuk</title>
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
      padding: 5px 3px;
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

  </style>
<script defer src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
</head>
<body>
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
      <?php endif; ?>
      <?php if (isset($_SESSION['user'])): ?>
        <div class="user-dropdown">
          <i class="bx bxs-user-circle user-icon" onclick="toggleUserDropdown()"></i>
          <div class="user-menu" id="userMenu">
            <a href="profil.php">Profil</a>
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
            <a href="surat_masuk.php">Surat Masuk</a>
            <a href="surat_keluar.php">Surat Keluar</a>
            <a href="surat_disposisi_pengajuan.php">Disposisi Pengajuan</a>
            <a href="surat_disposisi.php">Disposisi Surat</a>
            <a href="surat_disposisi_tindak_lanjut.php">Disposisi Tindak Lanjut</a>
            <a href="surat_notif.php">Surat Notif</a>          
            <a href="surat_pengajuan.php">Pengajuan</a>          
            <!-- <a href="surat_internal.php">Surat Internal</a>           -->
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
  <h2 class="judul-surat-luar">Daftar Surat Masuk</h2>
  <div class="kontainer-balok">
      <!-- Balok 1: Tombol tambah surat masuk -->
    <div class="balok-1">
        <a href="buat_surat_masuk.php" class="btn-tambah">Upload</a>
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

    <!-- Balok 3: Tabel Data Surat -->
    <div class="balok-3">
    <table id="tabelSuratMasuk" border="1" cellpadding="10" cellspacing="0">
        <tr>
            <th>
                Tanggal Surat
                <div class="form-group">
                    <input type="date" id="tanggalSurat" name="tanggalSurat" required onchange="filterTable('tanggalsurat', this.value); showResetButton();" />
                </div>
            </th>
            <th>
                Tanggal Diterima
                <div class="form-group">
                    <input type="date" id="tanggalDiterima" name="tanggal_diterima" onchange="filterTable('tanggalditerima', this.value); showResetButton();" />
                </div>
            </th>
            <th>
                Tanggal Disposisi
                <div class="form-group">
                    <input type="date" id="tanggalDisposisi" name="tanggalDisposisi" onchange="filterTable('tanggaldisposisi', this.value); showResetButton();" />
                </div>
            </th>            
            <th>
                No. Surat
                <div class="no-export">
                    <select onchange="applyAllFilters('no_surat', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($noSuratData)): ?>
                            <?php foreach ($noSuratData as $rowNoSurat): ?>
                                <option value="<?= $rowNoSurat['no_surat'] ?>"><?= $rowNoSurat['no_surat'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>
            <th>
                No. Agenda
                <div class="no-export">
                    <select onchange="applyAllFilters('no_agenda', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($noAgendaData)): ?>
                            <?php foreach ($noAgendaData as $rowNoAgenda): ?>
                                <option value="<?= $rowNoAgenda['no_agenda'] ?>"><?= $rowNoAgenda['no_agenda'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>
            <th>
                Perihal
                <div class="no-export">
                    <select onchange="applyAllFilters('perihal', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($perihalData)): ?>
                            <?php foreach ($perihalData as $rowPerihal): ?>
                                <option value="<?= $rowPerihal['perihal'] ?>"><?= $rowPerihal['perihal'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>
            <th>
                Dari
                <div class="no-export">
                    <select onchange="applyAllFilters('dari', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($dariData)): ?>
                            <?php foreach ($dariData as $rowDari): ?>
                                <option value="<?= $rowDari['dari'] ?>"><?= $rowDari['dari'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>

            <th>
                Keterangan
                <div class="no-export">
                    <select onchange="applyAllFilters('keterangan', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($keteranganData)): ?>
                            <?php foreach ($keteranganData as $rowKeterangan): ?>
                                <option value="<?= $rowKeterangan['keterangan'] ?>"><?= $rowKeterangan['keterangan'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>
            <th>
                Disposisi
                <div class="no-export">
                    <select onchange="applyAllFilters('instruksi', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($instruksiData)): ?>
                            <?php foreach ($instruksiData as $rowInstruksi): ?>
                                <option value="<?= $rowInstruksi['instruksi'] ?>"><?= $rowInstruksi['instruksi'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>
            <th>
                <div class="input-row">
                  <label for="disposisi_kepada">Disposisi Kepada:</label>
                  <select onchange="applyAllFilters('disposisi_kepada', this.value); showResetButton();">                  
                    <option value="">---Pilih---</option>
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
                  </select>
                </div>
            </th>
            <th>File</th>
            <th>Aksi</th>
        </tr>

        <?php foreach ($result as $row): ?>
        <tr class="data-row"
            data-tanggalsurat="<?= $row['tanggal_surat'] ?>"
            data-tanggalditerima="<?= $row['tanggal_diterima'] ?>"
            data-tanggaldisposisi="<?= $row['tanggal_disposisi'] ?>"
            data-no_surat="<?= $row['no_surat'] ?>"
            data-no_agenda="<?= $row['no_agenda'] ?>"
            data-perihal="<?= $row['perihal'] ?>"
            data-dari="<?= $row['dari'] ?>"
            data-keterangan="<?= $row['keterangan'] ?>"
            data-instruksi="<?= $row['instruksi'] ?>"
            data-disposisi_kepada="<?= $row['disposisi_kepada'] ?>"
            data-file="<?= $row['file_url'] ?>">

            <td><?= date('d-m-Y', strtotime($row['tanggal_surat'])) ?></td>
            <td>
              <?= !empty($row['tanggal_diterima']) ? date('d-m-Y', strtotime($row['tanggal_diterima'])) : '-' ?>
            </td>
            <td>
              <?= !empty($row['tanggal_disposisi']) ? date('d-m-Y', strtotime($row['tanggal_disposisi'])) : '-' ?>
            </td>
            <td><?= $row['no_surat'] ?></td>
            <td><?= $row['no_agenda'] ?></td>
            <td><?= $row['perihal'] ?></td>
            <td><?= $row['dari'] ?></td>
            <td><?= $row['keterangan'] ?></td>
            <td><?= $row['instruksi'] ?></td>
            <td>
            <div style="margin-bottom: 5px;">
              <?= htmlspecialchars($row['disposisi_kepada'] ?? '-') ?>
            </div>
              <form method="POST" action="surat_masuk.php">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="update_disposisi" value="1">
                <select name="disposisi_kepada" onchange="this.form.submit()" required>
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
            <td><a href="<?= $row['file_url'] ?>" target="_blank"><?= basename($row['file_url']) ?></a></td>
            <td>
                <a href="update_surat_masuk.php?id=<?= $row['id'] ?>">✏️</a><br>
                <a href="surat_masuk.php?delete=<?= $row['id'] ?>" onclick="return confirm('Hapus surat ini?')">🗑️</a>
            </td>
        </tr>
      <?php endforeach; ?>
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
      <p>© Copyright Humas Marketing Citra Husada.</p>
    </div>
  </footer>
  <script defer src="script.js"></script>
  <script>
  // ============ User menu (dibungkus supaya tidak bentrok nama variabel global) ============
  (function(){
    const userIconEl = document.querySelector(".user-icon");
    const userMenuEl = document.getElementById("userMenu");
    if (userIconEl && userMenuEl) {
      userIconEl.addEventListener("click", function (e) {
        e.stopPropagation();
        userMenuEl.style.display = (userMenuEl.style.display === "block") ? "none" : "block";
      });
      document.addEventListener("click", function (e) {
        if (!userIconEl.contains(e.target)) userMenuEl.style.display = "none";
      });
    }
  })();

  function showResetButton() {
    const resetContainer = document.getElementById("reset-container");
    if (resetContainer) resetContainer.style.display = "block";
  }

  function filterTable(attribute, value) {
    const rows = document.querySelectorAll('.data-row');
    const filterValue = (value || '').toLowerCase();
    rows.forEach(row => {
      const cellValue = (row.getAttribute('data-' + attribute) || '').toLowerCase();
      row.style.display = (!filterValue || cellValue.includes(filterValue)) ? "table-row" : "none";
    });
  }

(function(){
  const dd = document.querySelector('.export-dropdown');
  const btn = dd?.querySelector('.export-toggle');
  if (btn) {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      dd.classList.toggle('open');
    });
    document.addEventListener('click', () => dd.classList.remove('open'));
  }
})();

window.exportTableToExcel = function (last3Months) {
  if (typeof XLSX === 'undefined') { alert('Library XLSX belum ter-load.'); return; }
  const src = document.getElementById('tabelSuratMasuk');
  if (!src) { alert('Tabel tidak ditemukan'); return; }

  const table = src.cloneNode(true);
  table.querySelectorAll('.no-export, select, form, button').forEach(el => el.remove());

  // ------- PILIH kolom tanggal yang jadi acuan -------
  const dateAttr = 'tanggalsurat'; // 'tanggalditerima' / 'tanggaldisposisi' kalau mau

  function parseFlexibleDate(str){
    if (!str) return null;
    str = String(str).trim();
    // potong waktu kalau ada
    if (str.length >= 10) str = str.slice(0,10);

    // YYYY-MM-DD
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      const [y,m,d] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;
    }
    // DD-MM-YYYY
    if (/^\d{2}-\d{2}-\d{4}$/.test(str)) {
      const [d,m,y] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;
    }
    return null;
  }

  if (last3Months) {
    // --- Mode rolling 90 hari (paling aman & intuitif) ---
    const today = new Date();
    const start = new Date(today.getTime() - 90*24*60*60*1000);
    const end   = today;

    // // --- Kalau mau 3 bulan kalender, pakai ini:
    // const end   = new Date(today.getFullYear(), today.getMonth()+1, 0); // akhir bulan ini
    // const start = new Date(today.getFullYear(), today.getMonth()-2, 1); // awal 2 bulan lalu

    table.querySelectorAll('tr.data-row').forEach(tr => {
      const raw = tr.getAttribute('data-' + dateAttr) || '';
      const dt  = parseFlexibleDate(raw);
      // hapus baris kalau tanggal invalid / di luar rentang
      if (!dt || dt < start || dt > end) tr.remove();
    });
  }

  // buang kolom File & Aksi
  const header = table.querySelector('tr');
  const ths = Array.from(header.children);
  const removeIdx = ths
    .map((th, i) => [ (th.textContent||'').trim().split('\n')[0], i ])
    .filter(([t]) => t === 'File' || t === 'Aksi')
    .map(([, i]) => i);
  table.querySelectorAll('tr').forEach(tr => {
    Array.from(tr.children).forEach((td, i) => { if (removeIdx.includes(i)) td.remove(); });
  });

  const wb = XLSX.utils.table_to_book(table, { sheet: 'Surat Masuk' });
  const ws = wb.Sheets['Surat Masuk'];

  const firstRow = XLSX.utils.sheet_to_json(ws, { header: 1 })[0] || [];
  ws['!cols'] = firstRow.map(h => ({ wch: Math.min(Math.max(String(h||'').length + 2, 12), 40) }));

  XLSX.writeFile(wb, `surat_masuk${last3Months ? '_3 bulan terakhir3bulan' : '_all'}.xlsx`);
  document.querySelector('.export-dropdown')?.classList.remove('open');
};


  function resetFilters() {
    // reset semua select header
    document.querySelectorAll('#tabelSuratMasuk th select').forEach(s => s.selectedIndex = 0);
    // reset semua input date
    document.querySelectorAll('#tabelSuratMasuk th input[type="date"]').forEach(i => i.value = '');
    // reset pencarian
    const s = document.getElementById('searchInput'); if (s) s.value = '';
    // tampilkan semua baris
    document.querySelectorAll('.data-row').forEach(r => r.style.display = 'table-row');
    // sembunyikan tombol reset
    const rc = document.getElementById('reset-container'); if (rc) rc.style.display = 'none';
  }

  function searchTable() {
    const input = (document.getElementById("searchInput")?.value || '').toLowerCase();
    const rows = document.querySelectorAll("#tabelSuratMasuk .data-row");
    rows.forEach(row => {
      const cells = row.querySelectorAll("td");
      let found = false;
      cells.forEach(cell => {
        if (cell.textContent.toLowerCase().includes(input)) found = true;
      });
      row.style.display = found ? "table-row" : "none";
    });
    showResetButton();
  }

  function applyAllFilters(){
    const filters = {
      tanggalsurat: (document.getElementById('tanggalSurat')?.value || '').toLowerCase(),
      tanggalditerima: (document.getElementById('tanggalDiterima')?.value || '').toLowerCase(),
      tanggaldisposisi: (document.getElementById('tanggalDisposisi')?.value || '').toLowerCase(),
      no_surat: getSelectValue('No. Surat'),
      no_agenda: getSelectValue('No. Agenda'),
      perihal: getSelectValue('Perihal'),
      dari: getSelectValue('Dari'),
      keterangan: getSelectValue('Keterangan'),
      instruksi: getSelectValue('Disposisi'),
      disposisi_kepada: (document.getElementById('select-disposisi-kepada')?.value || '').toLowerCase() // <- pakai id khusus
    };

    const rows = document.querySelectorAll('.data-row');
    rows.forEach(row => {
      let visible = true;
      for (const key in filters) {
        const val = filters[key];
        if (!val) continue;
        const cell = (row.getAttribute('data-' + key) || '').toLowerCase();
        if (!cell.includes(val)) { visible = false; break; }
      }
      row.style.display = visible ? 'table-row' : 'none';
    });
  }

  // helper ambil value dari select di header berdasarkan heading (No. Surat, No. Agenda, dst)
  function getSelectValue(headingText){
    const ths = Array.from(document.querySelectorAll('#tabelSuratMasuk th'));
    const th = ths.find(t => (t.firstChild?.textContent || t.textContent).trim().split('\n')[0] === headingText);
    const sel = th?.querySelector('select');
    return (sel?.value || '').toLowerCase();
  }

function showResetButton() {
  const resetContainer = document.getElementById('reset-container');
  if (!resetContainer) return;

  const search = document.getElementById('searchInput');
  const selects = document.querySelectorAll('#tabelSuratMasuk th select');
  const dates   = document.querySelectorAll('#tabelSuratMasuk th input[type="date"]');

  const hasSearch = !!(search && search.value.trim().length > 0);
  const hasSelect = Array.from(selects).some(s => (s.value || '').trim() !== '');
  const hasDate   = Array.from(dates).some(i => (i.value || '').trim() !== '');

  resetContainer.style.display = (hasSearch || hasSelect || hasDate) ? 'block' : 'none';
}

// Hook semua kontrol agar memanggil showResetButton
document.addEventListener('DOMContentLoaded', () => {
  const search = document.getElementById('searchInput');
  const selects = document.querySelectorAll('#tabelSuratMasuk th select');
  const dates   = document.querySelectorAll('#tabelSuratMasuk th input[type="date"]');

  if (search) search.addEventListener('input', showResetButton);
  selects.forEach(s => s.addEventListener('change', showResetButton));
  dates.forEach(i => i.addEventListener('change', showResetButton));

  // panggil sekali saat load untuk set keadaan awal
  showResetButton();
});

  </script>
</body>
</html>
