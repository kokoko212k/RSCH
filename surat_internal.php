<?php
session_start();
include 'config.php';

// Cek login
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'];
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
// Hapus surat
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM surat_internal WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $_SESSION['success_message'] = "Surat berhasil dihapus.";
    header("Location: surat_internal.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_surat'])) {
    $tanggal_surat = $_POST['tanggal_surat'] ?? '';
    $no_surat = trim($_POST['no_surat'] ?? '');
    $perihal = trim($_POST['perihal'] ?? '');
    $dari = trim($_POST['dari'] ?? '');

    if (isset($_FILES['file_url']) && $_FILES['file_url']['error'] === UPLOAD_ERR_OK) {
        $file_name = time() . '_' . uniqid() . '_' . basename($_FILES['file_url']['name']);
        $target_dir = 'uploads/';
        $file_path = $target_dir . $file_name;

        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        $allowed_types = ['application/pdf'];
        $file_type = mime_content_type($_FILES['file_url']['tmp_name']);

        if (in_array($file_type, $allowed_types) && $_FILES['file_url']['size'] <= 5 * 1024 * 1024) {
            if (move_uploaded_file($_FILES['file_url']['tmp_name'], $file_path)) {
                $stmt = $pdo->prepare("INSERT INTO surat_internal 
                    (tanggal_surat, no_surat, perihal, dari, file_url) 
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $tanggal_surat,
                    $no_surat,
                    $perihal,
                    $dari,
                    $file_path
                ]);


                $_SESSION['success_message'] = "Surat berhasil disimpan.";
                header("Location: surat_internal.php");
                exit();
            } else {
                echo "Gagal mengunggah file.";
            }
        } else {
            echo "File harus berupa PDF dan maksimal 5MB.";
        }
    } else {
        echo "File tidak ditemukan atau gagal diunggah.";
    }
}



$stmt = $pdo->query("SELECT * FROM surat_internal ORDER BY id DESC");
$suratList = $stmt->fetchAll(PDO::FETCH_ASSOC);
$result = $suratList;

