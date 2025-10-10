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


// Hapus Data
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = mysqli_prepare($conn, "DELETE FROM surat_pengajuan WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    header("Location: surat_pengajuan.php");
    exit();
}

// Ambil data user dari session jika ada
$user = $_SESSION['user'] ?? null;
// Ambil role/status user (atau kosong kalau belum login)
$role = $user['status'] ?? null;
// Cek apakah user punya akses ke fitur E-Office
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
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

// Ambil data unik untuk dropdown
$noSurat = mysqli_query($conn, "SELECT DISTINCT no_surat FROM surat_pengajuan");
$noDari = mysqli_query($conn, "SELECT DISTINCT dari FROM surat_pengajuan");
$instruksi = mysqli_query($conn, "SELECT DISTINCT instruksi FROM surat_pengajuan");


$result = mysqli_query($conn, "SELECT * FROM surat_pengajuan ORDER BY id DESC");

// Proses input data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil data input
    $tanggal = $_POST['tanggal'];
    $no_surat = $_POST['no_surat'];
    $dari = $_POST['dari'];
    $instruksi = $_POST['instruksi'];    
    // Upload file
    $file_name = basename($_FILES["file_url"]["name"]);
    $target_dir = "uploads/";
    $file_path = $target_dir . $file_name;

    // Validasi file
    $allowed_types = ['application/pdf'];
    if (in_array($_FILES['file_url']['type'], $allowed_types) && $_FILES['file_url']['size'] <= 5 * 1024 * 1024) { // max 5 MB
        if (move_uploaded_file($_FILES["file_url"]["tmp_name"], $file_path)) {
            $stmt = mysqli_prepare($conn, "INSERT INTO surat_pengajuan (tanggal, no_surat, dari, instruksi, file_url) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $tanggal, $no_surat, $dari, $instruksi, $file_name);
            if (mysqli_stmt_execute($stmt)) {
                header('Location: surat_pengajuan.php');
                exit();
            } else {
                echo "Gagal menyimpan surat: " . mysqli_error($conn);
            }
        } else {
            echo "Gagal mengunggah file.";
        }
    } else {
        echo "File harus PDF dan maksimal 5MB.";
    }
}
?>


<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Surat Pengajuan</title>
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
    margin-bottom: 10px;
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
      padding: 5px 10px;
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
  display: block; 
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

