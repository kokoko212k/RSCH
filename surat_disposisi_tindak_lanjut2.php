<?php
session_start();
include 'config.php';

// Cek login
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_disposisi'])) {
    $id  = (int)($_POST['id_tindaklanjut'] ?? 0);
    $val = trim((string)($_POST['disposisi_kepada'] ?? ''));

    // dukung "lain-lain"
    if ($val === 'lain') {
        $val = trim((string)($_POST['disposisi_kepada_lain'] ?? ''));
    }

    if ($id <= 0 || $val === '') {
        $_SESSION['flash'] = 'Param tidak lengkap';
        header('Location: surat_disposisi_tindak_lanjut.php'); exit;
    }

    // update tabel tindak lanjut
    $pdo->prepare("
        UPDATE surat_disposisi_tindak_lanjut 
        SET disposisi_kepada = ? 
        WHERE id_tindaklanjut = ?
    ")->execute([$val, $id]);

    $r = $pdo->prepare("SELECT no_surat, file_url FROM surat_disposisi_tindak_lanjut WHERE id_tindaklanjut=?");
    $r->execute([$id]);
    if ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $pdo->prepare("UPDATE surat_disposisi_tindak_lanjut SET disposisi_kepada=? WHERE no_surat=?")
            ->execute([$val, $row['no_surat']]);

        // buat notifikasi
        $pdo->prepare("INSERT INTO surat_notif (tanggal,no_surat,file_url,waktu) VALUES (?,?,?,NOW())")
            ->execute([date('Y-m-d'), $row['no_surat'], $row['file_url']]);
    }

    $_SESSION['flash'] = 'Disposisi diperbarui';
    header('Location: surat_disposisi_tindak_lanjut.php'); exit;
}


$user  = $_SESSION['user'];
$role  = $user['status'] ?? null;
$can_access_eoffice = in_array($role, ['Super Admin']);
$pengirim = $user['nama'] ?? '';


