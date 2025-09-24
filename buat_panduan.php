<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

// Ambil status/role dari session
$role = $_SESSION['user']['status']; 
$can_access_eoffice = in_array($role, ['Sekretariat', 'Direktur','Super Admin']);

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
  die("Koneksi gagal: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
$tanggal            = $_POST['tanggal'] ?? '';
$judul              = $_POST['judul'] ?? '';
$deskripsi          = $_POST['deskripsi'] ?? '';

$upload_dir = 'panduan/files/';
$file_url = '';

if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['file']['tmp_name'];
    $file_name = basename($_FILES['file']['name']);
    $target_file = $upload_dir . time() . '_' . $file_name;

    $allowed_types = ['application/pdf'];
    if (!in_array($_FILES['file']['type'], $allowed_types)) {
        echo "<script>alert('Tipe file tidak diizinkan. Hanya PDF yang diperbolehkan.'); window.history.back();</script>";
        exit;
    }

    if (move_uploaded_file($file_tmp, $target_file)) {
        $file_url = $target_file; 
    } else {
        echo "<script>alert('Gagal mengunggah file.'); window.history.back();</script>";
        exit;
    }
} else {
    echo "<script>alert('File tidak ditemukan atau terjadi kesalahan upload.'); window.history.back();</script>";
    exit;
}

// Validasi kosong
if (
  empty($tanggal) || empty($judul) ||  empty($deskripsi) || empty($file_url)) {
  echo "<script>alert('Semua field wajib diisi.'); window.history.back();</script>";
  exit;
}

// Validasi format tanggal
if (!DateTime::createFromFormat('Y-m-d', $tanggal)) {
    echo "<script>alert('Format tanggal lahir tidak valid. Gunakan format YYYY-MM-DD.'); window.history.back();</script>";
}

// Cek NIK duplikat
$check = $pdo->prepare("SELECT COUNT(*) FROM layanan_panduan WHERE judul = :judul");
$check->execute([':judul' => $judul]);
$exists = $check->fetchColumn();

if ($exists > 0) {
    echo "<script>alert('Judul sudah digunakan.'); window.history.back();</script>";
    exit;
  }





// Masukkan data ke database
$sql = "INSERT INTO layanan_panduan (tanggal, judul, deskripsi,  file_url) 
        VALUES (:tanggal, :judul, :deskripsi, :file_url)";
$stmt = $pdo->prepare($sql);

try {
  $stmt->execute([
    ':tanggal'          => $tanggal,
    ':judul'            => $judul,
    ':deskripsi'        => $deskripsi,
    ':file_url'         => $file_url,
  ]);
  echo "<script>alert('Tambah panduan berhasil!'); window.location.href = 'layanan_panduan.php';</script>";
  exit();
} catch (Exception $e) {
  echo "Terjadi kesalahan: " . $e->getMessage();
}
}
?>


<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tambah Panduan</title>
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

    .container h3 {
      text-align: center;
      color: #333;
      padding: 20px;
      font-size: 25px;
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

    .form-action{
      display: flex;
    }

    .button-row {
      width: fit-content;
      background-color: #007bff;
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      margin-top: 10px;            
      margin-bottom: 30px;      
    }

    .cancel-btn {
      width: fit-content;
      background-color:hsl(15, 100.00%, 50.00%);
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      margin-top: 10px;            
      margin-bottom: 30px;      
    }    

    .button-row:hover {
      /* background-color:hsl(211, 100.00%, 66.10%); */
      cursor: pointer;  
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

    .footer-section h3 {
    text-align: baseline;
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


    <section class="container">
      <h3>Tambah Panduan</h3>
        <form action="buat panduan.php" method="POST" enctype="multipart/form-data">
        <div class="input-row">
        <div class="input-row">
          <label for="tanggal">Tanggal</label>
          <input type="date" id="tanggal" name="tanggal" required />
        </div>
          <label for="judul">Judul</label>
          <input type="text" id="judul" name="judul" required />
        </div>
        <div class="input-row">
          <label for="deskrispi">Deskripsi</label>
          <textarea id="deskrispi" name="deskripsi" required></textarea>
        </div>
        <div class="input-row">
          <label for="file">File Panduan</label>
          <input type="file" id="file" name="file" accept="application/pdf" />
        </div>                 
        <div class="form-actions">
          <button type="submit" class="button-row">Simpan</button>
          <a href="layanan_panduan.php" class="cancel-btn">Batal</a>
      </div>
      </form>
    </section>
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
  </script>
  <script src="script.js"></script>
</body>
</html>
