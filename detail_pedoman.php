<?php
session_start();

// Cek user login
$user = $_SESSION['user'] ?? null;
$role = $user['status'] ?? null;
$can_edit = in_array($role, ['Sekretariat', 'Super Admin']);
$can_access_eoffice = in_array($role, ['Sekretariat', 'Direktur','Super Admin']);

// Koneksi database
$host = "localhost";
$db_user = "root";
$password = "";
$db_name = "rsch";

$koneksi = mysqli_connect($host, $db_user, $password, $db_name);
if (!$koneksi) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

// Hapus data jika tombol hapus diklik
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_id'])) {
    if (!$can_edit) {
        die("Akses tidak diizinkan.");
    }

    $hapus_id = mysqli_real_escape_string($koneksi, $_POST['hapus_id']);
    $hapus_query = "DELETE FROM layanan_pedoman WHERE id = '$hapus_id'";

    if (mysqli_query($koneksi, $hapus_query)) {
        header("Location: layanan_pedoman.php?hapus=berhasil");
        exit();
    } else {
        die("Gagal menghapus : " . mysqli_error($koneksi));
    }
}

// Ambil ID dari URL untuk tampilkan detail
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("ID tidak ditemukan.");
}

$id = mysqli_real_escape_string($koneksi, $_GET['id']);
$query = "SELECT * FROM layanan_pedoman WHERE id = '$id'";
$result = mysqli_query($koneksi, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    die("Data tidak ditemukan.");
}

$data = mysqli_fetch_assoc($result);
?>



<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panduan</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
</head>
<style>
  .detail-buku {
    padding: 30px;
    margin: 40px auto;
    background-color: #ffffff;
    border-radius: 10px;
    max-width: 1200px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    font-family: Arial, sans-serif;
  }

  .detail-buku h3 {
    text-align: baseline;
    font-size: 24px;
    color: #333;
    margin-bottom: 20px;
  }

  .buku-info {
    display: flex;
    align-items: flex-start;
    gap: 20px;
  }

  .buku-image img {
    max-width: 200px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  }

  .buku-details {
    flex-grow: 1;
    font-size: 16px;
    color: #333;
  }

  .buku-details h4 {
    text-align: center;
    font-size: 30px;
    margin-bottom: 10px;
  }

  .buku-details p {
    text-align: center;
    margin: 5px;
    font-size: 20px;
  }

  .action-buttons {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 20px;
  }

  .btn-edit {
    padding: 10px 20px;
    background-color:hsl(231, 100.00%, 65.70%);
    text-decoration: none;
    color: white;
    border-radius: 5px;
    font-weight: bold;
    transition: background-color 0.2s ease;
  }

  .btn-hapus {
    padding: 13px 18px;
    text-decoration: none;
    font-size: 15px;
    color: black;
    border: none;
    border-radius: 5px;
    font-weight: bold;
    transition: background-color 0.2s ease;
    }  


  .btn-edit:hover {
    background-color: #218838;
  }

  .btn-hapus {
    background-color: #dc3545;
  }

  .btn-hapus:hover {
    background-color: #c82333;
  }

  .download-container {
  text-align: center;
  margin-top: 20px; 
  }

  .btn-download {
  display: inline-flex;
  justify-content: center;
  align-items: center;
  background-color:hsl(119, 86.40%, 53.70%);
  color: white;
  padding: 10px 16px;
  text-decoration: none;
  border-radius: 5px;
  font-weight: bold;
  width: auto;
  }
  .btn-download:hover {
  background-color:rgb(6, 179, 0);
  }

  .btn-download2 {
  display: inline-flex;
  justify-content: center;
  align-items: center;
  background-color:rgba(251, 255, 33, 1);
  color: white;
  padding: 10px 16px;
  text-decoration: none;
  border-radius: 5px;
  font-weight: bold;
  width: auto;
  }
  .btn-download2:hover {
  background-color:hsla(62, 97%, 58%, 1.00);
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
      <!-- <div class="search-bar-bottom">
        <input type="text" placeholder="Cari..." />
        <button>Cari</button>
      </div> -->
    </div>
  </nav>

  <section class="detail-buku">
    <h3>Detail Panduan</h3>
    <div class="buku-info">
      <div class="buku-details">
        <h4><?= htmlspecialchars($data['judul']) ?></h4>
        <p><strong>Tanggal:</strong> <?= htmlspecialchars($data['tanggal']) ?></p>
        <p><strong>Judul:</strong> <?= htmlspecialchars($data['judul']) ?></p>
        <p><strong>Deskripsi:</strong><br><?= nl2br(htmlspecialchars($data['deskripsi'])) ?></p>      

        <?php if ($can_edit): ?>
          <div class="action-buttons">
            <a href="update_pedoman.php?id=<?= urlencode($data['id']) ?>" class="btn-edit">Edit</a>
            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus ini?');" style="display:inline;">
              <input type="hidden" name="hapus_id" value="<?= htmlspecialchars($data['id']) ?>">
              <button type="submit" class="btn-hapus">Hapus</button>
            </form>
          </div>
        <?php endif; ?>

        <?php if (!empty($data['file_url'])): ?>
          <div class="download-container">
            <!-- Tombol Download -->
            <a href="<?= htmlspecialchars($data['file_url']) ?>" class="btn-download" download>
              📥 Download File
            </a>
            <!-- Tombol Lihat -->
            <a href="<?= htmlspecialchars($data['file_url']) ?>" class="btn-download2" target="_blank">
              Lihat File
            </a>
          </div>
        <?php else: ?>
          <p><em>Tidak ada file untuk diunduh.</em></p>
        <?php endif; ?>
      </div>
    </div>
  </section>


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