// ================== SYNC DATA DARI SURAT_DISPOSISI ==================
$stmtDisposisi = $pdo->prepare("
    SELECT id, tanggal, no_surat, file_url, instruksi 
    FROM surat_disposisi 
    WHERE LOWER(instruksi) LIKE '%diteruskan%'
");
$stmtDisposisi->execute();
$dataDisposisi = $stmtDisposisi->fetchAll(PDO::FETCH_ASSOC);

foreach ($dataDisposisi as $row) {
    $cek = $pdo->prepare("SELECT COUNT(*) FROM surat_disposisi_tindak_lanjut WHERE no_surat = ?");
    $cek->execute([$row['no_surat']]);
    if ($cek->fetchColumn() == 0) {
        $insert = $pdo->prepare("
            INSERT INTO surat_disposisi_tindak_lanjut (tanggal, no_surat, file_url)
            VALUES (?, ?, ?)
        ");
        $insert->execute([$row['tanggal'], $row['no_surat'], $row['file_url']]);
    }
}

$stmt = $pdo->query("SELECT * FROM surat_disposisi_tindak_lanjut ORDER BY tanggal DESC");
$semuaData = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ================== HAPUS PESAN ==================
if (isset($_POST['hapus_pesan_id']) && is_numeric($_POST['hapus_pesan_id'])) {
    $pesan_id = (int) $_POST['hapus_pesan_id'];
    $id = $_POST['id'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM pesan WHERE id = ? AND pengirim = ?");
    $stmt->execute([$pesan_id, $pengirim]);

    if ($stmt->rowCount() > 0) {
        $del = $pdo->prepare("DELETE FROM pesan WHERE id = ?");
        $del->execute([$pesan_id]);
        header("Location: surat_disposisi_tindak_lanjut.php?id=$id&status=deleted");
        exit;
    } else {
        header("Location: surat_disposisi_tindak_lanjut.php?id=$id&status=error");
        exit;
    }
}
$penerima = $_POST['penerima'] ?? null;
$pesan_input = $_POST['pesan'] ?? null;
$no_surat = $_POST['no_surat'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pesan_input && $pengirim && $penerima && $no_surat) {
    $stmt = $pdo->prepare("INSERT INTO pesan (no_surat, pengirim, penerima, pesan) VALUES (?, ?, ?, ?)");
    $stmt->execute([$no_surat, $pengirim, $penerima, $pesan_input]);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Ruang Baca Virtual</title> 
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<style>
  @media print {
  .no-export {
    display: none;
  }
  }
    .kontainer-balok {
        background-color: #ffffff;
        padding: 30px;
        margin: 40px auto;
        border-radius: 10px;
        max-width: 1200px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }

    /* Judul di luar kontainer */
    .judul-surat-luar {
        text-align: center;
        padding-left: 30px;
        font-size: 24px;
        color: #333;
        margin-top: 20px;
        margin-bottom: 10px;
    }

    /* Header: judul dan tombol export */
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

    /* Tombol Tambah */
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

    /* Search Bar */
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

    /* Tabel */
    .balok-3 {
        margin-top: 20px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background-color: #fff;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        border-radius: 8px;
        overflow: hidden;
    }

    table select {
    width: 220px; 
    font-size: 14px;
    padding: 4px 6px;
    }


    th, td {
        padding: 10px 15px;
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
    }

    td a {
        color: #007bff;
        text-decoration: none;
        font-weight: bold;
    }

    td a:hover {
        text-decoration: underline;
    }

  .form-group input[type="date"] {
  display: block;
  width: 20px;
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

.dropdown-instruksi {
    width: 19px;
    padding: 5px;
}

.chat-container {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 15px;
}

.chat-bubble {
    max-width: 60%;
    padding: 10px 15px;
    border-radius: 15px;
    position: relative;
    word-wrap: break-word;
    margin-bottom: 10px;
}

.chat-bubble.left {
    background-color: #f1f0f0;
    align-self: flex-start;
    border-top-left-radius: 0;
}

.chat-bubble.right {
    background-color: #cce5ff;
    align-self: flex-end;
    border-top-right-radius: 0;
}

.chat-time {
    font-size: 0.75em;
    color: gray;
    margin-top: 5px;
    text-align: right;
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
        <li><a href="masukan.php" class="fitur-nav">Masukan</a></li>
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
            <!-- <a href="surat_internal.php">Surat Internal</a>         -->
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

<!-- Judul di luar kontainer -->
<h2 class="judul-surat-luar">Disposisi Tindak Lanjut</h2>

<!-- Buka kontainer utama -->
<div class="kontainer-balok">

    <!-- Balok 1 -->
    <div class="balok-1">
        <button type="button" class="btn-tambah" onclick="exportTableToExcel()">Export</button>
    </div>

    <!-- Balok 2: Search Bar -->
    <div class="balok-2">
        <div class="search-bar">
            <input type="text" placeholder="Cari surat..." id="searchInput" oninput="searchTable()" />
            <button>Cari</button>
        </div>
    </div>

<!-- TABEL -->
<div class="table-container">
    <table id="tabelSuratDisposisiTindakLanjut" border="1" cellpadding="10" cellspacing="0">
        <thead>
            <tr>
                <th>No</th>
                <th>Tanggal</th>
                <th>No Surat</th>
                <th>File</th>
                <th>Chat</th>
                <th>Disposisi Kepada</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($semuaData)): ?>
            <?php $no = 1; foreach ($semuaData as $row): ?>
                <tr class="data-row" data-id="<?= $row['id_tindaklanjut'] ?>" data-no-surat="<?= $row['no_surat'] ?>">
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['tanggal'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['no_surat'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($row['file_url'])): ?>
                            <a href="<?= htmlspecialchars($row['file_url']) ?>" target="_blank">Lihat File</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($row['disposisi_kepada'])): ?>
                        <button type="button"
                                class="btn-chat"
                                data-no-surat="<?= htmlspecialchars($row['no_surat'], ENT_QUOTES) ?>"
                                onclick="toggleChatBoxFromBtn(this)">💬</button>
                      <?php else: ?>
                        <button type="button"
                                class="btn-chat"
                                data-no-surat="<?= htmlspecialchars($row['no_surat'], ENT_QUOTES) ?>"
                                <?= empty($row['disposisi_kepada']) ? 'disabled title="Isi disposisi dulu"' : 'onclick="toggleChatBoxFromBtn(this)"' ?>>
                            💬
                        </button>
                      <?php endif; ?>
                    </td>
                    <td>
                    <div style="margin-bottom: 5px;">
                      <?= htmlspecialchars($row['disposisi_kepada'] ?? '-') ?>
                    </div>
                      <form method="POST" action="surat_disposisi_tindak_lanjut.php">
                        <input type="hidden" name="id_tindaklanjut" value="<?= $row['id_tindaklanjut'] ?>">
                        <input type="hidden" name="update_disposisi" value="1">
                        <select name="disposisi_kepada" onchange="this.form.requestSubmit()">
                          <option value="">---Pilih---</option>
                          <?php
                          $opsi_disposisi = [
                            "GUDANG FARMASI", "GUDANG LOGISTIK", "GUDANG FIX ASET", "FARMASI RAWAT JALAN", "FARMASI RAWAT INAP",
                            "POLI KLINIK RAWAT JALAN", "INSTALASI GAWAT DARURAT", "RADIOLOGI", "LABORATORIUM", "NS ROSALINA",
                            "NS TERATAI", "NS ANTURIUM", "NS ALAMANDA", "NS BERSALIN", "NS PERINATOLOGI", "UMUM RT", "ICU", "OK",
                            "KEPERAWATAN", "KEUANGAN", "TPP", "IT", "GIZI", "HEMODIALISA", "LAUNDRY + KEBERSIHAN", "KEPEGAWAIAN & DIKLAT",
                            "MARKETING", "INFORMASI & KOMPLAIN", "YANJANGMED", "TIM PMKP", "TIM PPI", "TIM K3", "DIREKSI",
                            "REKAM MEDIS", "AKUNTANSI & PERPAJAKAN", "SEKRETARIAT", "CLEANING SERVICE (CS)", "DRIVER & SECURITY",
                            "KASIR RAWAT INAP", "KASIR RAWAT JALAN", "TIM PENGENDALI BPJS (Casemix)", "NS LOTUS", "NS TULIP",
                            "KOMITE KEPERAWATAN", "TIM PKRS"
                          ];
                          foreach ($opsi_disposisi as $opsi) {
                              $selected = $row['disposisi_kepada'] == $opsi ? 'selected' : '';
                              echo "<option value=\"$opsi\" $selected>$opsi</option>";
                          }
                          ?>
                        </select>
                      </form>
                    </td>
                    <td>
                        <a href="update_surat_disposisi_tindak_lanjut.php?id=<?= urlencode($row['id_tindaklanjut'] ?? '') ?>">✏️</a><br>
                        <a href="surat_disposisi_tindak_lanjut.php?delete=<?= urlencode($row['id_tindaklanjut'] ?? '') ?>" onclick="return confirm('Hapus surat ini?')">🗑️</a>
                    </td>
                </tr>
                <tr id="chatbox-<?= $row['no_surat'] ?>" data-no-surat="<?= $row['no_surat'] ?>" style="display: none;">
                    <td colspan="7">
                        <div class="chat-box" data-no-surat="<?= $row['no_surat'] ?>"></div>
                        <form class="form-chat" data-no-surat="<?= $row['no_surat'] ?>" method="POST">
                            <input type="hidden" name="pengirim" value="<?= htmlspecialchars($user['nama']) ?>">
                            <input type="hidden" name="penerima" value="<?= ($user['status'] === 'Super Admin' ? 'Sekretariat' : 'Super Admin') ?>">
                            <input type="hidden" name="no_surat" value="<?= htmlspecialchars($row['no_surat']) ?>">
                            <textarea name="pesan" placeholder="Ketik pesan..." required style="width: 50%;"></textarea>
                            <button type="submit">Kirim</button>
                        </form>   
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="7">Data tidak ditemukan</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Reset Button -->
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
    var table = document.getElementById("tabelSuratDisposisiTindakLanjut").cloneNode(true);

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

    // Ambil header
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

    for (var i = 1; i < bodyRows.length; i++) {
        var row = bodyRows[i];
        var rowData = [];
        var cells = row.querySelectorAll('td');
        cells.forEach((cell, index) => {
            if (!removeIndexes.includes(index)) {
                rowData.push(cell.textContent.trim());
            }
        });
        if (rowData.length > 0) {
            dataArray.push(rowData);
        }
    }

    var exportData = [headersArray, ...dataArray];

    var ws = XLSX.utils.aoa_to_sheet(exportData);
    ws['!cols'] = [
        { wch: 15 }, 
        { wch: 15 }, 
        { wch: 30 }, 
        { wch: 15 }, 
    ];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "surat disposisi tindak lanjut");

    XLSX.writeFile(wb, "surat_disposisi_tindak_lanjut.xlsx");
    alert("Data berhasil diekspor!");
}

function resetFilters() {
  const selects = document.querySelectorAll('select');
  selects.forEach(select => {
    select.selectedIndex = 0;
  });

  const dateInput = document.getElementById('tanggalSurat');
  if (dateInput) dateInput.value = '';

  const rows = document.querySelectorAll('.data-row');
  rows.forEach(row => {
    row.style.display = "table-row";
  });

  const resetContainer = document.getElementById("reset-container");
  if (resetContainer) {
    resetContainer.style.display = "none";
  }
}

function searchTable() {
  const input = document.getElementById("searchInput").value.toLowerCase();
  const rows = document.querySelectorAll("#tabelSuratDisposisiTindakLanjut .data-row");

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

  const resetContainer = document.getElementById("reset-container");
  if (input.length > 0) {
    resetContainer.style.display = "block";
  }
}


// function cssAttr(v){ return String(v).replace(/\\/g,'\\\\').replace(/"/g,'\\"'); }

// function toggleChatBox(noSurat) {
//   const row = document.querySelector(`tr[id^="chatbox-"][data-no-surat="${cssAttr(noSurat)}"]`);
//   if (!row) return;
//   const chatBox = row.querySelector('.chat-box');
//   row.style.display = (row.style.display === 'none' || !row.style.display) ? '' : 'none';
//   if (row.style.display !== 'none') {
//     loadChat(noSurat, chatBox);
//   }
// }


function loadChat(noSurat, container) {
  const url = './pesan_lihat.php?ajax=1&no_surat=' + encodeURIComponent(noSurat);
  container.innerHTML = '<div style="opacity:.6">Memuat percakapan...</div>';

  fetch(url, {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(async res => {
      const text = await res.text();
      if (!res.ok) throw new Error('HTTP ' + res.status + ' — ' + text.slice(0, 200));
      return text;
    })
  .then(html => {
    if (html && html.trim()) {
      container.innerHTML = '<div class="chat-container">' + html + '</div>';
    } else {
      container.innerHTML = '<div style="opacity:.6">Belum ada pesan.</div>';
    }
    container.scrollTop = container.scrollHeight;
  })
    .catch(err => {
      container.innerHTML = '<div style="color:#b00020">Gagal memuat chat: ' + err.message + '</div>';
    });
}


setInterval(function() {
  document.querySelectorAll('[id^="chatbox-"]').forEach(function(chatbox) {
    const noSurat = chatbox.dataset.noSurat;
    const chatBoxDiv = chatbox.querySelector('.chat-box');
    if (noSurat && chatBoxDiv && chatbox.style.display !== 'none') {
      fetch(`pesan_lihat.php?ajax=1&no_surat=${encodeURIComponent(noSurat)}`)
        .then(r => r.text())
        .then(html => {
          chatBoxDiv.innerHTML = html;
          chatBoxDiv.scrollTop = chatBoxDiv.scrollHeight;
        });
    }
  });
}, 10000);


function sendChat(e, noSurat) {
    e.preventDefault();
    const form = e.target;
    const pesan = form.pesan.value;
    const pengirim = form.pengirim.value;
    
    fetch('pesan_buat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `no_surat=${encodeURIComponent(noSurat)}&pengirim=${encodeURIComponent(pengirim)}&pesan=${encodeURIComponent(pesan)}`
    }).then(() => {
        form.pesan.value = '';
        loadChat(noSurat);
    });
}

function initialChat() {
    const noSurat = document.querySelector('input[name="no_surat"]').value;
    const chatBox = document.getElementById('chat-box');
    fetch('pesan_lihat.php?no_surat=' + noSurat)
        .then(res => res.text())
        .then(data => {
            chatBox.innerHTML = data;
            chatBox.scrollTop = chatBox.scrollHeight;
        });
}

document.querySelectorAll('.form-chat').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(form);
        const noSurat = form.dataset.noSurat;
        const chatBox = form.closest('tr').querySelector('.chat-box');

        fetch('pesan_buat.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(() => {
            form.reset();
            loadChat(noSurat, chatBox);
        });
    });
});


