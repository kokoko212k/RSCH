<?php
session_start();
include 'config.php';

$user = $_SESSION['user'] ?? null;
$user_id = $user['user']['id'] ?? null;
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

// if (isset($_SESSION['flash'])) {
//     echo "<script>alert('{$_SESSION['flash']}');</script>";
//     unset($_SESSION['flash']);
// }

// Reset session
unset($_SESSION['buka_disposisi_pengajuan']);

// Cek login
if (!$user) {
    header("Location: login.php");
    exit;
}

// Proses update instruksi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['instruksi'], $_POST['id'])) {
    $id = $_POST['id'];
    $instruksi = trim($_POST['instruksi']);

    $stmt = $pdo->prepare("SELECT * FROM surat_disposisi_pengajuan WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $data = $stmt->fetch();

    if ($data) {
        $file_url = $data['file_url'];

        // Update disposisi_pengajuan
        $stmt = $pdo->prepare("UPDATE surat_disposisi_pengajuan SET instruksi = :instruksi, status = 'Telah Diproses' WHERE id = :id");
        $stmt->execute(['instruksi' => $instruksi, 'id' => $id]);

        // Update instruksi ke surat_pengajuan
        $stmt = $pdo->prepare("UPDATE surat_pengajuan SET instruksi = :instruksi WHERE file_url = :file_url");
        $stmt->execute(['instruksi' => $instruksi, 'file_url' => $file_url]);

        // Jika instruksi == Diterima, maka salin ke surat_masuk & surat_disposisi
        if ($instruksi === "Diterima") {
            $stmt = $pdo->prepare("SELECT * FROM surat_pengajuan WHERE file_url = :file_url");
            $stmt->execute(['file_url' => $file_url]);
            $pengajuan = $stmt->fetch();

            if ($pengajuan) {
                // -------------------------
                // 1) Upsert surat_masuk
                // -------------------------
                $stmt = $pdo->prepare("
                    UPDATE surat_masuk
                    SET tanggal_surat = :tanggal_surat,
                        dari = :dari,
                        file_url = :file_url,
                        tanggal_diterima = :tanggal_diterima,
                        instruksi = 'Diterima'
                    WHERE no_surat = :no_surat
                    LIMIT 1
                ");
                $stmt->execute([
                    'tanggal_surat'     => $pengajuan['tanggal'],
                    'dari'              => $pengajuan['dari'],
                    'file_url'          => $pengajuan['file_url'],
                    'tanggal_diterima'  => $tanggal_hari_ini,
                    'no_surat'          => $pengajuan['no_surat'],
                ]);

                if ($stmt->rowCount() === 0) {
                    // Tidak ada baris cocok → INSERT baru
                    $stmt = $pdo->prepare("
                        INSERT INTO surat_masuk
                        (tanggal_surat, no_surat, dari, file_url, tanggal_diterima, instruksi)
                        VALUES (:tanggal_surat, :no_surat, :dari, :file_url, :tanggal_diterima, 'Diterima')
                    ");
                    $stmt->execute([
                        'tanggal_surat'     => $pengajuan['tanggal'],
                        'no_surat'          => $pengajuan['no_surat'],
                        'dari'              => $pengajuan['dari'],
                        'file_url'          => $pengajuan['file_url'],
                        'tanggal_diterima'  => $tanggal_hari_ini,
                    ]);
                }

                // -------------------------
                // 2) Upsert surat_disposisi
                // -------------------------
                $stmt = $pdo->prepare("
                    UPDATE surat_disposisi
                    SET instruksi = 'Diterima',
                        tanggal   = :tanggal
                    WHERE (TRIM(no_surat) = TRIM(:no_surat) OR file_url = :file_url)
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([
                    'tanggal'  => $tanggal_hari_ini,
                    'no_surat' => $pengajuan['no_surat'],
                    'file_url' => $pengajuan['file_url'],
                ]);

                if ($stmt->rowCount() === 0) {
                    // Tidak ada baris cocok → INSERT baru (akan muncul teratas bila view ORDER BY id DESC)
                    $stmt = $pdo->prepare("
                        INSERT INTO surat_disposisi
                            (tanggal, no_surat, file_url)
                        VALUES
                            (:tanggal, :no_surat, :file_url)
                    ");
                    $stmt->execute([
                        'tanggal'  => $tanggal_hari_ini,
                        'no_surat' => $pengajuan['no_surat'],
                        'file_url' => $pengajuan['file_url'],
                    ]);
                }
            }
        }

        header('Content-Type: text/plain; charset=UTF-8');
        echo 'success';
    } else {
        echo 'not_found';
    }
    exit();
}

// Proses hapus
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM surat_disposisi_pengajuan WHERE id = :id");
    $stmt->execute(['id' => $_GET['delete']]);
    header("Location: surat_disposisi_pengajuan.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cari_surat'])) {
    $no_surat = $_POST['no_surat'] ?? '';
    $dari = $_POST['dari'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM surat_pengajuan WHERE no_surat = :no_surat AND dari = :dari");
    $stmt->execute(['no_surat' => $no_surat, 'dari' => $dari]);
    $data = $stmt->fetch();

    if ($data) {
        $stmt = $pdo->prepare("INSERT INTO surat_disposisi_pengajuan (tanggal, no_surat, dari, instruksi, status, file_url)
            VALUES (:tanggal, :no_surat, :dari, '', 'Belum Diproses', :file_url)");
        $stmt->execute([
            'tanggal' => $data['tanggal'],
            'no_surat' => $data['no_surat'],
            'dari' => $data['dari'],
            'file_url' => $data['file_url']
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

  <h2>Disposisi Pengajuan</h2>
    <!-- Balok 2: Tombol tambah disposisi -->
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
                      <select onchange="filterTable('instruksi', this.value); showResetButton();">
                          <option value=""></option>
                          <option value="Diterima">Diterima</option>
                          <option value="Ditolak">Ditolak</option>
                      </select>
                  </div>
              </th>
              <th>Status</th>
              <th>File</th>
              <th>Aksi</th>
          </tr>
      </thead>
      <tbody>
          <?php if (count($surat_pengajuan) === 0): ?>
              <tr><td colspan="7" align="center">Tidak ada data pengajuan masuk.</td></tr>
          <?php else: ?>
              <?php foreach ($surat_pengajuan as $i => $row): ?>
              <tr>
                  <td><?= $i + 1 ?></td>
                  <td><?= htmlspecialchars($row['no_surat']) ?></td>
                  <td><?= htmlspecialchars($row['tanggal']) ?></td>
                  <td><?= htmlspecialchars($row['dari']) ?></td>
                  <td>
                      <span id="label-instruksi-<?= $row['id'] ?>">
                          <?= htmlspecialchars($row['instruksi']) ?>
                      </span>
                      <select class="dropdown-instruksi" onchange="updateInstruksi(<?= $row['id'] ?>, this.value)">
                      <option value="">Belum Diproses</option>
                      <option value="Diterima" <?= $row['instruksi'] == 'Diterima' ? 'selected' : '' ?>>Diterima</option>
                      <option value="Ditolak" <?= $row['instruksi'] == 'Ditolak' ? 'selected' : '' ?>>Ditolak</option>
                      </select>
                  </td>
                  <td id="status-<?= $row['id'] ?>"><?= htmlspecialchars($row['status']) ?></td>
                  <td>
                      <?php if (!empty($row['file_url'])): ?>
                        <a href="<?= $row['file_url'] ?>" target="_blank"><?= basename($row['file_url']) ?></a>
                      <?php else: ?>
                          (Kosong)
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
      <p>© Copyright Humas Marketing Citra Husada.</p>
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
  if (typeof XLSX === 'undefined') {
    alert('Library XLSX belum dimuat. Pastikan xlsx.full.min.js sudah di-include.');
    return;
  }

  const table = document.getElementById('tabelSuratDisposisiPengajuan');
  if (!table) { alert('Tabel tidak ditemukan.'); return; }

  // --- Kumpulkan index kolom yang perlu dibuang (File, Aksi) ---
  const headerRow = table.querySelector('thead tr');
  if (!headerRow) { alert('Header tabel tidak ditemukan.'); return; }
  const ths = Array.from(headerRow.querySelectorAll('th'));

  const removeIdx = [];
  const headers = [];

  ths.forEach((th, idx) => {
    const label = (th.childNodes[0]?.textContent || th.textContent || '').trim();
    if (label === 'File' || label === 'Aksi') {
      removeIdx.push(idx);
    } else {
      headers.push(label);
    }
  });

  // --- Ambil data baris yang terlihat saja ---
  const bodyRows = Array.from(table.querySelectorAll('tbody tr'));
  const data = [];

  bodyRows.forEach(tr => {
    // Hanya ekspor yang terlihat (display != none)
    const isHidden = window.getComputedStyle(tr).display === 'none';
    if (isHidden) return;

    const tds = Array.from(tr.querySelectorAll('td'));
    if (!tds.length) return;

    const row = [];

    tds.forEach((td, idx) => {
      if (removeIdx.includes(idx)) return;

      // Khusus kolom Instruksi: ambil dari span label jika ada
      const spanLabel = td.querySelector('[id^="label-instruksi-"]');
      let text;
      if (spanLabel) {
        text = spanLabel.textContent.trim();
      } else {
        // fallback umum: ambil textContent cell (tanpa newline berlebih)
        text = (td.textContent || '').replace(/\s+\n|\n+\s+/g, ' ').trim();
      }
      row.push(text);
    });

    // Hindari push baris kosong
    if (row.some(v => v !== '')) data.push(row);
  });

  // Gabungkan header + data
  const aoa = [headers, ...data];

  // Buat sheet
  const ws = XLSX.utils.aoa_to_sheet(aoa);

  // Lebar kolom dinamis (perkiraan berdasarkan panjang header)
  ws['!cols'] = [
    { wch: 6 },   // No
    { wch: 20 },  // No. Surat
    { wch: 14 },  // Tanggal
    { wch: 28 },  // Dari
    { wch: 20 },  // Instruksi
    { wch: 20 },  // Status
  ];

  // Buat workbook & tulis file
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'surat disposisi pengajuan');
  XLSX.writeFile(wb, 'surat_disposisi_pengajuan.xlsx');

  alert('Data berhasil diekspor!');
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

function updateInstruksi(id, instruksi) {
  fetch('surat_disposisi_pengajuan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(id) + '&instruksi=' + encodeURIComponent(instruksi)
  })
  .then(r => r.text())
  .then(t => {
      t = t.trim(); // penting!
      if (t === 'success') {
          const label = document.getElementById('label-instruksi-' + id);
          if (label) label.innerText = instruksi || 'Belum Diproses';

          const st = document.getElementById('status-' + id);
          if (st) st.innerText = instruksi ? 'Telah Diproses' : 'Belum Diproses';
      } else {
          alert('Gagal memperbarui data: ' + t);
      }
  })
  .catch(err => alert('Error jaringan: ' + err.message));
}

  </script>  
</body>
</html>