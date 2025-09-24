<?php
session_start();
include 'config.php';

// helper aman untuk HTML
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


date_default_timezone_set('Asia/Jakarta');
$tanggal_hari_ini = date('Y-m-d');

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'];
$role = $user['status'] ?? null;
$can_access_eoffice = in_array($role, ['Super Admin', 'Direktur']);

$pdo->prepare("
    UPDATE surat_masuk 
    SET tanggal_diterima = :tanggal 
    WHERE tanggal_diterima IS NULL 
      AND file_url IN (SELECT file_url FROM surat_disposisi WHERE file_url IS NOT NULL)
")->execute(['tanggal' => $tanggal_hari_ini]);

$pdo->prepare("
    UPDATE surat_keluar 
    SET tanggal_diterima = :tanggal 
    WHERE tanggal_diterima IS NULL 
      AND file_url IN (SELECT file_url FROM surat_disposisi WHERE file_url IS NOT NULL)
")->execute(['tanggal' => $tanggal_hari_ini]);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['flash'])) {
    echo "<script>alert(" . json_encode($_SESSION['flash']) . ");</script>";
    unset($_SESSION['flash']);
}



unset($_SESSION['buka_disposisi']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_keterangan'], $_POST['id'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $id  = (int) $_POST['id'];
    $ket = trim(substr($_POST['keterangan'] ?? '', 0, 255));

    $pdo->prepare("UPDATE surat_disposisi SET keterangan = :ket WHERE id = :id")
        ->execute(['ket' => $ket, 'id' => $id]);

    echo 'ok';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['instruksi'], $_POST['id'])) {
    $id = (int) $_POST['id'];
    $instruksi = trim($_POST['instruksi'] ?? '');
    $keterangan = trim(substr($_POST['keterangan'] ?? '', 0, 255));
    $tanggal_disposisi = $tanggal_hari_ini;

    if ($id > 0) {
        // ambil surat terkait
        $stmt = $pdo->prepare("SELECT * FROM surat_disposisi WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $dataSurat = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dataSurat) {
            $file_url = $dataSurat['file_url'];
            $no_surat = $dataSurat['no_surat'];

            // kalau instruksi kosong, jangan ubah instruksi; hanya simpan keterangan
            if ($instruksi === '') {
                $pdo->prepare("UPDATE surat_disposisi SET keterangan = :ket WHERE id = :id")
                    ->execute(['ket' => $keterangan, 'id' => $id]);
                echo 'success';
                exit();
            }

            // update instruksi + keterangan
            $pdo->prepare("
                UPDATE surat_disposisi 
                SET instruksi = :instruksi,
                    keterangan = :ket,
                    status_disposisi = 'Telah Diproses'
                WHERE id = :id
            ")->execute([
                'instruksi' => $instruksi,
                'ket'       => $keterangan,
                'id'        => $id
            ]);

            // sinkron ke surat_masuk/keluar (instruksi saja)
            foreach (['surat_masuk', 'surat_keluar'] as $table) {
                $pdo->prepare("UPDATE $table SET instruksi = :instruksi WHERE file_url = :file_url")
                    ->execute(['instruksi' => $instruksi, 'file_url' => $file_url]);
            }

            if (strcasecmp($instruksi, 'Diteruskan') === 0) {
                foreach (['surat_masuk', 'surat_keluar'] as $table) {
                    $pdo->prepare("
                        UPDATE $table 
                        SET tanggal_disposisi = :tanggal 
                        WHERE file_url = :file_url 
                          AND (tanggal_disposisi IS NULL OR tanggal_disposisi = '')
                    ")->execute(['tanggal' => $tanggal_disposisi, 'file_url' => $file_url]);
                }

                // masukkan tindak lanjut
                $stmt = $pdo->prepare("
                    INSERT INTO surat_disposisi_tindak_lanjut (tanggal, no_surat, file_url) 
                    VALUES (?, ?, ?)
                ");
                if (!$stmt->execute([$tanggal_disposisi, $no_surat, $file_url])) {
                    $error = $stmt->errorInfo();
                    echo "error:" . $error[2];
                    exit;
                }

                echo 'success-redirect';
                exit();
            }

            echo 'success';
            exit();
        }
    }
    echo 'invalid';
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM surat_disposisi WHERE id = :id");
    $stmt->execute(['id' => $id]);
    header("Location: surat_disposisi.php");
    exit();
}


$no_surat_input = $_POST['no_surat'] ?? '';
$tujuan_input   = $_POST['ditujukan_kepada'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cari_surat'])) {
    $tanggal = $no_surat = $ditujukan_kepada = $file_url = $sumber_surat = '';

    // 🔎 Cari di surat_masuk (tidak ada kolom ditujukan_kepada)
    $stmt = $pdo->prepare("SELECT * FROM surat_masuk WHERE no_surat = :no_surat");
    $stmt->execute(['no_surat' => $no_surat_input]);
    $data = $stmt->fetch();

    if ($data) {
        $tanggal = $data['tanggal_surat'];
        $no_surat = $data['no_surat'];
        $file_url = $data['file_url'];
        $sumber_surat = 'Surat Masuk';

        // update tanggal_diterima kalau kosong
        if (empty($data['tanggal_diterima'])) {
            $pdo->prepare("UPDATE surat_masuk SET tanggal_diterima = :tgl WHERE no_surat = :no")
                ->execute(['tgl' => $tanggal_hari_ini, 'no' => $no_surat]);
        }
    } else {
        // 🔎 Cari di surat_keluar (punya kolom ditujukan_kepada)
        $stmt = $pdo->prepare("SELECT * FROM surat_keluar WHERE no_surat = :no_surat AND ditujukan_kepada = :ditujukan_kepada");
        $stmt->execute([
            'no_surat' => $no_surat_input,
            'ditujukan_kepada' => $tujuan_input
        ]);
        $data = $stmt->fetch();

        if ($data) {
            $tanggal = $data['tanggal'];
            $no_surat = $data['no_surat'];
            $ditujukan_kepada = $data['ditujukan_kepada'];
            $file_url = $data['file_url'];
            $sumber_surat = 'Surat Keluar';

            if (empty($data['tanggal_diterima'])) {
                $pdo->prepare("UPDATE surat_keluar SET tanggal_diterima = :tgl WHERE no_surat = :no")
                    ->execute(['tgl' => $tanggal_hari_ini, 'no' => $no_surat]);
            }
        } else {
            // 🔎 Cari di surat_pengajuan (punya kolom ditujukan_kepada)
            $stmt = $pdo->prepare("SELECT * FROM surat_pengajuan WHERE no_perihal = :no_surat AND ditujukan_kepada = :ditujukan_kepada");
            $stmt->execute([
                'no_surat' => $no_surat_input,
                'ditujukan_kepada' => $tujuan_input
            ]);
            $data = $stmt->fetch();

            if ($data) {
                $tanggal = $data['tanggal'];
                $no_surat = $data['no_perihal'];
                $ditujukan_kepada = $data['ditujukan_kepada'];
                $file_url = $data['file_url'];
                $sumber_surat = 'Surat Pengajuan';
            }
        }
    }

    // ✅ kalau ketemu salah satu sumber surat, simpan ke disposisi
    if ($tanggal !== '') {
        $stmt = $pdo->prepare("
            INSERT INTO surat_disposisi (tanggal, no_surat, ditujukan_kepada, instruksi, status_disposisi, file_url) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tanggal, $no_surat, $ditujukan_kepada, '', 'Belum Diproses', $file_url]);

        $_SESSION['buka_disposisi'] = true;
        $_SESSION['flash'] = "Data berhasil ditambahkan dari $sumber_surat ke disposisi.";
        header('Location: surat_masuk.php');
        exit();
    }
}