function editPesan(idPesan, isiLama) {
    const pesanBaru = prompt("Edit pesan:", isiLama);
    if (pesanBaru && pesanBaru !== isiLama) {
        fetch("pesan_edit.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "id_pesan=" + encodeURIComponent(idPesan) +
                  "&pesan_baru=" + encodeURIComponent(pesanBaru)
        })
        .then(res => res.text())
        .then(msg => {
            if (msg.toLowerCase().includes("berhasil")) {
                alert("Pesan berhasil diedit");
                // kalau mau refresh chat box, panggil ulang loadChat() di sini
            } else {
                alert("Gagal mengedit pesan: " + msg);
            }
        })
        .catch(err => {
            alert("Terjadi kesalahan: " + err);
        });
    }
}



function toggleDropdown(selectElement, rowId) {
    const lainnyaContainer = document.getElementById('lainnya-container-' + rowId);
    const inputLain = document.getElementById('disposisi_kepada_lain-' + rowId);

    if (selectElement.value === 'lain') {
        lainnyaContainer.style.display = 'block';
        inputLain.setAttribute('required', 'required');
    } else {
        lainnyaContainer.style.display = 'none';
        inputLain.removeAttribute('required');
        inputLain.value = '';
    }
}

function setDisposisi(selectElement, rowId) {
    const selectedValue   = selectElement.value;
    const dataRow         = selectElement.closest('.data-row');                 
    const noSurat         = dataRow.dataset.noSurat;                            
    const idSurat         = rowId;                                             
    const lainnyaContainer= document.getElementById('lainnya-container-' + rowId);
    const inputLain       = document.getElementById('disposisi_kepada_lain-' + rowId);


    if (selectedValue === 'lain') {
        lainnyaContainer.style.display = 'block';
        inputLain.setAttribute('required', 'required');
        
        // Tambahkan event listener untuk input "lain-lain"
        inputLain.onchange = function() {
            updateDisposisi(idSurat, this.value, noSurat, dataRow);
        };
    } else {
        lainnyaContainer.style.display = 'none';
        inputLain.removeAttribute('required');
        inputLain.value = '';
        
        // Langsung update ke server untuk pilihan selain "lain-lain"
        updateDisposisi(idSurat, selectedValue, noSurat, dataRow);
    }
}

