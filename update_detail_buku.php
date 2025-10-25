<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$role = $_SESSION['user']['status'];
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
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

// Ambil ID buku dari URL
if (!isset($_GET['id_buku'])) {
    echo "ID buku tidak ditemukan.";
    exit();
}

$id_buku = $_GET['id_buku'];

// Ambil data buku
$stmt = $pdo->prepare("SELECT * FROM koleksi WHERE id_buku = :id_buku");
$stmt->execute([':id_buku' => $id_buku]);
$buku = $stmt->fetch();

if (!$buku) {
    echo "Data buku tidak ditemukan.";
    exit();
}

// Proses update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $judul = $_POST['judul'] ?? '';
    $penulis = $_POST['penulis'] ?? '';
    $tahun = $_POST['tahun'] ?? '';
    $deskripsi = $_POST['deskripsi'] ?? '';

    $upload_dir = 'data_buku/files/';
    $image_url = $buku['images/']; 
    $file_url = $buku['file_url'];

    // Jika upload gambar baru
    if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['gambar']['tmp_name'];
        $file_name = basename($_FILES['gambar']['name']);
        $target_file = $upload_dir . time() . '_' . $file_name;

        if (move_uploaded_file($file_tmp, $target_file)) {
            $image_url = $target_file;
        } else {
            echo "<script>alert('Gagal mengunggah gambar.'); window.history.back();</script>";
            exit;
        }
    }

    // Jika upload file buku baru
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
    }

    // Validasi format tahun
    if (!preg_match('/^\d{4}$/', $tahun)) {
        echo "<script>alert('Format tahun tidak valid. Gunakan format YYYY.'); window.history.back();</script>";
        exit;
    }

    $update = $pdo->prepare("UPDATE koleksi SET judul = :judul, penulis = :penulis, tahun = :tahun, deskripsi = :deskripsi, image_url = :image_url, file_url = :file_url WHERE id_buku = :id_buku");

    try {
        $update->execute([
            ':judul' => $judul,
            ':penulis' => $penulis,
            ':tahun' => $tahun,
            ':deskripsi' => $deskripsi,
            ':image_url' => $image_url,
            ':file_url' => $file_url,
            ':id_buku' => $id_buku
        ]);

        echo "<script>alert('Buku berhasil diupdate!'); window.location.href = 'koleksi.php';</script>";
        exit();
    } catch (Exception $e) {
        echo "Terjadi kesalahan: " . $e->getMessage();
    }
}

$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tambah Buku</title>
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


<section class="container">
    <h3>Edit Buku</h3>
    <form action="update_detail_buku.php?id_buku=<?= $buku['id_buku'] ?>" method="POST" enctype="multipart/form-data">
        <div class="input-row">
            <label for="judul">Judul</label>
            <input type="text" id="judul" name="judul" required value="<?= htmlspecialchars($buku['judul']) ?>" />
        </div>
        <div class="input-row">
            <label for="penulis">Penulis</label>
            <input type="text" id="penulis" name="penulis" required value="<?= htmlspecialchars($buku['penulis']) ?>" />
        </div>
        <div class="input-row">
            <label for="tahun">Tahun</label>
            <input type="number" id="tahun" name="tahun" required value="<?= htmlspecialchars($buku['tahun']) ?>" />
        </div>
        <div class="input-row">
            <label for="deskripsi">Deskripsi</label>
            <textarea id="deskripsi" name="deskripsi" required><?= htmlspecialchars($buku['deskripsi']) ?></textarea>
        </div>
        <div class="input-row">
            <label for="gambar">Gambar Thumbnail (Kosongkan jika tidak ingin ganti)</label>
            <input type="file" id="gambar" name="gambar" accept="image/*" />
            <p>Gambar saat ini: <a href="<?= $buku['image_url'] ?>" target="_blank">Lihat Gambar</a></p>
        </div>
        <div class="input-row">
            <label for="file">File Buku (Kosongkan jika tidak ingin ganti)</label>
            <input type="file" id="file" name="file" accept="application/pdf" />
        </div>
        <div class="form-actions">
            <button type="submit" class="button-row">Update</button>
            <a href="koleksi.php" class="cancel-btn">Batal</a>
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
      <p>© Copyright IT Support Citra Husada.</p>
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
