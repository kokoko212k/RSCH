<?php
session_start();
include 'config.php';

if (isset($_GET['success']) && $_GET['success'] == 1) {
    echo "<script>alert('Data berhasil disimpan!');</script>";
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'] ?? null;
$role = $user['status'] ?? null;
$can_access_eoffice = in_array($role, ['Sekretariat', 'Super Admin']);

// Hapus Data
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM surat_internal WHERE id = :id");
    $stmt->execute(['id' => $id]);
    header("Location: surat_internal.php");
    exit();
}

// Ambil data unik untuk dropdown
$noSuratResult = $pdo->query("SELECT DISTINCT no_surat FROM surat_internal");
$perihalResult = $pdo->query("SELECT DISTINCT perihal FROM surat_internal");
$dariResult = $pdo->query("SELECT DISTINCT dari FROM surat_internal");

$result = $pdo->query("SELECT * FROM surat_internal ORDER BY id DESC");

// Proses input data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal_surat = $_POST['tanggal_surat'];
    $no_surat = $_POST['no_surat'];
    $perihal = $_POST['perihal'];
    $dari = $_POST['dari'];

    $uploaded_files = [];
    $target_dir = "surat_internal/files/";
    $allowed_types = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/jpeg',
        'image/png',
        'image/gif'
    ];

    if (isset($_FILES["file_url"])) {
        foreach ($_FILES["file_url"]["tmp_name"] as $key => $tmp_name) {
            $file_name = basename($_FILES["file_url"]["name"][$key]);
            $file_tmp = $_FILES["file_url"]["tmp_name"][$key];
            $file_type = $_FILES["file_url"]["type"][$key];
            $file_error = $_FILES["file_url"]["error"][$key];
            $file_size = $_FILES["file_url"]["size"][$key];

            if (
                $file_error === UPLOAD_ERR_OK &&
                in_array($file_type, $allowed_types) &&
                $file_size <= 5 * 1024 * 1024
            ) {
                $target_file = $target_dir . $file_name;
                if (move_uploaded_file($file_tmp, $target_file)) {
                    $uploaded_files[] = $target_file;
                }
            }
        }
    }

    // Gabungkan semua nama file (pisahkan dengan koma)
    $file_url_combined = implode(',', $uploaded_files);

    $stmt = $pdo->prepare("
        INSERT INTO surat_internal (
            tanggal_surat, no_surat, 
            perihal, dari, 
            file_url
        ) 
        VALUES (
            :tanggal_surat, :no_surat, 
            :perihal, :dari, 
            :file_url
        )
    ");
    $stmt->execute([
        'tanggal_surat' => $tanggal_surat,
        'no_surat' => $no_surat,
        'perihal' => $perihal,
        'dari' => $dari,
        'file_url' => $file_url_combined
    ]);
    header('Location: surat_internal.php?success=1');
    exit();
}
?>