function updateDisposisi(idSurat, disposisiValue, noSurat, dataRow) {
    fetch('surat_disposisi_tindak_lanjut.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `aksi=update_disposisi&id_tindaklanjut=${encodeURIComponent(idSurat)}&disposisi_kepada=${encodeURIComponent(disposisiValue)}`
    })
    .then(async (res) => {
        const raw = await res.text();
        const clean = raw.replace(/^\uFEFF/, '').trim();
        let data;
        try { data = JSON.parse(clean); } 
        catch { data = { ok: (clean === 'OK') }; } 
        return data;
    })
    .then(data => {
        if (data.ok) {
            const disposisiTd = dataRow.querySelector('td:nth-child(6)');
            disposisiTd.innerHTML = '';

            const valueDisplay = document.createElement('span');
            valueDisplay.textContent = disposisiValue;
            valueDisplay.style.marginRight = '10px';
            valueDisplay.style.fontWeight = 'bold';
            disposisiTd.appendChild(valueDisplay);

            const editButton = document.createElement('button');
            editButton.textContent = '✏️';
            editButton.onclick = function() {
                showEditForm(disposisiTd, idSurat, noSurat, dataRow, disposisiValue);
            };
            disposisiTd.appendChild(editButton);

            const chatBtn = dataRow.querySelector('.btn-chat');
            if (chatBtn && chatBtn.disabled) {
                chatBtn.disabled = false;
                chatBtn.removeAttribute('title');
                chatBtn.onclick = function () { toggleChatBoxFromBtn(chatBtn); };
            }

            alert('Disposisi berhasil diperbarui!');
        } else {
            alert('Gagal memperbarui disposisi: ' + (data.error || 'unknown'));
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan jaringan: ' + err.message);
    });
}

