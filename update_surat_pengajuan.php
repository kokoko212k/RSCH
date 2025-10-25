<?php
session_start();
include 'config.php'; // menyediakan $pdo (PDO)

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user        = $_SESSION['user'];
$role        = $user['status'] ?? null;
$loginUserId = (int)($user['id'] ?? 0);
$nik         = $user['nik'] ?? null;
$unit        = $user['unit'] ?? null;

/* ================== HITUNG BADGE NOTIF (opsional) ================== */
$jumlahNotif = 0;
if ($loginUserId > 0) {
  $stmtCnt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifikasi_targets t
    LEFT JOIN detail_notifikasi d
      ON d.notifikasi_id = t.notifikasi_id
     AND d.user_id       = t.user_id
    WHERE t.user_id = ?
      AND d.read_at IS NULL
  ");
  $stmtCnt->execute([$loginUserId]);
  $jumlahNotif = (int)$stmtCnt->fetchColumn();
}

/* ================== TENTUKAN SCOPE (pakai named placeholder!) ================== */
$scopeSql  = '1=0';
$scopeBind = [];
switch ($role) {
  case 'Super Admin':
    $scopeSql = '1=1';
    break;
  case 'Sekretariat':
    $scopeSql              = 'pengajuan_unit = :scope_unit';
    $scopeBind[':scope_unit'] = $unit;
    break;
  default: // Direktur/Admin/Member
    $scopeSql            = 'pengajuan_nik = :scope_nik';
    $scopeBind[':scope_nik'] = $nik;
    break;
}

/* ================== AMBIL ID ================== */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { die('ID tidak ditemukan.'); }

/* ================== AMBIL DATA LAMA DENGAN SCOPE (anti-IDOR) ================== */
$sqlGet  = "SELECT * FROM surat_pengajuan WHERE id = :id AND ($scopeSql) LIMIT 1";
$params  = array_merge([':id' => $id], $scopeBind);
$stmtGet = $pdo->prepare($sqlGet);
$stmtGet->execute($params);
$data = $stmtGet->fetch(PDO::FETCH_ASSOC);
if (!$data) { die('Data tidak ditemukan / akses ditolak.'); }

/* simpan owner untuk notifikasi */
$ownerNik  = $data['pengajuan_nik']  ?? null;
$ownerUnit = $data['pengajuan_unit'] ?? null;

/* ================== PROSES UPDATE ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal  = trim($_POST['tanggal']  ?? '');
    $no_surat = trim($_POST['no_surat'] ?? '');
    $dari     = trim($_POST['dari']     ?? '');

    if ($tanggal === '' || $no_surat === '' || $dari === '') {
      die("Tanggal, No. Surat, dan Dari wajib diisi.");
    }

    // default: pakai file lama
    $file_path_to_save = $data['file_url'];

    // jika upload file baru
    if (isset($_FILES['file_url']) && $_FILES['file_url']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "pengajuan/files/"; // konsisten dgn create
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

        $allowed_types = ['application/pdf'];
        $file_name = basename($_FILES['file_url']['name']);
        $file_tmp  = $_FILES['file_url']['tmp_name'];
        $file_type = $_FILES['file_url']['type'];
        $file_size = $_FILES['file_url']['size'];
        $file_path = $target_dir . $file_name;

        if (!in_array($file_type, $allowed_types, true)) {
            die("File harus PDF.");
        }
        if ($file_size > 5 * 1024 * 1024) {
            die("Maksimal ukuran file 5MB.");
        }
        if (!move_uploaded_file($file_tmp, $file_path)) {
            die("Gagal mengunggah file.");
        }
        $file_path_to_save = $file_path;
    }

    // UPDATE dengan scope (named placeholder juga)
    $sqlUpd = "
      UPDATE surat_pengajuan
         SET tanggal  = :tanggal,
             no_surat = :no_surat,
             dari     = :dari,
             file_url = :file_url
       WHERE id       = :id
         AND ($scopeSql)
       LIMIT 1
    ";

    $updParams = array_merge([
      ':tanggal'  => $tanggal,
      ':no_surat' => $no_surat,
      ':dari'     => $dari,
      ':file_url' => $file_path_to_save,
      ':id'       => $id,
    ], $scopeBind);

    $pdo->beginTransaction();
    $stmtUpd = $pdo->prepare($sqlUpd);
    $ok      = $stmtUpd->execute($updParams);
    $affected= $stmtUpd->rowCount(); // rows yang benar-benar tersentuh

    if ($ok) {
        // kirim notifikasi UPDATE (kalau fungsinya ada)
        if (function_exists('buat_notif_dari_surat_pengajuan')) {
          buat_notif_dari_surat_pengajuan($pdo, $id, 'update', $ownerNik, $ownerUnit);
        }
        $pdo->commit();
        header('Location: surat_pengajuan.php?success=2');
        exit();
    } else {
        $pdo->rollBack();
        die('Gagal mengupdate surat.');
    }
}

/* ================== (opsional) render form edit di bawah ini ================== */
// $data sudah siap untuk prefilling form
$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Update Surat Pengajuan</title>
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
    <h2>Update Surat Pengajuan</h2>
    <form action="update_surat_pengajuan.php?id=<?= $id ?>" method="POST" enctype="multipart/form-data">

      <div class="input-row">
        <label for="tanggal">Tanggal</label>
        <input type="date" name="tanggal"  value="<?= $data['tanggal'] ?>">
      </div>

      <div class="input-row">
        <label for="no_surat">No. Surat</label>
        <input type="text" name="no_surat"  value="<?= $data['no_surat'] ?>">
      </div>

      <div class="input-row">
        <label for="dari">Dari</label>
        <input type="text" name="dari"  value="<?= $data['dari'] ?>">
      </div>

      <div class="input-row">
        <label for="file">File Surat Pengajuan (Kosongkan jika tidak diubah)</label>
        <input type="file" name="file_url" accept="application/pdf">
      </div>

      <div class="button-row">
        <button type="submit" class="submit-btn">Update</button>
        <a href="surat_pengajuan.php" class="cancel-btn">Batal</a>
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
