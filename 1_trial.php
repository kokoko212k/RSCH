<?php
session_start();
include 'config.php';

$user = $_SESSION['user'] ?? null;
$user_id = $user['user']['id'] ?? null;
$role = $user['status'] ?? null;
$can_access_eoffice = in_array($role, ['Sekretariat', 'Direktur','Super Admin']);


$ip = $_SERVER['REMOTE_ADDR'];
$halaman = 'beranda';
$stmt = $pdo->prepare("INSERT INTO log_kunjungan (ip_address, user_id, halaman) VALUES (?, ?, ?)");
$stmt->execute([$ip, $user_id, $halaman]);

// $tipe = 'Panduan'; // Atau 'Pedoman', 'SOP'
// $dokumen_id = $_GET['id'];
// $stmt = $pdo->prepare("INSERT INTO log_view_dokumen (user_id, tipe, dokumen_id) VALUES (?, ?, ?)");
// $stmt->execute([$user_id, $tipe, $dokumen_id]);

$sql = "SELECT DATE_FORMAT(tanggal_kunjungan, '%Y-%m') AS bulan, COUNT(*) AS total 
        FROM log_kunjungan 
        GROUP BY bulan 
        ORDER BY bulan DESC
        LIMIT 6"; 
$data_kunjungan = $pdo->query($sql)->fetchAll();
$data_kunjungan = array_reverse($data_kunjungan);

// Genre buku dari koleksi
$sql = "SELECT genre, COUNT(*) AS total 
        FROM log_baca_buku 
        GROUP BY genre 
        ORDER BY total DESC 
        LIMIT 5";
$data_genre = $pdo->query($sql)->fetchAll();

// View dokumen Panduan, Pedoman, SOP
$sql = "SELECT tipe, COUNT(*) AS total 
        FROM log_view_dokumen 
        GROUP BY tipe";
$data_dokumen = $pdo->query($sql)->fetchAll();

// View promkes
$sql = "SELECT DATE_FORMAT(tanggal_view, '%Y-%m') AS bulan, COUNT(*) AS total 
        FROM log_view_promkes 
        GROUP BY bulan 
        ORDER BY bulan DESC 
        LIMIT 6";
$data_promkes = array_reverse($pdo->query($sql)->fetchAll());

$artikelResult = $pdo->query("SELECT * FROM artikel ORDER BY tanggal DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
$video = $pdo->query("SELECT * FROM video ORDER BY tanggal DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
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
</head>
<style>
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

.grafik-section {
  padding: 20px;
}

.grafik-box {
  margin-bottom: 40px;
}

canvas {
  max-width: 100%;
  height: auto;
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
            <a href="users.php">Data User</a>            
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

  <!-- Hero Section -->
<section class="welcome">
    <div class="welcome-title">
      <h1>Welcome to <br><span>RUANG BACA VIRTUAL</span></h1>
    </div>
    <div class="welcome-description">
      <p>Discover Knowledge Without Limits</p>
    </div>
  </section>

<!-- Trend Buku Section -->
<section class="section trend-buku">
  <h2>Trend Buku</h2>
  <div class="trend-container">
    <div class="buku fade-in">
      <img src="Buku\Buku RSCH 1.jpeg" alt="Buku 1">
      <h3>KAMUS LENGKAP KEDOKTERAN</h3>
      <!-- <p>Penulis: Nama Penulis 1</p> -->
    </div>
    <div class="buku fade-in">
      <img src="Buku\Buku RSCH 2.jpeg" alt="Buku 2">
      <h3>MANAJEMEN INFORMASI KESEHATAN</h3>
      <!-- <p>Penulis: Nama Penulis 2</p> -->
    </div>
    <div class="buku fade-in">
      <img src="Buku\Buku RSCH 3.jpeg" alt="Buku 3">
      <h3>ANATOMI UNTUK MAHASISWA KEDOKTERAN GIGI</h3>
      <!-- <p>Penulis: Nama Penulis 3</p> -->
    </div>
  <div class="buku fade-in">
    <img src="Buku\Buku RSCH 1.jpeg" alt="Buku 4">
    <h3>KAMUS LENGKAP KEDOKTERAN</h3>
    <!-- <p>Penulis: Nama Penulis 4</p> -->
  </div>
  <div class="buku fade-in">
    <img src="Buku\Buku RSCH 2.jpeg" alt="Buku 5">
    <h3>MANAJEMEN INFORMASI KESEHATAN</h3>
    <!-- <p>Penulis: Nama Penulis 5</p> -->
  </div>
  <div class="buku fade-in">
    <img src="Buku\Buku RSCH 3.jpeg" alt="Buku 6">
    <h3>ANATOMI UNTUK MAHASISWA KEDOKTERAN GIGI</h3>
    <!-- <p>Penulis: Nama Penulis 6</p> -->
  </div>
</div>
  <div class="center-button">
    <a href="buku-lain.php" class="tampilkan-lainnya">Tampilkan Lainnya</a>
  </div>  
</section>

<!-- Grafik Section -->
<section class="grafik-section">
  <h2>Statistik Pengunjung</h2>

  <div class="grafik-box">
    <canvas id="chartKunjungan"></canvas>
    <script>
    const ctx = document.getElementById('chartKunjungan').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($data_kunjungan, 'bulan')) ?>,
            datasets: [{
                label: 'Jumlah Kunjungan',
                data: <?= json_encode(array_column($data_kunjungan, 'total')) ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.5)'
            }]
        }
    });
    </script>
  </div>

  <h3>Statistik Genre Koleksi</h3>
  <div class="grafik-box">
    <canvas id="grafikKoleksi"></canvas>
    <script>
    const ctxKoleksi = document.getElementById('grafikKoleksi').getContext('2d');
    new Chart(ctxKoleksi, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($data_genre, 'genre')) ?>,
            datasets: [{
                label: 'Jumlah Dibaca',
                data: <?= json_encode(array_column($data_genre, 'total')) ?>,
                backgroundColor: 'rgba(255, 159, 64, 0.6)'
            }]
        }
    });
    </script>
  </div>

  <h3>Statistik View Dokumen</h3>
  <div class="grafik-box">
    <canvas id="grafikDokumen"></canvas>
    <script>
    const ctxDokumen = document.getElementById('grafikDokumen').getContext('2d');
    new Chart(ctxDokumen, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($data_dokumen, 'tipe')) ?>,
            datasets: [{
                label: 'Jumlah View',
                data: <?= json_encode(array_column($data_dokumen, 'total')) ?>,
                backgroundColor: 'rgba(153, 102, 255, 0.6)'
            }]
        }
    });
    </script>
  </div>

  <h3>Statistik Promkes</h3>
  <div class="grafik-box">
    <canvas id="grafikPromkes"></canvas>
    <script>
    const ctxPromkes = document.getElementById('grafikPromkes').getContext('2d');
    new Chart(ctxPromkes, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_column($data_promkes, 'bulan')) ?>,
            datasets: [{
                label: 'View Promkes per Bulan',
                data: <?= json_encode(array_column($data_promkes, 'total')) ?>,
                backgroundColor: 'rgba(255, 99, 132, 0.4)',
                borderColor: 'rgba(255, 99, 132, 1)',
                fill: true
            }]
        }
    });
    </script>
  </div>