// Ambil data unik untuk dropdown
function getDropdownData($pdo, $column) {
    $stmt = $pdo->prepare("SELECT DISTINCT $column FROM surat_internal");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$noSuratData = getDropdownData($pdo, 'no_surat');
$perihalData = getDropdownData($pdo, 'perihal');
$dariData = getDropdownData($pdo, 'dari');

$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Surat Internal</title>
  <link rel="stylesheet" href="style.css" />
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'> <!-- untuk ikon footer -->
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
      padding: 5px 3px;
      text-align: left;
      border-bottom: 1px solid #ddd;
  }

  th {
      background-color: #007bff;
      color: #fff;
      font-size: 16px;
  }

  td {
      background-color: #f9f9f9;
      word-break: keep-all;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 250px;  
      }

  td a {
      color: #007bff;
      text-decoration: none;
      font-weight: bold;
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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



  <!-- Judul di luar kontainer -->
  <h2 class="judul-surat-luar">Daftar Surat Internal</h2>
  <div class="kontainer-balok">
    <div class="balok-1">
        <a href="buat_surat_internal.php" class="btn-tambah">Upload</a>
        <button type="button" class="btn-tambah" onclick="exportTableToExcel()">Export</button>
    </div>

    <!-- Balok 2: Search Bar -->
    <div class="balok-2">
        <div class="search-bar">
        <input type="text" placeholder="Cari surat..." id="searchInput" oninput="searchTable()" />
        <button>Cari</button>
        </div>
    </div>

    <!-- Balok 3: Tabel Data Surat -->
    <div class="balok-3">
    <table id="tabelSuratInternal" border="1" cellpadding="10" cellspacing="0">
        <tr>
            <th>
                Tanggal Surat
                <div class="form-group">
                    <input type="date" id="tanggalSurat" name="tanggalSurat" required onchange="filterTable('tanggalsurat', this.value); showResetButton();" />
                </div>
            </th>        
            <th>
                No. Surat
                <div class="no-export">
                    <select onchange="filterTable('nosurat', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($noSuratData)): ?>
                            <?php foreach ($noSuratData as $rowNoSurat): ?>
                                <option value="<?= $rowNoSurat['no_surat'] ?>"><?= $rowNoSurat['no_surat'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>
            <th>
                Perihal
                <div class="no-export">
                    <select onchange="filterTable('perihal', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($perihalData)): ?>
                            <?php foreach ($perihalData as $rowPerihal): ?>
                                <option value="<?= $rowPerihal['perihal'] ?>"><?= $rowPerihal['perihal'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>
            <th>
                Dari
                <div class="no-export">
                    <select onchange="filterTable('dari', this.value); showResetButton();">
                        <option value=""></option>
                        <?php if (!empty($dariData)): ?>
                            <?php foreach ($dariData as $rowDari): ?>
                                <option value="<?= $rowDari['dari'] ?>"><?= $rowDari['dari'] ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option disabled>Tidak ada data.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </th>
            <th>File</th>
            <th>Aksi</th>
        </tr>

        <?php foreach ($result as $row): ?>
        <tr class="data-row"
            data-tanggalsurat="<?= $row['tanggal_surat'] ?>"
            data-nosurat="<?= $row['no_surat'] ?>"
            data-perihal="<?= $row['perihal'] ?>"
            data-dari="<?= $row['dari'] ?>"
            data-file="<?= $row['file_url'] ?>">

            <td><?= date('d-m-Y', strtotime($row['tanggal_surat'])) ?></td>
            <td><?= $row['no_surat'] ?></td>
            <td><?= $row['perihal'] ?></td>
            <td><?= $row['dari'] ?></td>
            <td><a href="<?= $row['file_url'] ?>" target="_blank"><?= basename($row['file_url']) ?></a></td>
            <td>
                <a href="update_surat_internal.php?id=<?= $row['id'] ?>">✏️</a><br>
                <a href="surat_internal.php?delete=<?= $row['id'] ?>" onclick="return confirm('Hapus surat ini?')">🗑️</a>
            </td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <div id="reset-container" style="display: none; text-align: right; margin-top: 15px;">
        <button onclick="resetFilters()" style="background-color:#dc3545; color:white; padding: 8px 16px; border:none; border-radius:5px;">Reset</button>
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
      <p>© Copyright IT Support Citra Husada.</p>
    </div>
  </footer>
  <script src="script.js"></script>
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
function exportTableToExcel() {
    var table = document.getElementById("tabelSuratInternal").cloneNode(true);

    // Hapus elemen no-export
    var filters = table.querySelectorAll(".no-export");
    filters.forEach(filter => filter.remove());

    var headers = table.querySelectorAll("th");
    var removeIndexes = [];

    // Cari index kolom yang mau dihapus
    headers.forEach((cell, index) => {
        var text = cell.childNodes[0].textContent.trim();
        if (text === "Aksi" || text === "File") {
            removeIndexes.push(index);
        }
    });

    // Ambil header (ambil teks node pertama saja)
    var headerCells = table.querySelectorAll('tr')[0].querySelectorAll('th');
    var headersArray = [];

    headerCells.forEach((cell, index) => {
        if (!removeIndexes.includes(index)) {
            headersArray.push(cell.childNodes[0].textContent.trim());
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
        { wch: 15 }, // Tanggal Surat
        { wch: 15 }, // No. Surat
        { wch: 30 }, // Perihal
        { wch: 30 }, // Dari
=    ];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Users");

    XLSX.writeFile(wb, "surat_internal.xlsx");
    alert("Data berhasil diekspor!");
}



    function resetFilters() {
      // Reset semua dropdown
      const selects = document.querySelectorAll('select');
      selects.forEach(select => {
        select.selectedIndex = 0;
      });

      // Reset input tanggal
      const dateInputs = document.querySelectorAll('input[type="date"]');
      dateInputs.forEach(input => input.value = '');


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
  const rows = document.querySelectorAll("#tabelSuratInternal .data-row");

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

  </script>  
</body>
</html>