$stmt = $pdo->query("SELECT * FROM surat_disposisi ORDER BY id DESC");
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>




<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Surat Disposisi</title>
  <link rel="stylesheet" href="style.css" />
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'> <!-- untuk ikon footer -->
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

  </style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
  <h2 class="judul-surat-luar">Disposisi Surat</h2>
  <div class="kontainer-balok">
      <!-- Balok 1: Tombol tambah disposisi -->
      <div class="balok-1">
          <!-- <a href="form_disposisi.php" class="btn-tambah">Upload</a> -->
          <button type="button" class="btn-tambah" onclick="exportTableToExcel()">Export</button>
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
          <table id="tabelSuratDisposisi" border="1" cellpadding="10" cellspacing="0">
              <tr>
                  <th>Tanggal</th>
                  <th>No. Surat</th>
                  <th>Ditujukan Kepada</th>
                  <th>
                      Instruksi
                      <div class="no-export">
                          <select onchange="filterTable('instruksi', this.value); showResetButton();">
                              <option value=""></option>
                              <option value="Belum">Belum</option>
                              <option value="Diterima">Diterima</option>
                              <option value="Ditolak">Ditolak</option>
                          </select>
                      </div>
                  </th>
                  <th>Keterangan</th>
                  <th>Status</th>
                  <th>File</th>
                  <th>Aksi</th>
              </tr>
              <?php foreach ($result as $row): ?>
              <tr id="row-<?= $row['id'] ?>" class="data-row">
                  <td><?= htmlspecialchars($row['tanggal']) ?></td>
                  <td><?= htmlspecialchars($row['no_surat']) ?></td>
                  <td><?= htmlspecialchars($row['ditujukan_kepada'] ?? '-') ?></td>
                  <td>
                      <span id="label-instruksi-<?= $row['id'] ?>">
                          <?= htmlspecialchars($row['instruksi']) ?>
                      </span>
                      <select id="sel-<?= $row['id'] ?>" class="dropdown-instruksi"
                              onchange="updateInstruksi(<?= $row['id'] ?>, this.value)">                      
                          <option value="">Belum Diproses</option>
                          <option value="Diterima" <?= $row['instruksi'] == 'Diterima' ? 'selected' : '' ?>>Diterima</option>
                          <option value="Diteruskan" <?= $row['instruksi'] == 'Diteruskan' ? 'selected' : '' ?>>Diteruskan</option>
                          <option value="Ditolak" <?= $row['instruksi'] == 'Ditolak' ? 'selected' : '' ?>>Ditolak</option>
                      </select>
                  </td>
                  <td>
                    <textarea
                      id="ket-<?= $row['id'] ?>"
                      placeholder="Tulis keterangan..."
                      onblur="saveKet(<?= (int)$row['id'] ?>, this.value)"
                      style="width:220px; min-height:60px; resize:vertical;"
                    ><?= h($row['keterangan'] ?? '') ?></textarea>
                  </td>
                  <td id="status-<?= $row['id'] ?>"><?= htmlspecialchars($row['status_disposisi']) ?></td>
                  <td><a href="<?= $row['file_url'] ?>" target="_blank"><?= basename($row['file_url']) ?></a></td>
                  <td>
                      <a href="update_surat_disposisi.php?id=<?= $row['id'] ?>">✏️</a><br>
                      <a href="surat_disposisi.php?delete=<?= $row['id'] ?>" onclick="return confirm('Hapus disposisi ini?')">🗑️</a>
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
  rows.forEach(row => {
    if (value === "" || row.dataset[attribute.toLowerCase()] === value) {
      row.style.display = "table-row";
    } else {
      row.style.display = "none";
    }
  });
  }
