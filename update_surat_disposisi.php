<?php
session_start();

$host = "localhost";
$user = "root";
$pass = "";
$db = "rsch";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

include 'config.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'] ?? null;
$role = $user['status'] ?? null;
$can_access_eoffice = in_array($role, ['Sekretariat', 'Direktur', 'Super Admin']);

// Ambil ID dari URL
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "ID tidak ditemukan.";
    exit();
}

// Ambil data lama dari surat_disposisi
$stmt = mysqli_prepare($conn, "SELECT * FROM surat_disposisi WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($result);

if (!$data) {
    echo "Data tidak ditemukan.";
    exit();
}

// Proses input data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'];
    $no_surat = $_POST['no_surat'];
    $ditujukan_kepada = $_POST['ditujukan_kepada'];
    $instruksi = $_POST['instruksi'];
    $status_disposisi = $_POST['status_disposisi'];
    $file_path = $data['file_url'];

    if (isset($_FILES["file_url"]) && $_FILES["file_url"]["error"] === UPLOAD_ERR_OK) {
        // Jika ada file baru
        $file_name = basename($_FILES["file_url"]["name"]);
        $target_dir = "uploads/";
        $file_path = $target_dir . $file_name;

        $allowed_types = ['application/pdf'];
        if (in_array($_FILES['file_url']['type'], $allowed_types) && $_FILES['file_url']['size'] <= 5 * 1024 * 1024) {
            if (!move_uploaded_file($_FILES["file_url"]["tmp_name"], $file_path)) {
                echo "Gagal mengunggah file.";
                exit();
            }
        } else {
            echo "File harus PDF dan maksimal 5MB.";
            exit();
        }
    }

    // Update data di surat_disposisi
    $query = "UPDATE surat_disposisi SET tanggal=?, no_surat=?, ditujukan_kepada=?, instruksi=?, status_disposisi=?, file_url=? WHERE id=?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssssssi", $tanggal, $no_surat, $ditujukan_kepada, $instruksi, $status_disposisi, $file_path, $id);

    if (mysqli_stmt_execute($stmt)) {
        header('Location: surat_disposisi.php?success=2');
        exit();
    } else {
        echo "Gagal mengupdate disposisi: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Update Surat Keluar</title>
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
            <!-- <a href="surat_internal.php">Surat Internal</a>       -->
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
  <h2>Update Surat Disposisi</h2>
  <form action="update_surat_disposisi.php?id=<?= $id ?>" method="POST" enctype="multipart/form-data">

    <div class="input-row">
      <label for="tanggal">Tanggal</label>
      <input type="date" name="tanggal" value="<?= $data['tanggal'] ?>">
    </div>

    <div class="input-row">
      <label for="no_surat">No. Surat</label>
      <input type="text" name="no_surat" value="<?= $data['no_surat'] ?>">
    </div>

    <div class="status-row">
      <label for="ditujukan_kepada">Ditujukan Kepada</label>
      <select name="ditujukan_kepada" id="ditujukan_kepada-select">
        <option value="">---Pilih---</option>
        <option value="">---Pilih---</option>
        <option value="GUDANG FARMASI" <?= ($data['ditujukan_kepada'] == 'GUDANG FARMASI') ? 'selected' : '' ?>>GUDANG FARMASI</option>
        <option value="GUDANG LOGISTIK" <?= ($data['ditujukan_kepada'] == 'GUDANG LOGISTIK') ? 'selected' : '' ?>>GUDANG LOGISTIK</option>
        <option value="GUDANG FIX ASET" <?= ($data['ditujukan_kepada'] == 'GUDANG FIX ASET') ? 'selected' : '' ?>>GUDANG FIX ASET</option>
        <option value="FARMASI RAWAT JALAN" <?= ($data['ditujukan_kepada'] == 'FARMASI RAWAT JALAN') ? 'selected' : '' ?>>FARMASI RAWAT JALAN</option>
        <option value="FARMASI RAWAT INAP" <?= ($data['ditujukan_kepada'] == 'FARMASI RAWAT INAP') ? 'selected' : '' ?>>FARMASI RAWAT INAP</option>
        <option value="POLI KLINIK RAWAT JALAN" <?= ($data['ditujukan_kepada'] == 'POLI KLINIK RAWAT JALAN') ? 'selected' : '' ?>>POLI KLINIK RAWAT JALAN</option>
        <option value="INSTALASI GAWAT DARURAT" <?= ($data['ditujukan_kepada'] == 'INSTALASI GAWAT DARURAT') ? 'selected' : '' ?>>INSTALASI GAWAT DARURAT</option>
        <option value="RADIOLOGI" <?= ($data['ditujukan_kepada'] == 'RADIOLOGI') ? 'selected' : '' ?>>RADIOLOGI</option>
        <option value="LABORATORIUM" <?= ($data['ditujukan_kepada'] == 'LABORATORIUM') ? 'selected' : '' ?>>LABORATORIUM</option>
        <option value="NS ROSALINA" <?= ($data['ditujukan_kepada'] == 'NS ROSALINA') ? 'selected' : '' ?>>NS ROSALINA</option>
        <option value="NS TERATAI" <?= ($data['ditujukan_kepada'] == 'NS TERATAI') ? 'selected' : '' ?>>NS TERATAI</option>
        <option value="NS ANTURIUM" <?= ($data['ditujukan_kepada'] == 'NS ANTURIUM') ? 'selected' : '' ?>>NS ANTURIUM</option>
        <option value="NS ALAMANDA" <?= ($data['ditujukan_kepada'] == 'NS ALAMANDA') ? 'selected' : '' ?>>NS ALAMANDA</option>
        <option value="NS BERSALIN" <?= ($data['ditujukan_kepada'] == 'NS BERSALIN') ? 'selected' : '' ?>>NS BERSALIN</option>
        <option value="NS PERINATOLOGI" <?= ($data['ditujukan_kepada'] == 'NS PERINATOLOGI') ? 'selected' : '' ?>>NS PERINATOLOGI</option>
        <option value="UMUM RT" <?= ($data['ditujukan_kepada'] == 'UMUM RT') ? 'selected' : '' ?>>UMUM RT</option>
        <option value="ICU" <?= ($data['ditujukan_kepada'] == 'ICU') ? 'selected' : '' ?>>ICU</option>
        <option value="OK" <?= ($data['ditujukan_kepada'] == 'OK') ? 'selected' : '' ?>>OK</option>
        <option value="KEPERAWATAN" <?= ($data['ditujukan_kepada'] == 'KEPERAWATAN') ? 'selected' : '' ?>>KEPERAWATAN</option>
        <option value="KEUANGAN" <?= ($data['ditujukan_kepada'] == 'KEUANGAN') ? 'selected' : '' ?>>KEUANGAN</option>
        <option value="TPP" <?= ($data['ditujukan_kepada'] == 'TPP') ? 'selected' : '' ?>>TPP</option>
        <option value="IT" <?= ($data['ditujukan_kepada'] == 'IT') ? 'selected' : '' ?>>IT</option>
        <option value="GIZI" <?= ($data['ditujukan_kepada'] == 'GIZI') ? 'selected' : '' ?>>GIZI</option>
        <option value="HEMODIALISA" <?= ($data['ditujukan_kepada'] == 'HEMODIALISA') ? 'selected' : '' ?>>HEMODIALISA</option>
        <option value="LAUNDRY + KEBERSIHAN" <?= ($data['ditujukan_kepada'] == 'LAUNDRY + KEBERSIHAN') ? 'selected' : '' ?>>LAUNDRY + KEBERSIHAN</option>
        <option value="KEPEGAWAIAN & DIKLAT" <?= ($data['ditujukan_kepada'] == 'KEPEGAWAIAN & DIKLAT') ? 'selected' : '' ?>>KEPEGAWAIAN & DIKLAT</option>
        <option value="MARKETING" <?= ($data['ditujukan_kepada'] == 'MARKETING') ? 'selected' : '' ?>>MARKETING</option>
        <option value="INFORMASI & KOMPLAIN" <?= ($data['ditujukan_kepada'] == 'INFORMASI & KOMPLAIN') ? 'selected' : '' ?>>INFORMASI & KOMPLAIN</option>
        <option value="YANJANGMED" <?= ($data['ditujukan_kepada'] == 'YANJANGMED') ? 'selected' : '' ?>>YANJANGMED</option>
        <option value="TIM PMKP" <?= ($data['ditujukan_kepada'] == 'TIM PMKP') ? 'selected' : '' ?>>TIM PMKP</option>
        <option value="TIM PPI" <?= ($data['ditujukan_kepada'] == 'TIM PPI') ? 'selected' : '' ?>>TIM PPI</option>
        <option value="TIM K3" <?= ($data['ditujukan_kepada'] == 'TIM K3') ? 'selected' : '' ?>>TIM K3</option>
        <option value="DIREKSI" <?= ($data['ditujukan_kepada'] == 'DIREKSI') ? 'selected' : '' ?>>DIREKSI</option>
        <option value="REKAM MEDIS" <?= ($data['ditujukan_kepada'] == 'REKAM MEDIS') ? 'selected' : '' ?>>REKAM MEDIS</option>
        <option value="AKUNTANSI & PERPAJAKAN" <?= ($data['ditujukan_kepada'] == 'AKUNTANSI & PERPAJAKAN') ? 'selected' : '' ?>>AKUNTANSI & PERPAJAKAN</option>
        <option value="SEKRETARIAT" <?= ($data['ditujukan_kepada'] == 'SEKRETARIAT') ? 'selected' : '' ?>>SEKRETARIAT</option>
        <option value="CLEANING SERVICE (CS)" <?= ($data['ditujukan_kepada'] == 'CLEANING SERVICE (CS)') ? 'selected' : '' ?>>CLEANING SERVICE (CS)</option>
        <option value="DRIVER & SECURITY" <?= ($data['ditujukan_kepada'] == 'DRIVER & SECURITY') ? 'selected' : '' ?>>DRIVER & SECURITY</option>
        <option value="KASIR RAWAT INAP" <?= ($data['ditujukan_kepada'] == 'KASIR RAWAT INAP') ? 'selected' : '' ?>>KASIR RAWAT INAP</option>
        <option value="KASIR RAWAT JALAN" <?= ($data['ditujukan_kepada'] == 'KASIR RAWAT JALAN') ? 'selected' : '' ?>>KASIR RAWAT JALAN</option>
        <option value="TIM PENGENDALI BPJS (Casemix)" <?= ($data['ditujukan_kepada'] == 'TIM PENGENDALI BPJS (Casemix)') ? 'selected' : '' ?>>TIM PENGENDALI BPJS (Casemix)</option>
        <option value="NS LOTUS" <?= ($data['ditujukan_kepada'] == 'NS LOTUS') ? 'selected' : '' ?>>NS LOTUS</option>
        <option value="NS TULIP" <?= ($data['ditujukan_kepada'] == 'NS TULIP') ? 'selected' : '' ?>>NS TULIP</option>
        <option value="KOMITE KEPERAWATAN" <?= ($data['ditujukan_kepada'] == 'KOMITE KEPERAWATAN') ? 'selected' : '' ?>>KOMITE KEPERAWATAN</option>
        <option value="TIM PKRS" <?= ($data['ditujukan_kepada'] == 'TIM PKRS') ? 'selected' : '' ?>>TIM PKRS</option>
      </select>
    </div>

    <div class="status-row">
      <label for="instruksi">Instruksi</label>
      <select name="instruksi">
        <option value="">---Pilih---</option>
        <option value="Diterima" <?= $data['instruksi'] == 'Diterima' ? 'selected' : '' ?>>Diterima</option>
        <option value="Diperbaiki" <?= $data['instruksi'] == 'Diperbaiki' ? 'selected' : '' ?>>Diperbaiki</option>
        <option value="Ditolak" <?= $data['instruksi'] == 'Ditolak' ? 'selected' : '' ?>>Ditolak</option>
      </select>
    </div>

    <div class="status-row">
      <label for="status_disposisi">Status Disposisi</label>
      <select name="status_disposisi">
        <option value="">---Pilih---</option>
        <option value="Telah Diproses" <?= $data['status_disposisi'] == 'Telah Diproses' ? 'selected' : '' ?>>Telah Diproses</option>
        <option value="Belum Diproses" <?= $data['status_disposisi'] == 'Belum Diproses' ? 'selected' : '' ?>>Belum Diproses</option>
      </select>
    </div>

    <div class="input-row">
      <label for="file">File Surat (Kosongkan jika tidak diubah)</label>
      <input type="file" name="file_url" accept="application/pdf">
    </div>

    <div class="button-row">
      <button type="submit" class="submit-btn">Update</button>
      <a href="surat_disposisi.php" class="cancel-btn">Batal</a>
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



