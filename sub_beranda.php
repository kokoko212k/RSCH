<?php
session_start();

include 'config.php';
// Ambil data user dari session jika ada
$user = $_SESSION['user'] ?? null;

// Ambil role/status user (atau kosong kalau belum login)
$role = $user['status'] ?? null;

// Role yang bisa akses E-Office dan fitur khusus
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
$can_access_special = in_array($role, ['Admin', 'Sekretariat', 'Direktur', 'Super Admin', 'Member']);
$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Portal Home</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
</head>
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

    <section class="layanan-section">
      <h2>PORTAL ROOM</h2>
      <div class="layanan-grid">
        <a href="koleksi.php" class="layanan-card blue">
          <i class="icon">🔍</i>
          <h3>Koleksi</h3>
          <p>Cari buku, jurnal, dan bahan pustaka lainnya dengan cepat dan mudah.</p>
          <span>Learn More →</span>
        </a>
        <a href="bacaan.php" class="layanan-card orange">
          <i class="icon">📚</i>
          <h3>Daftar Bacaan</h3>
          <p>Lihat daftar bacaan yang telah anda pilih.</p>
          <span>Learn More →</span>
        </a>

        <?php if ($can_access_special): ?>
          <a href="repositori.php" class="layanan-card orange">
            <i class="icon">💻</i>
            <h3>Repositori</h3>
            <p>Akses publikasi dan karya ilmiah.</p>
            <span>Learn More →</span>
          </a>
          <a href="layanan_promkes.php" class="layanan-card orange">
            <i class="icon">🩺</i>
            <h3>Promkes</h3>
            <p>Ketahui informasi mengenai kesehatan dari Kementerian Kesehatan Republik Indonesia.</p>
            <span>Learn More →</span>
          </a>
          <a href="layanan_panduan pedoman sop.php" class="layanan-card orange">
            <i class="icon">📋</i>
            <h3>Pedoman, Panduan, dan SOP</h3>
            <p>Pelajari prosedur standar operasional untuk memastikan kelancaran dan konsistensi dalam setiap proses.</p>
            <span>Learn More →</span>
          </a>
        <?php endif; ?>

        <!-- <a href="masukan.php" class="layanan-card orange">
          <i class="icon">💬</i>
          <h3>Masukan</h3>
          <p>Berikan komentar/saran dalam meningkatkan layanan sistem.</p>
          <span>Learn More →</span>
        </a> -->
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
            <a href="https://www.youtube.com/channel/UCWrutgBiaPK0vCk_pYxwGhw"><i class="bx bxl-youtube sosmed-icon"></i></a>
            <a href="https://www.facebook.com/rscitrahusadajember/"><i class="bx bxl-facebook sosmed-icon"></i></a>
            <a href="https://www.tiktok.com/@rscitrahusadajember?_t=ZS-8ssxXvGOz9G&_r=1"><i class="bx bxl-tiktok sosmed-icon"></i></a>
            <a href="https://www.instagram.com/rscitrahusadajember/"><i class="bx bxl-instagram sosmed-icon"></i></a>
            <a href="https://rscitrahusada.com/" ><i class='bx bxs-home sosmed-icon'></i></a>
          </ul>
        <div class="footer-section">
        <div class="map-container">
          <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1690.947685920273!2d113.6812076459426!3d-8.169000958621156!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2dd694127910c383%3A0x42956e9612f6b07a!2sCitra%20Husada%20Hospital!5e0!3m2!1sen!2sid!4v1750478359122!5m2!1sen!2sid" width="300" height="200" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>      </div>
        </div>
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

  <script src="script.js"></script>
  <script>
    function toggleUserDropdown() {
      document.getElementById('userMenu').classList.toggle('show');
    }
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