function exportTableToExcel() {
    var table = document.getElementById("tabelSuratDisposisi").cloneNode(true);

    // Hapus elemen no-export
    var filters = table.querySelectorAll(".no-export");
    filters.forEach(filter => filter.remove());

    var headers = table.querySelectorAll("th");
    var removeIndexes = [];

    // Cari index kolom yang mau dihapus
    headers.forEach((cell, index) => {
        var text = cell.childNodes[0].textContent.trim(); // ⬅️ Ambil hanya teks label
        if (text === "Aksi" || text === "File") {
            removeIndexes.push(index);
        }
    });

    // Ambil header (ambil teks node pertama saja)
    var headerCells = table.querySelectorAll('tr')[0].querySelectorAll('th');
    var headersArray = [];

    headerCells.forEach((cell, index) => {
        if (!removeIndexes.includes(index)) {
            headersArray.push(cell.childNodes[0].textContent.trim()); // ⬅️ Ini kunci
        }
    });

    // Ambil data isi
    var bodyRows = table.querySelectorAll('tr');
    var dataArray = [];

    // Mulai dari baris kedua (index 1), baris pertama adalah header
    for (var i = 1; i < bodyRows.length; i++) {
        var row = bodyRows[i];
        var rowData = [];
        var cells = row.querySelectorAll('td');
        cells.forEach((cell, index) => {
            if (!removeIndexes.includes(index)) {
                rowData.push(cell.textContent.trim());
            }
        });
        if (rowData.length > 0) { // Hindari baris kosong
            dataArray.push(rowData);
        }
    }

    // Gabungkan header dan data
    var exportData = [headersArray, ...dataArray];

    // Buat file Excel
    var ws = XLSX.utils.aoa_to_sheet(exportData);
    ws['!cols'] = [
      { wch: 15 }, // Tanggal
      { wch: 15 }, // No. Surat
      { wch: 30 }, // Ditujukan Kepada
      { wch: 15 }, // Instruksi
      { wch: 30 }, // Keterangan
      { wch: 15 }, // Status
    ];


    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "surat disposisi");

    XLSX.writeFile(wb, "surat disposisi.xlsx");
    alert("Data berhasil diekspor!");
}



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
  const rows = document.querySelectorAll("#tabelSuratDisposisi .data-row");

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

