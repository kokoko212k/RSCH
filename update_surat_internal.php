<?php
session_start();
include 'config.php';

// Cek login
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
// Ambil ID dari URL
$id = $_GET['id'] ?? null;
if (!$id) {
    echo "ID tidak ditemukan.";
    exit();
}

// Ambil data lama
$stmt = $pdo->prepare("SELECT * FROM surat_internal WHERE id = ?");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data) {
    echo "Data tidak ditemukan.";
    exit();
}

// Proses update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal_surat = $_POST['tanggal_surat'] ?? '';
    $no_surat      = trim($_POST['no_surat'] ?? '');
    $perihal       = trim($_POST['perihal'] ?? '');
    $dari          = trim($_POST['dari'] ?? '');

    $target_dir = "surat_internal/files/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    // Path file yang akan disimpan di DB
    $file_path_to_save = $data['file_url']; // default: file lama tetap

    // Jika ada file baru
    if (isset($_FILES["file_url"]) && $_FILES["file_url"]["error"] === UPLOAD_ERR_OK) {
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

        $file_tmp  = $_FILES["file_url"]["tmp_name"];
        $file_type = $_FILES["file_url"]["type"];
        $file_size = $_FILES["file_url"]["size"];
        $file_name = time() . '_' . uniqid() . '_' . basename($_FILES["file_url"]["name"]);
        $file_path = $target_dir . $file_name;

        if (!in_array($file_type, $allowed_types) || $file_size > 5 * 1024 * 1024) {
            echo "File harus berupa PDF, Word, Excel, PPT, gambar maksimal 5MB.";
            exit();
        }

        if (!move_uploaded_file($file_tmp, $file_path)) {
            echo "Gagal mengunggah file.";
            exit();
        }

        // Update path file baru
        $file_path_to_save = $file_path;
    }

    // Update data di database
    $query = "UPDATE surat_internal 
              SET tanggal_surat=?, no_surat=?, perihal=?, dari=?, file_url=? 
              WHERE id=?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$tanggal_surat, $no_surat, $perihal, $dari, $file_path_to_save, $id]);

    // Redirect dengan pesan sukses
    $_SESSION['success_message'] = "Surat berhasil diupdate.";
    header("Location: surat_internal.php?success=2");
    exit();
}

$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
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