function showEditForm(disposisiTd, idSurat, noSurat, dataRow, currentValue) {
    disposisiTd.innerHTML = '';
    
    const select = document.createElement('select');
    select.innerHTML = `
        <option value="GUDANG FARMASI">GUDANG FARMASI</option>
        <option value="GUDANG LOGISTIK">GUDANG LOGISTIK</option>
        <option value="GUDANG FIX ASET">GUDANG FIX ASET</option>
        <option value="FARMASI RAWAT JALAN">FARMASI RAWAT JALAN</option>
        <option value="FARMASI RAWAT INAP">FARMASI RAWAT INAP</option>
        <option value="POLI KLINIK RAWAT JALAN">POLI KLINIK RAWAT JALAN</option>
        <option value="INSTALASI GAWAT DARURAT">INSTALASI GAWAT DARURAT</option>
        <option value="RADIOLOGI">RADIOLOGI</option>
        <option value="LABORATORIUM">LABORATORIUM</option>
        <option value="NS ROSALINA">NS ROSALINA</option>
        <option value="NS TERATAI">NS TERATAI</option>
        <option value="NS ANTURIUM">NS ANTURIUM</option>
        <option value="NS ALAMANDA">NS ALAMANDA</option>
        <option value="NS BERSALIN">NS BERSALIN</option>
        <option value="NS PERINATOLOGI">NS PERINATOLOGI</option>
        <option value="UMUM RT">UMUM RT</option>
        <option value="ICU">ICU</option>
        <option value="OK">OK</option>
        <option value="KEPERAWATAN">KEPERAWATAN</option>
        <option value="KEUANGAN">KEUANGAN</option>
        <option value="TPP">TPP</option>
        <option value="IT">IT</option>
        <option value="GIZI">GIZI</option>
        <option value="HEMODIALISA">HEMODIALISA</option>
        <option value="LAUNDRY + KEBERSIHAN">LAUNDRY + KEBERSIHAN</option>
        <option value="KEPEGAWAIAN & DIKLAT">KEPEGAWAIAN & DIKLAT</option>
        <option value="MARKETING">MARKETING</option>
        <option value="INFORMASI & KOMPLAIN">INFORMASI & KOMPLAIN</option>
        <option value="YANJANGMED">YANJANGMED</option>
        <option value="TIM PMKP">TIM PMKP</option>
        <option value="TIM PPI">TIM PPI</option>
        <option value="TIM K3">TIM K3</option>
        <option value="DIREKSI">DIREKSI</option>
        <option value="REKAM MEDIS">REKAM MEDIS</option>
        <option value="AKUNTANSI & PERPAJAKAN">AKUNTANSI & PERPAJAKAN</option>
        <option value="SEKRETARIAT">SEKRETARIAT</option>
        <option value="CLEANING SERVICE (CS)">CLEANING SERVICE (CS)</option>
        <option value="DRIVER & SECURITY">DRIVER & SECURITY</option>
        <option value="KASIR RAWAT INAP">KASIR RAWAT INAP</option>
        <option value="KASIR RAWAT JALAN">KASIR RAWAT JALAN</option>
        <option value="TIM PENGENDALI BPJS (Casemix)">TIM PENGENDALI BPJS (Casemix)</option>
        <option value="NS LOTUS">NS LOTUS</option>
        <option value="NS TULIP">NS TULIP</option>
        <option value="KOMITE KEPERAWATAN">KOMITE KEPERAWATAN</option>
        <option value="TIM PKRS">TIM PKRS</option>
        <option value="lain">Lain-lain</option>
    `;
    select.value = currentValue;
    
    select.onchange = function() {
        setDisposisi(this, dataRow.dataset.id);
    };
    
    disposisiTd.appendChild(select);
    if (currentValue === 'lain') {
        const lainContainer = document.createElement('div');
        lainContainer.innerHTML = `
            <input type="text" 
                value="${currentValue}" 
                placeholder="Masukkan tujuan lain..." 
                onchange="updateDisposisi('${idSurat}', this.value, '${noSurat}', ${JSON.stringify(dataRow.dataset).replace(/"/g, '&quot;')})" />
        `;
        disposisiTd.appendChild(lainContainer);
    }
}