// function updateInstruksi(id, instruksi) {
//     if (instruksi === 'Belum') return;

//     fetch('surat_disposisi.php', {
//         method: 'POST',
//         headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
//         body: `id=${id}&instruksi=${instruksi}`
//     })
//     .then(response => response.text())
//     .then(data => {
//         data = data.trim();   // ← bersihin
//         if (data === 'success') {
//             // update tampilan biasa
//             document.getElementById('label-instruksi-' + id).innerText = instruksi; 
//             document.getElementById('status-' + id).innerText = 'Telah Diproses';   
//         } 
//         else if (data === 'success-redirect') {
//             // update tampilan + redirect ke tindak lanjut
//             document.getElementById('label-instruksi-' + id).innerText = instruksi; 
//             document.getElementById('status-' + id).innerText = 'Telah Diproses';   
//             window.location.href = 'surat_disposisi_tindak_lanjut.php?id=' + id;
//         } 
//         else {
//             alert('Gagal memperbarui data.');
//         }
//     });
// }

function saveKet(id, ket) {
  fetch('surat_disposisi.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `save_keterangan=1&id=${encodeURIComponent(id)}&keterangan=${encodeURIComponent(ket || '')}`
  })
  .then(r => r.text())
  .then(t => {
    const clean = t.replace(/<[^>]*>/g, '').trim().toLowerCase();
    if (clean === 'ok' || clean === 'success') return;
    throw new Error(clean || 'unknown');
  })
  .catch(err => alert('Gagal menyimpan keterangan: ' + err.message));
}


function updateInstruksi(id, instruksi) {
  // Ambil keterangan terkini di input yang sama baris
  const ketEl = document.getElementById('ket-' + id);
  const ket   = ketEl ? ketEl.value : '';

  fetch('surat_disposisi.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `id=${encodeURIComponent(id)}&instruksi=${encodeURIComponent(instruksi)}&keterangan=${encodeURIComponent(ket)}`
  })
  .then(response => response.text())
  .then(data => {
      data = data.trim();
      if (data === 'success') {
          document.getElementById('label-instruksi-' + id).innerText = instruksi || 'Belum Diproses';
          document.getElementById('status-' + id).innerText = instruksi ? 'Telah Diproses' : 'Belum Diproses';
      } else if (data === 'success-redirect') {
          document.getElementById('label-instruksi-' + id).innerText = instruksi;
          document.getElementById('status-' + id).innerText = 'Telah Diproses';
          window.location.href = 'surat_disposisi_tindak_lanjut.php?id=' + id;
      } else if (data.startsWith('error:')) {
          alert(data);
      } else {
          alert('Gagal memperbarui data.');
      }
  });
}


  </script>  
</body>
</html>