<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tambah Surat Internal</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/daerah-indonesia@1.1.0/index.min.js"></script>
</head>
  <style>
    /* Styling Umum */
    body {
      font-family: Arial, sans-serif;
      background-color: #f2f2f2;
    }
    .container {
      background-color: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      max-width: 500px;
      margin: 30px auto;
    }

    h2 {
      text-align: center;
      color: #333;
    }

    .input-row {
      margin-bottom: 15px;
    }

    input, textarea, select {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      border: 1px solid #ccc;
      border-radius: 5px;
    }

    input[type="text"], input[type="email"], input[type="password"], input[type="date"] {
      font-size: 14px;
    }

    textarea {
      height: 100px;
      font-size: 14px;
    }

    .button-row {
      display: flex;
      margin-top: 20px;
    }

    .submit-btn, .cancel-btn {
      padding: 10px 20px;
      font-size: 16px;
      cursor: pointer;
      border-radius: 5px;
      border: none;
      gap: 5px;      
    }

    .submit-btn {
      background-color:hsl(224, 100.00%, 65.30%);
      color: white;
      margin-right: 5px;
    }

    .cancel-btn {
      background-color: #f44336;
      color: white;
    }

    /* Responsif untuk layar kecil */
    @media (max-width: 600px) {
      .container {
        width: 100%;
        padding: 20px;
      }
    }

    /* Styling Dropdown Status */
    .status-row {
      margin-bottom: 10px;
    }

    .status-dropdown {
      display: none; /* Dropdown disembunyikan awalnya */
      margin-top: 10px;
    }

    .status-row.active .status-dropdown {
      display: block; /* Dropdown muncul ketika salah satu opsi dipilih */
    }

    .status-options select {
      width: 100%;
      padding: 10px;
      border-radius: 5px;
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


<div class="container">
  <h2>Tambah Surat Internal</h2>
    <form action="buat_surat_internal.php" method="POST" enctype="multipart/form-data" autocomplete="off">
    <div class="input-row">
      <label for="tanggal_surat">Tanggal Surat</label>        
      <input type="date" id="tanggal_surat" name="tanggal_surat" required />
    </div>
    <div class="input-row">
      <label for="no_surat">No. Surat</label>        
        <input type="text" name="no_surat" required />
    </div> 
    <div class="status-row">
      <label for="perihal">Perihal</label>
      <input type="text" name="perihal" required />
    </div>   
    <div class="status-row">
    <label for="dari">Dari</label>
    <select name="dari" id="dari-select" onchange="toggleDropdown(this)" required>
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
        <option value="KASIR RAWAT INAP">KASIR RAWAT INAP & JALAN</option>
        <option value="KASIR RAWAT INAP">KASIR RAWAT INAP</option>
        <option value="KASIR RAWAT JALAN">KASIR RAWAT JALAN</option>
        <option value="TIM PENGENDALI BPJS (Casemix)">TIM PENGENDALI BPJS (Casemix)</option>
        <option value="NS LOTUS">NS LOTUS</option>
        <option value="NS TULIP">NS TULIP</option>
        <option value="KOMITE KEPERAWATAN">KOMITE KEPERAWATAN</option>
        <option value="TIM PKRS">TIM PKRS</option>
    </select>
    </div>
    <div class="input-row">
      <label for="file">File Surat Internal</label>        
      <input type="file" id="file" name="file_url[]" 
      multiple accept= ".pdf,
               .doc,.docx,
               .xls,.xlsx,
               .ppt,.pptx,
               .jpg,.jpeg,.png,.gif" />
    </div>      
    <div class="button-row">
      <button type="submit" class="submit-btn">Simpan</button>
      <button type="button" class="cancel-btn" onclick="window.history.back();">Batal</button>
    </div>
  </form>
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
    // Fungsi untuk menampilkan dropdown ketika status dipilih
    function toggleDropdown(select) {
      var statusRow = document.querySelector('.status-row');
      var statusDisplay = document.getElementById('status-display');
      var statusDropdown = document.querySelector('.status-dropdown');

      if (select.value !== "") {
        statusDropdown.style.display = 'block'; // Tampilkan dropdown
        statusDisplay.textContent = select.options[select.selectedIndex].text; // Tampilkan status yang dipilih
      } else {
        statusDropdown.style.display = 'none'; // Sembunyikan dropdown jika tidak ada pilihan
      }
    }
          // === DROPDOWN USER LOGIN ===
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

      async function loadKota() {
    const res = await fetch("https://www.emsifa.com/api-wilayah-indonesia/api/regencies.json");
    const cities = await res.json();
    const select = document.getElementById("tempat_lahir");

    cities.forEach(city => {
      const opt = document.createElement("option");
      opt.value = city.name; // Simpan hanya nama kota
      opt.textContent = city.name; // Tampilkan ke user
      select.appendChild(opt);
    });
  }

  loadKota();
  </script>
  <script src="script.js"></script>
  <script>
  if (!document.querySelector('.submit-btn')) {
    console.warn("script.js mungkin belum dimuat atau belum berfungsi.");
  }
</script>

</body>
</html>