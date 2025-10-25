<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'] ?? null;
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
$can_access_promkes = in_array($role, ['Member', 'Admin', 'Sekretariat', 'Direktur', 'Super Admin']);
$can_access_layanan_panduan_pedoman_sop = in_array($role, ['Member', 'Admin', 'Sekretariat', 'Direktur', 'Super Admin']);

// Hapus Data
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM layanan_pedoman WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: layanan_pedoman.php");
    exit();
}

// Proses input data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'];
    $judul = $_POST['judul'];
    $deskripsi = $_POST['deskripsi'];

    $file_name = basename($_FILES["file_url"]["name"]);
    $target_dir = "pedoman/files/";
    $file_path = $target_dir . $file_name;

    $allowed_types = ['application/pdf'];
    if (in_array($_FILES['file_url']['type'], $allowed_types) && $_FILES['file_url']['size'] <= 5 * 1024 * 1024) {
        if (move_uploaded_file($_FILES["file_url"]["tmp_name"], $file_path)) {
            $stmt = $pdo->prepare("INSERT INTO layanan_pedoman (tanggal, judul, deskripsi, file_name) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$tanggal, $judul, $deskripsi, $file_name])) {
                header('Location: layanan_pedoman.php');
                exit();
            } else {
                echo "Gagal menyimpan data.";
            }
        } else {
            echo "Gagal mengunggah file.";
        }
    } else {
        echo "File harus PDF dan maksimal 5MB.";
    }
}

// Ambil Data
$sql = "SELECT * FROM layanan_pedoman";
$stmt = $pdo->query($sql);
$data = $stmt->fetchAll();
$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
?>



<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panduan - Pedoman - SOP</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <style>
.koleksi-section {
  padding: 60px 20px;
  background-color: #ecf0f1;
}

.koleksi-section h2 {
  text-align: center;
  margin-bottom: 30px;
  font-size: 28px;
  color: #2c3e50;
}

.koleksi-top-boxes {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.koleksi-top-box {
  flex: 0 1 2900px;
  max-width: 290px;
  background-color: white;
  padding: 20px;
  text-align: center;
  border-radius: 8px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  cursor: pointer;
  transition: transform 0.2s ease;
}

.koleksi-top-box:hover {
  transform: translateY(-5px);
}

.koleksi-top-box h3 {
  color: #2980b9;
  font-size: 20px;
  margin: 0;
  text-align: center; 
}

.koleksi-search {
  display: flex;
  justify-content: center;
  margin-bottom: 30px;
}

.koleksi-search input {
  padding: 10px;
  width: 750px;
  border: 1px solid #ccc;
}

.koleksi-search button {
  padding: 10px 36px;
  background-color: #2980b9;
  color: white;
  border: none;
  cursor: pointer;
}

.koleksi-search #book-icon {
  font-size: 30px; 
  margin-left: 15px; 
  cursor: pointer;
  margin-right: 10px;
  color: #2980b9; 
}

/* === List Panduan === */
.koleksi-container {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 900px;
  margin: auto;
  background-color: white;
  border-radius: 10px;
  padding: 30px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.koleksi-item {
  background: white;
  border-radius: 8px;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.15);
  padding: 15px;
  margin-bottom: 20px;
  cursor:pointer;
}

.koleksi-item a {
  display: block;
  text-decoration: none;
  color: inherit;
}
.koleksi-item a:hover {
  text-decoration: underline;
}

.koleksi-info {
  padding: 15px;
}

.koleksi-info ul {
  list-style: none;
  padding-left: 0;
  margin: 0;
}

.koleksi-info ul li {
  text-align: left;
  margin-bottom: 4px;
}

.koleksi-item h3 {
  margin-bottom: 10px;
  color: #2980b9;
}

/* Navigasi jika butuh */
.koleksi-nav {
  text-align: center;
  margin-top: 20px;
  margin-bottom: 40px; 
}

.nav-btn {
  padding: 8px 16px;
  margin: 0 10px;
  background-color: #2c3e50;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}
.nav-btn:disabled {
  background-color: #999;
}

.dropdown-container {
    position: relative;
    display: inline-block;
    margin-left: 10px;
}

#dropdown-toggle {
    font-size: 36px;
    cursor: pointer;
}

.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    background-color: white;
    border: 1px solid #ccc;
    border-radius: 5px;
    min-width: 120px;
    z-index: 1000;
}

.dropdown-menu a {
    display: block;
    padding: 10px;
    text-decoration: none;
    color: black;
}

