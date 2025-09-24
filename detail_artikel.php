<?php
session_start();
include 'config.php'; // Pastikan config pakai PDO $pdo

// Cek user login
$user = $_SESSION['user'] ?? null;
$role = $user['status'] ?? null;
$can_edit = in_array($role, ['Admin', 'Super Admin']);
$can_access_eoffice = in_array($role, ['Sekretariat', 'Direktur','Super Admin']);

if (!$user) {
    die("Anda harus login terlebih dahulu.");
}

// Proses hapus artikel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_id'])) {
    if (!$can_edit) {
        die("Akses tidak diizinkan.");
    }

    $hapus_id = $_POST['hapus_id'];
    $hapus = $pdo->prepare("DELETE FROM artikel WHERE id = :id");
    if ($hapus->execute(['id' => $hapus_id])) {
        header("Location: artikel.php?hapus=berhasil");
        exit();
    } else {
        die("Gagal menghapus artikel.");
    }
}

// Ambil ID artikel dari URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("ID artikel tidak ditemukan.");
}

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM artikel WHERE id = :id");
$stmt->execute(['id' => $id]);
$data = $stmt->fetch();

if (!$data) {
    die("Data artikel tidak ditemukan.");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Detail Artikel</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .detail-artikel {
      padding: 30px;
      margin: 40px auto;
      background-color: #ffffff;
      border-radius: 10px;
      max-width: 1000px;
      box-shadow: 0 4px 16px rgba(0,0,0,.1);
      font-family: Arial, sans-serif;
    }
    .detail-artikel h2 { text-align: center; margin-bottom: 20px; }
    .artikel-image { display: block; width:80%; max-height:550px; object-fit:cover; border-radius:8px; margin: 0 auto;}
    .artikel-meta { color:#rgba(0,0,0,.1); font-size:20px; margin:10px 0; }
    .artikel-isi { margin-top:20px; line-height:1.6; font-size:16px; }
    .action-buttons { margin-top:20px; display:flex; gap:10px; }
    .btn-edit {
      padding:10px 16px; border-radius:5px; font-weight:bold; text-decoration:none;
    }
    .btn-hapus {
      padding:14px 16px; border-radius:5px; font-weight:bold; text-decoration:none;
    }
    .btn-edit { background:#007BFF; color:white; }
    .btn-edit:hover { background:#0056b3; }
    .btn-hapus { background:#dc3545; color:white; border:none; }
    .btn-hapus:hover { background:#c82333; }
    .btn-download {
      display:inline-block; margin-top:15px; padding:10px 16px;
      background:#28a745; color:white; border-radius:5px; font-weight:bold;
    }
    .btn-download:hover { background:#218838; }
    .user-dropdown {
    position: relative;
    display: inline-block;
    }

    .user-icon {
    font-size: 30px;
    cursor: pointer;
    color: white; 
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
      <a href="sub_beranda.php" class="jelajahi-portal">Layanan</a>
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
            <!-- <a href="surat_internal.php">Surat Internal</a>      -->
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

  <div class="main-content">
    <section class="detail-artikel">
      <h2><?= htmlspecialchars($data['judul']) ?></h2>
      
      <?php if (!empty($data['image_url'])): ?>
        <img src="<?= htmlspecialchars($data['image_url']) ?>" alt="Gambar Artikel" class="artikel-image">
      <?php endif; ?>

      <div class="artikel-meta">
        <strong>Tanggal:</strong> <?= htmlspecialchars($data['tanggal'] ?? '-') ?>
        <p><strong>Deskripsi:</strong><br><?= nl2br(htmlspecialchars($data['deskripsi'])) ?></p>   
      </div>


      <?php if (!empty($data['file_url']) && file_exists($data['file_url'])): ?>
        <a href="<?= htmlspecialchars($data['file_url']) ?>" class="btn-download" download>📥 Download</a>
      <?php endif; ?>

      <?php if ($can_edit): ?>
        <div class="action-buttons">
          <a href="update_artikel.php?id=<?= urlencode($data['id']) ?>" class="btn-edit">Edit</a>
          <form method="POST" onsubmit="return confirm('Yakin ingin menghapus artikel ini?');">
            <input type="hidden" name="hapus_id" value="<?= htmlspecialchars($data['id']) ?>">
            <button type="submit" class="btn-hapus">Hapus</button>
          </form>
        </div>
      <?php endif; ?>
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


document.getElementById('image').addEventListener('change', function(event) {
    const file = event.target.files[0];
    const reader = new FileReader();
    const preview = document.getElementById('preview');
    
    reader.onload = function(e) {
    preview.src = e.target.result;
    preview.style.display = 'block';  // Menampilkan gambar
    }
    
    if (file) {
    reader.readAsDataURL(file);
    }
});
  </script>
</body>
</html>