/* === Export dropdown === */
.export-dropdown { position: relative; display: inline-block; }
.export-toggle { cursor: pointer; }
.export-menu {
  position: absolute; top: 100%; left: 0;
  display: none; min-width: 210px; padding: 6px;
  background:#fff; border:1px solid #ddd; border-radius:8px;
  box-shadow:0 6px 20px rgba(0,0,0,.08); z-index: 10;
}
.export-item {
  display:block; width:100%; text-align:left;
  padding:8px 10px; background:transparent; border:none; cursor:pointer;
}
.export-item:hover { background:#f2f6ff; }
.export-dropdown.open .export-menu { display:block; }

  </style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.5/xlsx.full.min.js"></script>
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
        <div class="main-title">Surat Pengajuan</div>
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
<h2 class="judul-surat-luar">Daftar Surat Pengajuan</h2>

<div class="kontainer-balok">
    <!-- Balok 1: Tombol Upload dan Export -->
    <div class="export-dropdown">
      <a href="buat_surat_pengajuan.php" class="btn-tambah">Upload</a>
      <button type="button" class="btn-tambah export-toggle">Export ▾</button>
      <div class="export-menu">
        <button type="button" class="export-item" onclick="exportTableToExcel(false)">Export All</button>
        <button type="button" class="export-item" onclick="exportTableToExcel(true)">Export 3 Bulan Terakhir</button>
      </div>
    </div>


    <!-- Balok 2: Search Bar -->
    <div class="balok-2">
        <div class="search-bar">
            <input type="text" placeholder="Cari..." id="searchInput" oninput="searchTable()" />
            <button onclick="searchTable()">Cari</button>
        </div>
    </div>

    <!-- Balok 3: Tabel Data Surat -->
    <div class="balok-3">
        <table id="tabelSuratPengajuan" border="1" cellpadding="10" cellspacing="0">
            <tr>
                <th>
                    Tanggal
                    <div class="form-group">
                        <input type="date" id="tanggal" name="tanggal" onchange="filterTable('tanggal', this.value); showResetButton();" />
                    </div>
                </th>
                <th>
                    No. Surat
                    <div class="no-export">
                        <select onchange="filterTable('nop', this.value); showResetButton();">
                            <option value="">Semua</option>
                            <?php while ($row = mysqli_fetch_assoc($noSurat)): ?>
                                <option value="<?= htmlspecialchars($row['no_surat']) ?>"><?= htmlspecialchars($row['no_surat']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </th>
                <th>
                    Dari
                    <div class="no-export">
                        <select onchange="filterTable('dari', this.value); showResetButton();">
                            <option value="">Semua</option>
                            <?php while ($row = mysqli_fetch_assoc($noDari)): ?>
                                <option value="<?= htmlspecialchars($row['dari']) ?>"><?= htmlspecialchars($row['dari']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </th>
                <th>
                    Instruksi
                    <div class="no-export">
                        <select onchange="filterTable('instruksi', this.value); showResetButton();">
                            <option value="">Semua</option>
                            <?php while ($row = mysqli_fetch_assoc($instruksi)): ?>
                                <option value="<?= htmlspecialchars($row['instruksi']) ?>"><?= htmlspecialchars($row['instruksi']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </th>
                <th>File</th>
                <th>Aksi</th>
            </tr>

            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                <tr class="data-row"
                    data-tanggal="<?= htmlspecialchars($row['tanggal']) ?>"
                    data-nop="<?= htmlspecialchars($row['no_surat']) ?>"
                    data-dari="<?= htmlspecialchars($row['dari']) ?>"
                    data-instruksi="<?= htmlspecialchars($row['instruksi']) ?>">
                    <td><?= htmlspecialchars($row['tanggal']) ?></td>
                    <td><?= htmlspecialchars($row['no_surat']) ?></td>
                    <td><?= htmlspecialchars($row['dari']) ?></td>
                    <td><?= htmlspecialchars($row['instruksi']) ?></td>
                    <td><a href="<?= $row['file_url'] ?>" target="_blank">
                      <?= basename($row['file_url']) ?></a></td>
                    <td>
                        <a href="update_surat_pengajuan.php?id=<?= $row['id'] ?>">✏️</a><br>
                        <a href="surat_pengajuan.php?delete=<?= $row['id'] ?>" onclick="return confirm('Hapus pengajuan ini?')">🗑️</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    </div>


    <!-- Tombol Reset -->
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
    const filterValue = (value || '').toLowerCase();
    rows.forEach(row => {
      const cellValue = (row.getAttribute('data-' + attribute) || '').toLowerCase();
      row.style.display = (!filterValue || cellValue.includes(filterValue)) ? "table-row" : "none";
    });
  }

(function(){
  const dd  = document.querySelector('.export-dropdown');
  const btn = dd?.querySelector('.export-toggle');
  if (btn) {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      dd.classList.toggle('open');
    });
    document.addEventListener('click', () => dd.classList.remove('open'));
  }
})();

function exportTableToExcel(last3Months) {
  if (typeof XLSX === 'undefined') { alert('Library XLSX belum ter-load.'); return; }

  const src = document.getElementById("tabelSuratPengajuan");
  if (!src) { alert("Tabel tidak ditemukan"); return; }

  // clone tabel agar aman dimodif
  const table = src.cloneNode(true);

  // buang elemen yang tidak perlu diexport
  table.querySelectorAll(".no-export, select, form, button").forEach(el => el.remove());

  const DATE_ATTR = 'tanggal';
  function parseFlexibleDate(str){
    if (!str) return null;
    str = String(str).trim();
    if (str.length >= 10) str = str.slice(0,10);
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      const [y,m,d] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;
    }
    if (/^\d{2}-\d{2}-\d{4}$/.test(str)) {
      const [d,m,y] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;
    }
    return null;
  }

  if (last3Months) {
    // rolling 90 hari terakhir dari hari ini
    const today = new Date();
    const start = new Date(today.getTime() - 90*24*60*60*1000);
    const end   = today;

    table.querySelectorAll("tr.data-row").forEach(tr => {
      const raw = tr.getAttribute("data-" + DATE_ATTR) || "";
      const dt  = parseFlexibleDate(raw);
      if (!dt || dt < start || dt > end) tr.remove();
    });
  }

  // buang kolom 'File' dan 'Aksi' (berdasarkan judul th)
  const header = table.querySelector("tr");
  const ths = Array.from(header.children);
  const removeIdx = ths
    .map((th, i) => [ (th.textContent || '').trim().split('\n')[0], i ])
    .filter(([t]) => t === "File" || t === "Aksi")
    .map(([, i]) => i);

  table.querySelectorAll("tr").forEach(tr => {
    Array.from(tr.children).forEach((td, i) => {
      if (removeIdx.includes(i)) td.remove();
    });
  });

  // buat workbook dari tabel
  const wb = XLSX.utils.table_to_book(table, { sheet: "Surat Pengajuan" });
  const ws = wb.Sheets["Surat Pengajuan"];

  // auto width kolom sederhana berdasarkan header
  const firstRow = XLSX.utils.sheet_to_json(ws, { header: 1 })[0] || [];
  ws['!cols'] = firstRow.map(h => ({ wch: Math.min(Math.max(String(h||'').length + 2, 12), 40) }));

  XLSX.writeFile(wb, `surat_pengajuan${last3Months ? '_3 bulan terakhir' : '_all'}.xlsx`);

  // tutup dropdown
  document.querySelector('.export-dropdown')?.classList.remove('open');
}

    function resetFilters() {
      // Reset semua dropdown
      const selects = document.querySelectorAll('select');
      selects.forEach(select => {
        select.selectedIndex = 0;
      });

      // Reset input tanggal
      const dateInput = document.getElementById('tanggal');
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
  const rows = document.querySelectorAll("#tabelSuratPengajuan .data-row");

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

function showResetButton() {
  const resetContainer = document.getElementById('reset-container');
  if (!resetContainer) return;

  const search = document.getElementById('searchInput');
  const selects = document.querySelectorAll('#tabelSuratPengajuan th select');
  const dates   = document.querySelectorAll('#tabelSuratPengajuan th input[type="date"]');

  const hasSearch = !!(search && search.value.trim().length > 0);
  const hasSelect = Array.from(selects).some(s => (s.value || '').trim() !== '');
  const hasDate   = Array.from(dates).some(i => (i.value || '').trim() !== '');

  resetContainer.style.display = (hasSearch || hasSelect || hasDate) ? 'block' : 'none';
}

// Hook semua kontrol agar memanggil showResetButton
document.addEventListener('DOMContentLoaded', () => {
  const search = document.getElementById('searchInput');
  const selects = document.querySelectorAll('#tabelSuratPengajuan th select');
  const dates   = document.querySelectorAll('#tabelSuratPengajuan th input[type="date"]');

  if (search) search.addEventListener('input', showResetButton);
  selects.forEach(s => s.addEventListener('change', showResetButton));
  dates.forEach(i => i.addEventListener('change', showResetButton));

  // panggil sekali saat load untuk set keadaan awal
  showResetButton();
});
  </script>  
</body>
</html>