.dropdown-menu a:hover {
    background-color: #f0f0f0;
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
    </div>
  </nav>

<section class="koleksi-section">
    <h2>Daftar Pedoman</h2>
    <div class="koleksi-search">
        <!-- Dropdown Aksi -->
        <div class="dropdown-container">
            <a href="buat_pedoman.php">
            <i class="bx bx-plus" id="dropdown-toggle"></i></a>
        </div>

        <!-- Search -->
        <input type="text" placeholder="Cari..." id="search-input" />
        <button id="search-btn">Cari</button>
    </div>

    <div class="koleksi-top-boxes">
        <a href="layanan_sop.php" class="koleksi-top-box">
            <h3>SOP</h3>
        </a>
        <a href="layanan_pedoman.php" class="koleksi-top-box">
            <h3>Pedoman</h3>
        </a>
        <a href="layanan_panduan.php" class="koleksi-top-box">
            <h3>Panduan</h3>
        </a>
    </div>

    <div id="pedoman-container" class="koleksi-container">
        <?php foreach ($data as $item): ?>
        <a href="detail_pedoman.php?id=<?php echo $item['id']; ?>" class="koleksi-item">
            <h3><?php echo $item['judul']; ?></h3>
            <p><?php echo $item['deskripsi']; ?></p>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- Footer -->
<footer>
  <div class="footer-container">
    <!-- Tautan Lainnya -->
    <div class="footer-section">
      <h3>Tautan Lainnya</h3>
      <ul>
        <li><a href="faq.html" class="footer-link">FAQ</a></li>
        <li><a href="panduan.html" class="footer-link">Panduan/Tutorial</a></li>
        <li><a href="tentang kami.html" class="footer-link">Tentang Kami</a></li>
      </ul>
    </div>

    <!-- Media Sosial -->
    <div class="footer-section">
      <h3>Media Sosial</h3>
      <div>
        <a href="https://www.youtube.com/channel/UCWrutgBiaPK0vCk_pYxwGhw"><i class="bx bxl-youtube sosmed-icon"></i></a>
        <a href="https://www.facebook.com/rscitrahusadajember/"><i class="bx bxl-facebook sosmed-icon"></i></a>
        <a href="https://www.tiktok.com/@rscitrahusadajember?_t=ZS-8ssxXvGOz9G&_r=1"><i class="bx bxl-tiktok sosmed-icon"></i></a>
        <a href="https://www.instagram.com/rscitrahusadajember/"><i class="bx bxl-instagram sosmed-icon"></i></a>
        <a href="https://rscitrahusada.com/"><i class='bx bxs-home sosmed-icon'></i></a>
      </div>
    </div>

    <!-- Kontak Kami -->
    <div class="footer-section">
      <h3>Kontak Kami</h3>
      <p>(+62 331) 486200 ext: 142</p>
      <p>08979049176</p>
      <p>Jalan Teratai No. 22, Patrang. Kab. Jember<br>Jawa Timur, Indonesia 68117</p>
    </div>
  </div>
</footer>

<footer>
  <div class="footer-bottom">
      <p>© Copyright IT Support Citra Husada.</p>
  </div>
</footer>

  <script src="script.js"></script>
<script>
    const searchInput = document.getElementById("search-input");
    const searchBtn = document.getElementById("search-btn");

    searchBtn.addEventListener("click", () => {
        const query = searchInput.value.toLowerCase();
        const items = document.querySelectorAll(".koleksi-item");

        let found = false;

        items.forEach(item => {
            const title = item.querySelector("h3").textContent.toLowerCase();
            const desc = item.querySelector("p").textContent.toLowerCase();

            if (title.includes(query) || desc.includes(query)) {
                item.style.display = "block";
                found = true;
            } else {
                item.style.display = "none";
            }
        });

        if (!found) {
            document.getElementById("pedoman-container").innerHTML = "<p style='text-align:center;'>Pedoman tidak ditemukan.</p>";
        }
    });

    document.getElementById('dropdown-toggle').addEventListener('click', function() {
    const menu = document.getElementById('dropdown-menu');
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    });

    // Optional: klik di luar dropdown untuk menutup
    document.addEventListener('click', function(event) {
        const toggle = document.getElementById('dropdown-toggle');
        const menu = document.getElementById('dropdown-menu');
        if (!toggle.contains(event.target) && !menu.contains(event.target)) {
            menu.style.display = 'none';
        }
    });

    document.getElementById('dropdown-toggle').addEventListener('click', function() {
        const menu = document.getElementById('dropdown-menu');
        menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    });

    // Optional: klik di luar dropdown untuk menutup
    document.addEventListener('click', function(event) {
        const toggle = document.getElementById('dropdown-toggle');
        const menu = document.getElementById('dropdown-menu');
        if (!toggle.contains(event.target) && !menu.contains(event.target)) {
            menu.style.display = 'none';
        }
    });    
    </script>
</body>
</html>