</section>


<!-- Fasilitas-->
<section class="fasilitas">
  <h2>Fasilitas</h2>
  <div class="konten-fasilitas">
    <div class="balok-kanan">
      <h3><i class="icon">👓</i>Literasi Digital</h3>
      <p>Fitur ini menyediakan koleksi digital, termasuk jurnal medis, buku, dan artikel ilmiah yang mempermudah akses dan pencarian bahan pustaka secara online.</p>
    </div>
    <div class="balok-kanan">
      <h3><i class="icon">🖥️</i>Ruang Komputer</h3><br>
      <p>Area ini dilengkapi dengan perangkat komputer yang dapat digunakan pengunjung untuk mengakses informasi, mengetik dokumen, atau menjalankan program tertentu</p>
    </div>
    <div class="balok-kanan">
      <h3><i class="icon">📶</i>Zona WiFi</h3>
      <p>Zona ini menyediakan akses internet untuk mendukung aktivitas Anda secara online</p>
      </div>
  </div>
</section>

<!-- <section class="video">
  <div class="video-wrapper">
    <iframe 
      width="560" 
      height="315" 
      src="https://www.youtube.com/embed/b5jSucGS-RA" 
      frameborder="0" 
      allowfullscreen>
    </iframe>
  </div>
</section> -->
<section class="video">
  <div class="video-wrapper">
    <?php if ($video): ?>
      <iframe 
        width="560" 
        height="315" 
        src="<?= htmlspecialchars($video['file_url']) ?>" 
        frameborder="0" 
        allowfullscreen>
      </iframe>
    <?php else: ?>
      <p>Tidak ada video.</p>
    <?php endif; ?>
  </div>
</section>


<!-- <section class="artikel-section">
  <h2>Artikel Terbaru</h2>
  <div class="artikel-container">
    <div class="artikel fade-in">
      <img src="Artikel\Artikel RSCH 1.jpeg" alt="Artikel 1">
      <h3>Judul Artikel 1</h3>
      <p>Isi Artikel 1.</p>
    </div>
    <div class="artikel fade-in">
      <img src="Artikel\Artikel RSCH 2.jpeg" alt="Artikel 2">
      <h3>Judul Artikel 2</h3>
      <p>Isi Artikel 2.</p>
    </div>
    <div class="artikel fade-in">
      <img src="Artikel\Artikel RSCH 3.jpeg" alt="Artikel 3">
      <h3>Judul Artikel 3</h3>
      <p>Isi Artikel 3.</p>
    </div>
  </div>
  <div class="center-button">
    <a href="artikel-lain.php" class="tampilkan-lainnya">Tampilkan Lainnya</a>
  </div>  
</section> -->
<section class="artikel-section">
  <h2>Artikel Terbaru</h2>
  <div class="artikel-container">
    <?php foreach ($artikelResult as $artikel): ?>
      <div class="artikel fade-in">
        <img src="<?= htmlspecialchars($artikel['image_url'] ?? '') ?>" alt="<?= htmlspecialchars($artikel['judul'] ?? '') ?>">
        <h3><?= htmlspecialchars($artikel['judul']) ?></h3>
        <p><?= htmlspecialchars($artikel['deskripsi']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="center-button">
    <a href="artikel.php" class="tampilkan-lainnya">Tampilkan Lainnya</a>
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
</body>
</html>
