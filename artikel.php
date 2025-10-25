<?php
session_start();
include 'config.php';

$host = "localhost";
$user = "root";
$password = "";
$db = "rsch";

$koneksi = mysqli_connect($host, $user, $password, $db);

if (!$koneksi) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

// ==== Pencarian ====
$search = '';
if (isset($_GET['search'])) {
    $search = mysqli_real_escape_string($koneksi, $_GET['search']);
    $query  = "SELECT * FROM artikel WHERE judul LIKE '%$search%' OR tanggal LIKE '%$search%' ORDER BY tanggal DESC";
} else {
    $query = "SELECT * FROM artikel ORDER BY tanggal DESC";
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
$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Artikel</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <style>
    /* ===== Komponen kecil dropdown user (opsional inline) ===== */
    .user-dropdown { position: relative; display: inline-block; }
    .user-icon { font-size: 30px; cursor: pointer; color: white; }
    .user-menu { display: none; position: absolute; right: 0; background-color: white; min-width: 120px; box-shadow: 0 4px 6px rgba(0,0,0,.1); z-index: 999; border-radius: 5px; }
    .user-menu a { display: block; padding: 10px 15px; color: #333; text-decoration: none; }
    .user-menu a:hover { background-color: #f0f0f0; }

    /* ===== Halaman Artikel ===== */
    .artikel-section { padding: 20px; max-width: 1200px; margin: auto; }
    .btn-tambah { background-color: hsl(211,77%,54%); padding: 5px 8px; font-size: 15px; border-radius: 5px; text-decoration: none; font-weight: bold; display: inline-block; margin-right: 10px; color: #fff; }
    .artikel-search { display: flex; width: 100%; justify-content: right; margin-bottom: 20px; }
    .artikel-search input[type="text"] { width: 300px; padding: 10px; border: 1px solid #ccc; border-radius: 5px 0 0 5px; }
    .artikel-search button { padding: 10px 15px; color: white; border: none; background-color: #4CAF50; border-radius: 0 5px 5px 0; cursor: pointer; }

    .artikel-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
    .artikel-card { border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,.05); background-color: white; display: flex; flex-direction: column;}
    .artikel-image { width: 100%; height: 400px; object-fit: cover; }
    .artikel-info { padding: 15px; display: flex; flex-direction: column; gap: 8px; flex-grow: 1; }
    .artikel-info h3 { margin: 0; font-size: 18px; }
    .artikel-meta { font-size: 13px; color: #666; }
    .artikel-ringkasan { font-size: 14px; line-height: 1.5; color: #333; }
    .detail-btn { margin-top: auto; align-self: center; display: inline-block; padding: 8px 12px; background-color: #007BFF; color: white; text-decoration: none; border-radius: 5px; text-align: center; }
    .detail-btn:hover { background-color: #0056b3; }

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
  </style>
</head>
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
      </div>
    </nav>

    <section class="artikel-section">
      <h2>Daftar Artikel</h2>

      <!-- Search Bar -->
      <div class="artikel-search">
        <?php if ($can_edit): ?>
          <a href="buat_artikel.php" class="btn-tambah">+ Artikel</a>
        <?php endif; ?>
        <form method="GET" action="artikel.php">
          <input type="text" name="search" placeholder="Cari judul/tanggal ..." value="<?php echo htmlspecialchars($search); ?>" class="btn-cari"/>
          <button type="submit">Cari</button>
        </form>
      </div>

      <!-- Hasil Pencarian -->
      <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <div class="artikel-grid">
          <?php while ($row = mysqli_fetch_assoc($result)) : ?>
            <div class="artikel-card">
              <?php if (!empty($row['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($row['image_url']); ?>" alt="Gambar Artikel" class="artikel-image">
              <?php else: ?>
                <img src="Properti/no-image.jpg" alt="Tidak ada gambar" class="artikel-image">
              <?php endif; ?>

              <div class="artikel-info">
                <h3><?php echo htmlspecialchars($row['judul']); ?></h3>
                <div class="artikel-meta">
                  <strong>Tanggal:</strong> <?php echo htmlspecialchars($row['tanggal'] ?? '-'); ?>
                </div>
                <div class="artikel-ringkasan">
                  <?php echo nl2br(htmlspecialchars($row['ringkasan'] ?? '')); ?>
                </div>
                    <?php if (!empty($row['id'])): ?>
                    <a href="detail_artikel.php?id=<?= urlencode($row['id']) ?>" class="detail-btn" target="_blank">Lihat Detail</a>
                    <?php endif; ?>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <p style="margin-top:20px;">Tidak ada artikel yang ditemukan.</p>
      <?php endif; ?>
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
          <p>(+62 331) 486200 ext: 142<br>08979049176</p>
          <p>Jalan Teratai No. 22, Patrang. Kab. Jember<br>Jawa Timur, Indonesia 68117</p>
        </div>
      </div>
    </footer>
    <footer>
      <div class="footer-bottom">
        <p>© Copyright IT Support Citra Husada.</p>
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
