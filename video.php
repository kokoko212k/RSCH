<?php
session_start();
$host = "localhost";
$user = "root";
$password = "";
$db = "rsch";

$koneksi = mysqli_connect($host, $user, $password, $db);
if (!$koneksi) die("Koneksi gagal: " . mysqli_connect_error());

// ==== Pencarian ====
$search = '';
if (isset($_GET['search'])) {
    $search = mysqli_real_escape_string($koneksi, $_GET['search']);
    $query  = "SELECT * FROM video WHERE judul LIKE '%$search%' OR deskripsi LIKE '%$search%' OR tanggal LIKE '%$search%' ORDER BY tanggal DESC";
} else {
    $query = "SELECT * FROM video ORDER BY tanggal DESC";
}
$result = mysqli_query($koneksi, $query);

// ==== Session & Role ====
$user = $_SESSION['user'] ?? null;
$role = $user['status'] ?? null;
$can_edit = in_array($role, ['Admin', 'Super Admin']);
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
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Video</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <style>
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


    .video-section { padding: 20px; max-width: 1200px; margin: auto; }
    .btn-tambah { background-color: hsl(211,77%,54%); padding: 5px 8px; font-size: 15px; border-radius: 5px; text-decoration: none; font-weight: bold; display: inline-block; margin-right: 10px; color: #fff; }
    .video-search { display: flex; justify-content: right; margin-bottom: 20px; }
    .video-search input[type="text"] { width: 300px; padding: 10px; border: 1px solid #ccc; border-radius: 5px 0 0 5px; }
    .video-search button { padding: 10px 15px; color: white; border: none; background-color: #4CAF50; border-radius: 0 5px 5px 0; cursor: pointer; }

    .video-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
    .video-card { border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,.05); background-color: white; display: flex; flex-direction: column;}
    .video-thumb { width: 100%; height: 300px; object-fit: cover; background: #000; }
    .video-info { padding: 15px; display: flex; flex-direction: column; gap: 8px; flex-grow: 1; }
    .video-info h3 { margin: 0; font-size: 18px; }
    .video-meta { font-size: 13px; color: #666; }
    .video-deskripsi { font-size: 14px; line-height: 1.5; color: #333; }
    .detail-btn { margin-top: auto; align-self: center; display: inline-block; padding: 8px 12px; background-color: #007BFF; color: white; text-decoration: none; border-radius: 5px; }
    .detail-btn:hover { background-color: #0056b3; }
  </style>
</head>
<body>
  <div class="main-content">
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
              <!-- <a href="surat_internal.php">Surat Internal</a> -->
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

    <div class="video-section">
        <h2>Daftar Video</h2>
        <!-- Search Bar -->
        <div class="video-search">
        <?php if ($can_edit): ?>
            <a href="buat_video.php" class="btn-tambah">+ Video</a>
        <?php endif; ?>
        <form method="GET" action="video.php">
            <input type="text" name="search" placeholder="Cari judul/deskripsi/tanggal ..." value="<?php echo htmlspecialchars($search); ?>"/>
            <button type="submit">Cari</button>
        </form>
        </div>

        <!-- Grid Video -->
        <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <div class="video-grid">
            <?php while ($row = mysqli_fetch_assoc($result)) : ?>
            <div class="video-card">
                <?php if (!empty($row['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($row['image_url']); ?>" alt="Thumbnail Video" class="video-thumb">
                <?php else: ?>
                <img src="Properti/no-video.jpg" alt="No Thumbnail" class="video-thumb">
                <?php endif; ?>

                <div class="video-info">
                <h3><?php echo htmlspecialchars($row['judul']); ?></h3>
                <div class="video-meta">
                    <strong>Tanggal:</strong> <?php echo htmlspecialchars($row['tanggal'] ?? '-'); ?>
                </div>
                <div class="video-deskripsi">
                    <?php echo nl2br(htmlspecialchars($row['deskripsi'] ?? '')); ?>
                </div>
                <?php if (!empty($row['id'])): ?>
                    <a href="detail_video.php?id=<?= urlencode($row['id']) ?>" class="detail-btn" target="_blank">Lihat Detail</a>
                <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <p style="margin-top:20px;">Tidak ada video yang ditemukan.</p>
        <?php endif; ?>
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
          <p>(+62 331) 486200 ext: 142<br>08979049176</p>
          <p>Jalan Teratai No. 22, Patrang. Kab. Jember<br>Jawa Timur, Indonesia 68117</p>
        </div>
      </div>
    </footer>
    <footer>
      <div class="footer-bottom">
        <p>© Copyright Humas Marketing Citra Husada.</p>
      </div>
    </footer>
  </div>

  <script>
    // Toggle menu user (jika tidak pakai script.js)
    const userIcon = document.querySelector('.user-icon');
    const userMenu = document.getElementById('userMenu');
    if (userIcon) {
      userIcon.addEventListener('click', function(e){
        e.stopPropagation();
        userMenu.style.display = (userMenu.style.display === 'block') ? 'none' : 'block';
      });
    }
    document.addEventListener('click', function(e){
      if (userMenu && !userIcon?.contains(e.target)) userMenu.style.display = 'none';
    });

    // Dropdown nav "Berita"
    function toggleDropdown(){
      const dd = document.querySelector('.navbar-bottom .dropdown .dropdown-content');
      if (!dd) return;
      dd.style.display = (dd.style.display === 'block') ? 'none' : 'block';
    }
  </script>
  <script src="script.js"></script>
</body>
</html>
