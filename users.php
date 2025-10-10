<?php
session_start();
include 'config.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Hapus Data
if (isset($_GET['delete'])) {
    $nik = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE nik = ?");
    if ($stmt->execute([$nik])) {
        header("Location: users.php");
        exit();
    } else {
        echo "Gagal menghapus user.";
    }
}

// Cek Login
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'];
$role = $user['status'] ?? null;


// Cek apakah user sudah login
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'];
$role = isset($user['status']) ? $user['status'] : null;

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
// Cek apakah user punya akses (hanya Super Admin)
$can_access_eoffice = in_array($role, ['Super Admin']);
if (!$can_access_eoffice) {
    echo "Akses ditolak!";
    exit();
}

// Query Dropdown Filter (pastikan tidak NULL atau kosong)
$nikResult = $pdo->query("SELECT DISTINCT nik FROM users WHERE nik IS NOT NULL AND nik != ''")->fetchAll(PDO::FETCH_ASSOC);
$namaResult = $pdo->query("SELECT DISTINCT nama FROM users WHERE nama IS NOT NULL AND nama != ''")->fetchAll(PDO::FETCH_ASSOC);
$statusResult = $pdo->query("SELECT DISTINCT status FROM users WHERE status IS NOT NULL AND status != ''")->fetchAll(PDO::FETCH_ASSOC);
$tempatLahirResult = $pdo->query("SELECT DISTINCT tempat_lahir FROM users WHERE tempat_lahir IS NOT NULL AND tempat_lahir != ''")->fetchAll(PDO::FETCH_ASSOC);
$jenisKelaminResult = $pdo->query("SELECT DISTINCT jenis_kelamin FROM users WHERE jenis_kelamin IS NOT NULL AND jenis_kelamin != ''")->fetchAll(PDO::FETCH_ASSOC);
$alamatKtpResult = $pdo->query("SELECT DISTINCT alamat_ktp FROM users WHERE alamat_ktp IS NOT NULL AND alamat_ktp != ''")->fetchAll(PDO::FETCH_ASSOC);

// Query Data Utama
$result = $pdo->query("SELECT * FROM users ORDER BY nik DESC")->fetchAll(PDO::FETCH_ASSOC);
?>