<div class="container">
  <h2>Update Surat Internal</h2>
  <form action="update_surat_internal.php?id=<?= $id ?>" method="POST" enctype="multipart/form-data">

    <div class="input-row">
      <label for="tanggal_surat">Tanggal Surat</label>
      <input type="date" name="tanggal_surat" value="<?= $data['tanggal_surat'] ?>">
    </div>

    <div class="input-row">
      <label for="no_surat">No. Surat</label>
      <input type="text" name="no_surat"  value="<?= $data['no_surat'] ?>">
    </div>

    <div class="input-row">
      <label for="perihal">Perihal</label>
      <input type="text" name="perihal"  value="<?= $data['perihal'] ?>">
    </div>  
    <div class="status-row">
    <label for="dari">Dari</label>
    <select name="dari" id="dari-select" >
        <option value="">---Pilih---</option>
        <option value="GUDANG FARMASI" <?= ($data['dari'] == 'GUDANG FARMASI') ? 'selected' : '' ?>>GUDANG FARMASI</option>
        <option value="GUDANG LOGISTIK" <?= ($data['dari'] == 'GUDANG LOGISTIK') ? 'selected' : '' ?>>GUDANG LOGISTIK</option>
        <option value="GUDANG FIX ASET" <?= ($data['dari'] == 'GUDANG FIX ASET') ? 'selected' : '' ?>>GUDANG FIX ASET</option>
        <option value="FARMASI RAWAT JALAN" <?= ($data['dari'] == 'FARMASI RAWAT JALAN') ? 'selected' : '' ?>>FARMASI RAWAT JALAN</option>
        <option value="FARMASI RAWAT INAP" <?= ($data['dari'] == 'FARMASI RAWAT INAP') ? 'selected' : '' ?>>FARMASI RAWAT INAP</option>
        <option value="POLI KLINIK RAWAT JALAN" <?= ($data['dari'] == 'POLI KLINIK RAWAT JALAN') ? 'selected' : '' ?>>POLI KLINIK RAWAT JALAN</option>
        <option value="INSTALASI GAWAT DARURAT" <?= ($data['dari'] == 'INSTALASI GAWAT DARURAT') ? 'selected' : '' ?>>INSTALASI GAWAT DARURAT</option>
        <option value="RADIOLOGI" <?= ($data['dari'] == 'RADIOLOGI') ? 'selected' : '' ?>>RADIOLOGI</option>
        <option value="LABORATORIUM" <?= ($data['dari'] == 'LABORATORIUM') ? 'selected' : '' ?>>LABORATORIUM</option>
        <option value="NS ROSALINA" <?= ($data['dari'] == 'NS ROSALINA') ? 'selected' : '' ?>>NS ROSALINA</option>
        <option value="NS TERATAI" <?= ($data['dari'] == 'NS TERATAI') ? 'selected' : '' ?>>NS TERATAI</option>
        <option value="NS ANTURIUM" <?= ($data['dari'] == 'NS ANTURIUM') ? 'selected' : '' ?>>NS ANTURIUM</option>
        <option value="NS ALAMANDA" <?= ($data['dari'] == 'NS ALAMANDA') ? 'selected' : '' ?>>NS ALAMANDA</option>
        <option value="NS BERSALIN" <?= ($data['dari'] == 'NS BERSALIN') ? 'selected' : '' ?>>NS BERSALIN</option>
        <option value="NS PERINATOLOGI" <?= ($data['dari'] == 'NS PERINATOLOGI') ? 'selected' : '' ?>>NS PERINATOLOGI</option>
        <option value="UMUM RT" <?= ($data['dari'] == 'UMUM RT') ? 'selected' : '' ?>>UMUM RT</option>
        <option value="ICU" <?= ($data['dari'] == 'ICU') ? 'selected' : '' ?>>ICU</option>
        <option value="OK" <?= ($data['dari'] == 'OK') ? 'selected' : '' ?>>OK</option>
        <option value="KEPERAWATAN" <?= ($data['dari'] == 'KEPERAWATAN') ? 'selected' : '' ?>>KEPERAWATAN</option>
        <option value="KEUANGAN" <?= ($data['dari'] == 'KEUANGAN') ? 'selected' : '' ?>>KEUANGAN</option>
        <option value="TPP" <?= ($data['dari'] == 'TPP') ? 'selected' : '' ?>>TPP</option>
        <option value="IT" <?= ($data['dari'] == 'IT') ? 'selected' : '' ?>>IT</option>
        <option value="GIZI" <?= ($data['dari'] == 'GIZI') ? 'selected' : '' ?>>GIZI</option>
        <option value="HEMODIALISA" <?= ($data['dari'] == 'HEMODIALISA') ? 'selected' : '' ?>>HEMODIALISA</option>
        <option value="LAUNDRY + KEBERSIHAN" <?= ($data['dari'] == 'LAUNDRY + KEBERSIHAN') ? 'selected' : '' ?>>LAUNDRY + KEBERSIHAN</option>
        <option value="KEPEGAWAIAN & DIKLAT" <?= ($data['dari'] == 'KEPEGAWAIAN & DIKLAT') ? 'selected' : '' ?>>KEPEGAWAIAN & DIKLAT</option>
        <option value="MARKETING" <?= ($data['dari'] == 'MARKETING') ? 'selected' : '' ?>>MARKETING</option>
        <option value="INFORMASI & KOMPLAIN" <?= ($data['dari'] == 'INFORMASI & KOMPLAIN') ? 'selected' : '' ?>>INFORMASI & KOMPLAIN</option>
        <option value="YANJANGMED" <?= ($data['dari'] == 'YANJANGMED') ? 'selected' : '' ?>>YANJANGMED</option>
        <option value="TIM PMKP" <?= ($data['dari'] == 'TIM PMKP') ? 'selected' : '' ?>>TIM PMKP</option>
        <option value="TIM PPI" <?= ($data['dari'] == 'TIM PPI') ? 'selected' : '' ?>>TIM PPI</option>
        <option value="TIM K3" <?= ($data['dari'] == 'TIM K3') ? 'selected' : '' ?>>TIM K3</option>
        <option value="DIREKSI" <?= ($data['dari'] == 'DIREKSI') ? 'selected' : '' ?>>DIREKSI</option>
        <option value="REKAM MEDIS" <?= ($data['dari'] == 'REKAM MEDIS') ? 'selected' : '' ?>>REKAM MEDIS</option>
        <option value="AKUNTANSI & PERPAJAKAN" <?= ($data['dari'] == 'AKUNTANSI & PERPAJAKAN') ? 'selected' : '' ?>>AKUNTANSI & PERPAJAKAN</option>
        <option value="SEKRETARIAT" <?= ($data['dari'] == 'SEKRETARIAT') ? 'selected' : '' ?>>SEKRETARIAT</option>
        <option value="CLEANING SERVICE (CS)" <?= ($data['dari'] == 'CLEANING SERVICE (CS)') ? 'selected' : '' ?>>CLEANING SERVICE (CS)</option>
        <option value="DRIVER & SECURITY" <?= ($data['dari'] == 'DRIVER & SECURITY') ? 'selected' : '' ?>>DRIVER & SECURITY</option>
        <option value="KASIR RAWAT INAP" <?= ($data['dari'] == 'KASIR RAWAT INAP') ? 'selected' : '' ?>>KASIR RAWAT INAP</option>
        <option value="KASIR RAWAT JALAN" <?= ($data['dari'] == 'KASIR RAWAT JALAN') ? 'selected' : '' ?>>KASIR RAWAT JALAN</option>
        <option value="TIM PENGENDALI BPJS (Casemix)" <?= ($data['dari'] == 'TIM PENGENDALI BPJS (Casemix)') ? 'selected' : '' ?>>TIM PENGENDALI BPJS (Casemix)</option>
        <option value="NS LOTUS" <?= ($data['dari'] == 'NS LOTUS') ? 'selected' : '' ?>>NS LOTUS</option>
        <option value="NS TULIP" <?= ($data['dari'] == 'NS TULIP') ? 'selected' : '' ?>>NS TULIP</option>
        <option value="KOMITE KEPERAWATAN" <?= ($data['dari'] == 'KOMITE KEPERAWATAN') ? 'selected' : '' ?>>KOMITE KEPERAWATAN</option>
        <option value="TIM PKRS" <?= ($data['dari'] == 'TIM PKRS') ? 'selected' : '' ?>>TIM PKRS</option>
    </select>
</div>
    <div class="input-row">
      <label for="file">File Surat Internal</label>
      <input type="file" name="file_url" accept="application/pdf">
    </div>

    <div class="button-row">
      <button type="submit" class="submit-btn">Update</button>
      <a href="surat_internal.php" class="cancel-btn">Batal</a>
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
</body>
</html>
