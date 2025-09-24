<?php
session_start();
include 'config.php';

// wajib login
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// ambil konteks user
$user        = $_SESSION['user'];
$user_nama   = $user['nama']   ?? '';
$user_status = $user['status'] ?? '';

// dipakai navbar
$role = $user_status;
$can_access_eoffice = in_array($role, ['Super Admin']);

// dipakai chat
$pengirim = $user_nama;
$penerima = ($user_status === 'Super Admin') ? 'Sekretariat' : 'Super Admin';

// ambil parameter surat
$no_surat = $_GET['no_surat'] ?? ($_GET['id'] ?? null);

// deteksi AJAX
$isAjax = isset($_GET['ajax']) ||
          (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// pastikan PDO lempar exception
if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}


// --- POST: hapus pesan (opsional dipakai kalau form delete submit ke file ini)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_pesan_id'])) {
    $hapusId      = (int) ($_POST['hapus_pesan_id'] ?? 0);
    $hapusNoSurat = $_POST['no_surat'] ?? '';

    // cek kepemilikan pesan
    $stmtCheck = $pdo->prepare("SELECT pengirim FROM pesan WHERE id = ?");
    $stmtCheck->execute([$hapusId]);
    $pengirimPesan = $stmtCheck->fetchColumn();

    if ($pengirimPesan === $user_status || $pengirimPesan === $user_nama) {
        $stmtDelete = $pdo->prepare("DELETE FROM pesan WHERE id = ?");
        $stmtDelete->execute([$hapusId]);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            exit;
        } else {
            header("Location: ?no_surat=" . urlencode($hapusNoSurat) . "&status=deleted");
            exit;
        }
    } else {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8', true, 403);
            echo json_encode(['ok'=>false,'error'=>'Akses ditolak']);
            exit;
        } else {
            header("Location: ?no_surat=" . urlencode($hapusNoSurat) . "&status=error&msg=Akses%20ditolak");
            exit;
        }
    }
}

// --- notifikasi GET hanya jika non-AJAX
if (!$isAjax && isset($_GET['status'])) {
    if ($_GET['status'] === 'success') {
        echo "<div class='notif success'>Pesan berhasil dikirim.</div>";
    } elseif ($_GET['status'] === 'error') {
        $msg = htmlspecialchars($_GET['msg'] ?? 'Terjadi kesalahan', ENT_QUOTES, 'UTF-8');
        echo "<div class='notif error'>{$msg}</div>";
    } elseif ($_GET['status'] === 'deleted') {
        echo "<div class='notif success'>Pesan berhasil dihapus.</div>";
    }
}

// --- wajib punya no_surat
if (!$no_surat) {
    echo $isAjax ? '' : "<div class='notif error'>Nomor surat tidak ditemukan.</div>";
    exit;
}

// --- ambil pesan
$stmt = $pdo->prepare("SELECT * FROM pesan WHERE no_surat = ? ORDER BY waktu ASC");
$stmt->execute([$no_surat]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- JIKA AJAX: hanya render bubble chat & keluar
if ($isAjax) {
    if (!$rows) {
        echo '<div class="notif">Belum ada pesan untuk surat ini.</div>';
        exit;
    }

    foreach ($rows as $row) {
        $isSender    = ($row['pengirim'] === $user_status || $row['pengirim'] === $user_nama);
        $bubbleClass = $isSender ? 'chat-bubble right' : 'chat-bubble left';
        $label       = $isSender ? 'Anda' : htmlspecialchars($row['pengirim'] ?? '', ENT_QUOTES, 'UTF-8');
        $pesanTxt    = trim($row['pesan'] ?? '');
        $pesanSafe   = $pesanTxt !== '' ? nl2br(htmlspecialchars($pesanTxt, ENT_QUOTES, 'UTF-8')) : '<i>(Tidak ada isi pesan)</i>';
        $waktu       = date('d M Y H:i', strtotime($row['waktu'] ?? 'now'));
        ?>
        <div class="<?= $bubbleClass ?>">
            <strong><?= $label ?></strong><br>
            <div class="chat-text"><b>Pesan:</b> <?= $pesanSafe ?></div>
            <div class="chat-time"><?= $waktu ?></div>

            <?php if ($isSender): ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="hapus_pesan_id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="no_surat" value="<?= htmlspecialchars($no_surat, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" style="background:none;border:none;color:red;cursor:pointer;">🗑️</button>
              </form>
              <button
                onclick="editPesan(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['pesan'] ?? '', ENT_QUOTES, 'UTF-8') ?>')"
                style="background:none;border:none;color:blue;cursor:pointer;">
                ✏️
              </button>
            <?php endif; ?>
        </div>
        <?php
    }
    exit; 
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
            <input type="text" placeholder="..." id="searchInput" oninput="searchTable()" />
            <button>Cari</button>
        </div>
    </div>

  <div class="wrap">
    <div class="head">
      <a href="surat_disposisi_tindak_lanjut.php">← Kembali</a>
      <h2 style="margin:0">Chat: <?= htmlspecialchars($no_surat, ENT_QUOTES) ?></h2>
    </div>

    <div class="card">
      <div id="chatBox" class="chat-box"><div style="opacity:.6">Memuat...</div></div>

      <form id="formSend" class="send" method="post">
        <input type="hidden" name="no_surat" value="<?= htmlspecialchars($no_surat, ENT_QUOTES) ?>">
        <input type="hidden" name="pengirim" value="<?= htmlspecialchars($pengirim, ENT_QUOTES) ?>">
        <input type="hidden" name="penerima" value="<?= htmlspecialchars($penerima, ENT_QUOTES) ?>">
        <textarea name="pesan" placeholder="Ketik pesan..." required></textarea>
        <button type="submit">Kirim</button>
      </form>
    </div>
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
const noSurat  = <?= json_encode($no_surat) ?>;
const chatBox  = document.getElementById('chatBox');
const formSend = document.getElementById('formSend');

function loadChat() {
  chatBox.innerHTML = '<div style="opacity:.6">Memuat...</div>';
  fetch('pesan_lihat.php?ajax=1&no_surat=' + encodeURIComponent(noSurat), {
    headers: {'X-Requested-With': 'XMLHttpRequest'},
    credentials: 'same-origin'
  })
  .then(r => r.text())
  .then(html => {
    chatBox.innerHTML = (html && html.trim())
      ? '<div class="chat-container">' + html + '</div>'
      : '<div style="opacity:.6">Belum ada pesan.</div>';
    chatBox.scrollTop = chatBox.scrollHeight;
  })
  .catch(err => chatBox.innerHTML = '<div style="color:#b00020">Gagal memuat: ' + err.message + '</div>');
}

formSend.addEventListener('submit', e => {
  e.preventDefault();
  const fd = new FormData(formSend);
  const btn = formSend.querySelector('button');
  btn.disabled = true;

  fetch('pesan_buat.php', { method:'POST', body:fd, credentials:'same-origin' })
    .then(r => r.text())
    .then(() => { formSend.pesan.value=''; loadChat(); })
    .finally(() => { btn.disabled = false; });
});

// refresh berkala
setInterval(() => {
  loadChat();
}, 10000);

loadChat();
</script>
</body>
</html>