<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Daftar Akun</title>
  <link rel="stylesheet" href="style.css" />
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'> 
  <style>
  @media print {
  .no-export {
    display: none;
  }
  }
   .userMenu {
    transition: all 0.3s ease;
    }
  .kontainer-balok {
      background-color: #ffffff;
      padding: 30px;
      margin: 40px auto;
      border-radius: 10px;
      max-width: 1200px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
  }

  .balok-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
  }

  .balok-header h2 {
      font-size: 24px;
      color: #333;
  }

  .btn-export {
      padding: 8px 16px;
      background-color: #17a2b8;
      color: white;
      border-radius: 5px;
      text-decoration: none;
  }

  .btn-export:hover {
      background-color: #138496;
  }

  .balok-1 {
      margin: 20px 0;
      text-align: left;
  }

  .btn-tambah {
    padding: 11px 20px;
    background-color: #28a745;
    color: white;
    text-decoration: none;
    border-radius: 5px;
  }

  .btn-tambah:hover {
    background-color: #218838;
  }

  button.btn-tambah {
  padding:11px 20px;
  background-color: #28a745;
  color: rgb(0, 0, 0);
  font-size: 15px;
  text-decoration: none;
  border-radius: 5px;
  border-style: none;
  }

  .balok-2 {
  margin-bottom: 20px;
  display: flex;
  justify-content: flex-start;
  }

  .search-bar {
      display: flex;
      gap: 10px;
  }

  .search-bar input {
      padding: 10px;
      font-size: 14px;
      border-radius: 5px;
      border: 1px solid #ddd;
  }

  .search-bar button {
      padding: 10px;
      background-color: #007bff;
      color: white;
      border: none;
      border-radius: 5px;
  }

  .search-bar button:hover {
      background-color: #0056b3;
  }

  /* Tabel Data Surat */
  .balok-3 {
      margin-top: 20px;
      overflow-x: auto;
      width: 100%;
  }

  table {
      width: 100%;
      max-width: 100%;
      table-layout: auto;
      border-collapse: collapse;
      background-color: #fff;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      border-radius: 8px;
      overflow: hidden;
  }

  table select {
  width: 18px;
  font-size: 14px;
  padding: 0.5px;
  }

  th, td {
      font-size: 14px;
      padding: 2px 8px;
      text-align: left;
      border-bottom: 1px solid #ddd;
  }

  th {
      background-color: #007bff;
      color: #fff;
      font-size: 16px;
  }

  th select {
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  } 

  td {
      background-color: #f9f9f9;
      word-break: keep-all;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 200px;  
      }



  td a {
      color: #007bff;
      text-decoration: none;
      font-weight: bold;
  }

  .alamat-ktp {
  max-width: 250px;      
  white-space: normal;    
  word-wrap: break-word;  
  word-break: break-word; 
  vertical-align: center;   
}

  td a:hover {
      text-decoration: underline;
  }

  .judul-surat-luar {
      text-align: center;
      margin: 0 auto;
      padding-left: 30px; 
      font-size: 24px;
      color: #333;
      margin-top: 20px;
      margin-bottom: 10px;
  }

  .form-group input[type="date"] {
  display: block;
  width: 20px;
  }

  .no-export {
  display: block; /* Tetap tampil di halaman */
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
.reset-bar {
    display: flex;
    justify-content: flex-end;
    margin-top: 4px; 
}

.btn-reset {
    background-color: #dc3545;
    color: white;
    padding: 8px 16px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    margin-left: 650px;
}
  </style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
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



  <!-- Judul di luar kontainer -->
  <h2 class="judul-surat-luar">Daftar Akun</h2>

  <div class="kontainer-balok">
      <!-- Balok 1: Tombol tambah -->
    <div class="balok-1">
        <a href="buat_akun.php" class="btn-tambah">Add</a>
        <button class="btn-tambah" onclick="exportToExcel()">Export</button>
    </div>

    <!-- Balok 2: Search Bar -->
    <div class="balok-2">
        <div class="search-bar">
        <input type="text" placeholder="Cari surat..." id="searchInput" oninput="searchTable()" />
        <button>Cari</button>
        </div>
        <div class="reset-bar" id="reset-container" style="display: none;">
            <button onclick="resetFilters()" class="btn-reset">Reset</button>
        </div>
    </div>



    <!-- Balok 3: Tabel -->
    <div class="balok-3">
    <table border="1" cellpadding="10" cellspacing="0" id="tabelUsers">
        <tr>
            <th>NIK
                <select onchange="sortTableByNIK(this.value); showResetButton();">
                    <option value="">-- Urutkan --</option>
                    <option value="asc">NIK (ASC)</option>
                    <option value="desc">NIK (DESC)</option>
                </select>
            </th>            
            <!-- <th>NIK
                <select onchange="filterTable('nik', this.value); showResetButton();">
                    <option value=""></option>
                    <?php foreach ($nikResult as $row) : ?>
                        <option value="<?= htmlspecialchars($row['nik']) ?>"><?= htmlspecialchars($row['nik']) ?></option>
                    <?php endforeach; ?>
                </select>
            </th> -->
            <th>Nama
                <!-- <select onchange="filterTable('nama', this.value); showResetButton();">
                    <option value=""></option>
                    <?php foreach ($namaResult as $row) : ?>
                        <option value="<?= htmlspecialchars($row['nama']) ?>"><?= htmlspecialchars($row['nama']) ?></option>
                    <?php endforeach; ?>
                </select> -->
            </th>
            <th>Status
                <select onchange="filterTable('status', this.value); showResetButton();">
                    <option value=""></option>
                    <?php foreach ($statusResult as $row) : ?>
                        <option value="<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></option>
                    <?php endforeach; ?>
                </select>
            </th>
            <th>Tempat Lahir
                <select onchange="filterTable('tempatlahir', this.value); showResetButton();">
                    <option value=""></option>
                    <?php foreach ($tempatLahirResult as $row) : ?>
                        <option value="<?= htmlspecialchars($row['tempat_lahir']) ?>"><?= htmlspecialchars($row['tempat_lahir']) ?></option>
                    <?php endforeach; ?>
                </select>
            </th>
            <th>Tanggal Lahir
                <input type="date" id="tanggalLahir" name="tanggalLahir" onchange="filterTable('tanggallahir', this.value); showResetButton();" />
            </th>
            <th>Jenis Kelamin
                <select onchange="filterTable('jeniskelamin', this.value); showResetButton();">
                    <option value=""></option>
                    <?php foreach ($jenisKelaminResult as $row) : ?>
                        <option value="<?= htmlspecialchars($row['jenis_kelamin']) ?>"><?= htmlspecialchars($row['jenis_kelamin']) ?></option>
                    <?php endforeach; ?>
                </select>
            </th>
            <th>Alamat KTP
                <!-- <select onchange="filterTable('alamatktp', this.value); showResetButton();">
                    <option value=""></option>
                    <?php foreach ($alamatKtpResult as $row) : ?>
                        <option value="<?= htmlspecialchars($row['alamat_ktp']) ?>"><?= htmlspecialchars($row['alamat_ktp']) ?></option>
                    <?php endforeach; ?>
                </select> -->
            </th>
            <th>Password</th>
            <th>Aksi</th>
        </tr>

        <?php foreach ($result as $row) : ?>
             <tr class="data-row"
              data-nik="<?= htmlspecialchars($row['nik'] ?? '') ?>"
              data-nama="<?= htmlspecialchars($row['nama'] ?? '') ?>"
              data-status="<?= htmlspecialchars($row['status'] ?? '') ?>"
              data-tempatlahir="<?= htmlspecialchars($row['tempat_lahir'] ?? '') ?>"
              data-tanggallahir="<?= htmlspecialchars($row['tanggal_lahir'] ?? '') ?>"
              data-jeniskelamin="<?= htmlspecialchars($row['jenis_kelamin'] ?? '') ?>"
              data-alamatktp="<?= htmlspecialchars($row['alamat_ktp'] ?? '') ?>"  >
                <td><?= htmlspecialchars($row['nik']) ?></td>
                <td class="alamat-ktp"><?= htmlspecialchars($row['nama']) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= htmlspecialchars($row['tempat_lahir']) ?></td>
                <td><?= date('d-m-Y', strtotime($row['tanggal_lahir'])) ?></td>
                <td><?= htmlspecialchars($row['jenis_kelamin']) ?></td>
                <td class="alamat-ktp"><?= htmlspecialchars($row['alamat_ktp'] ?? '') ?></td>
                <td>••••••</td>
                <td>
                    <a href="update_akun.php?nik=<?= $row['nik'] ?>&from=users.php">✏️</a>
                    <!-- <a href="profil.php?nik=<?= $row['nik'] ?>">📖</a> -->
                    <a href="users.php?delete=<?= $row['nik'] ?>" onclick="return confirm('Hapus user ini?')">🗑️</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
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

  </script>
  <script src="script.js"></script>
  <script>
  function filterTable(attribute, value) {
  const rows = document.querySelectorAll('.data-row');
  rows.forEach(row => {
    if (value === "" || row.dataset[attribute.toLowerCase()] === value) {
      row.style.display = "table-row";
    } else {
      row.style.display = "none";
    }
  });
  }
  
function exportToExcel() {
    var table = document.getElementById("tabelUsers").cloneNode(true);

    // Hapus elemen no-export
    var filters = table.querySelectorAll(".no-export");
    filters.forEach(filter => filter.remove());

    var headers = table.querySelectorAll("th");
    var removeIndexes = [];

    // Cari index kolom yang mau dihapus
    headers.forEach((cell, index) => {
        var text = cell.childNodes[0].textContent.trim(); // ⬅️ Ambil hanya teks label
        if (text === "Aksi" || text === "File") {
            removeIndexes.push(index);
        }
    });

    // Ambil header (ambil teks node pertama saja)
    var headerCells = table.querySelectorAll('tr')[0].querySelectorAll('th');
    var headersArray = [];

    headerCells.forEach((cell, index) => {
        if (!removeIndexes.includes(index)) {
            headersArray.push(cell.childNodes[0].textContent.trim()); // ⬅️ Ini kunci
        }
    });

    // Ambil data isi
    var bodyRows = table.querySelectorAll('tr');
    var dataArray = [];

    // Mulai dari baris kedua (index 1), baris pertama adalah header
    for (var i = 1; i < bodyRows.length; i++) {
        var row = bodyRows[i];
        var rowData = [];
        var cells = row.querySelectorAll('td');
        cells.forEach((cell, index) => {
            if (!removeIndexes.includes(index)) {
                rowData.push(cell.textContent.trim());
            }
        });
        if (rowData.length > 0) { // Hindari baris kosong
            dataArray.push(rowData);
        }
    }

    // Gabungkan header dan data
    var exportData = [headersArray, ...dataArray];

    // Buat file Excel
    var ws = XLSX.utils.aoa_to_sheet(exportData);
    ws['!cols'] = [
        { wch: 20 }, // nik
        { wch: 30 }, // nama
        { wch: 15 }, // status
        { wch: 25 }, // tempat lahir
        { wch: 20 }, // tanggal lahir
        { wch: 15 }, // jenis kelamin
        { wch: 50 }  // alamat ktp
    ];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Users");

    XLSX.writeFile(wb, "users.xlsx");
    alert("Data berhasil diekspor!");
}


    function resetFilters() {
      // Reset semua dropdown
      const selects = document.querySelectorAll('select');
      selects.forEach(select => {
        select.selectedIndex = 0;
      });

      // Reset input tanggal
      const dateInput = document.getElementById('nik');
      if (dateInput) dateInput.value = '';

      // Tampilkan semua baris
      const rows = document.querySelectorAll('.data-row');
      rows.forEach(row => {
        row.style.display = "table-row";
      });

      // Sembunyikan tombol reset
      const resetContainer = document.getElementById("reset-container");
      if (resetContainer) {
        resetContainer.style.display = "none";
      }
    }
  function searchTable() {
  const input = document.getElementById("searchInput").value.toLowerCase();
  const rows = document.querySelectorAll("#tabelUsers .data-row");

  rows.forEach(row => {
    const cells = row.querySelectorAll("td");
    let found = false;

    cells.forEach(cell => {
      if (cell.textContent.toLowerCase().includes(input)) {
        found = true;
      }
    });

    row.style.display = found ? "table-row" : "none";
  });

  // Tampilkan tombol reset jika ada input
  const resetContainer = document.getElementById("reset-container");
  if (input.length > 0) {
    resetContainer.style.display = "block";
  }
}
function sortTableByNIK(order) {
    let table = document.getElementById("tabelUsers");
    let rows = Array.from(table.querySelectorAll(".data-row"));
    
    rows.sort((a, b) => {
        let nikA = a.dataset.nik;
        let nikB = b.dataset.nik;

        // Bandingkan NIK sebagai angka, jika hanya angka, atau sebagai string jika mengandung karakter
        if (!isNaN(nikA) && !isNaN(nikB)) {
            nikA = parseFloat(nikA);
            nikB = parseFloat(nikB);
        }

        if (order === "asc") {
            return nikA > nikB ? 1 : -1;
        } else if (order === "desc") {
            return nikA < nikB ? 1 : -1;
        } else {
            return 0;
        }
    });

    // Hapus semua baris lama
    rows.forEach(row => table.tBodies[0].appendChild(row));
}

function showResetButton() {
    document.getElementById("reset-container").style.display = "block";
}
  </script>  
</body>
</html>