function handleLainTL(selectEl, inputId) {
  const input = document.getElementById(inputId);
  if (!input) return;
  if (selectEl.value === 'lain') {
    input.style.display = 'block';
    input.required = true;
    selectEl.onchange = null;
  } else {
    input.style.display = 'none';
    input.required = false;
  }
}

function onChangeDisposisiTL(selectEl, inputId) {
  const input = document.getElementById(inputId);
  if (!input) return;

  if (selectEl.value === 'lain') {
    input.style.display = 'block';
    input.required = true;
    input.focus();
  } else {
    input.style.display = 'none';
    input.required = false;
    if (typeof selectEl.form.requestSubmit === 'function') {
      selectEl.form.requestSubmit();
    } else {
      selectEl.form.submit();
    }
  }
}

function toggleChatBoxFromBtn(btn) {
  const dataRow = btn.closest('tr');
  if (!dataRow) return;

  // Baris chat harus tepat setelah baris data
  const chatRow = dataRow.nextElementSibling;
  if (!chatRow) return;

  const chatBox = chatRow.querySelector('.chat-box');
  if (!chatBox) return;

  const currentlyHidden = (chatRow.style.display === 'none' || !chatRow.style.display);
  chatRow.style.display = currentlyHidden ? 'table-row' : 'none';

  if (currentlyHidden) {
    const noSurat = btn.dataset.noSurat || chatRow.dataset.noSurat || dataRow.dataset.noSurat;
    if (noSurat) loadChat(noSurat, chatBox);
  }
}


  </script>  
</body>
</html>