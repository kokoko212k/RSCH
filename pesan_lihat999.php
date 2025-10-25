<?php
session_start();
include 'config.php';

// ====== guard login ======
if (!isset($_SESSION['user'])) {
  header("Location: login.php");
  exit;
}

// ====== helper ======
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function norm_file_key(?string $f): ?string {
  if (!$f) return null;
  $base = basename($f);
  return (strpos($f, 'uploads/') === 0) ? $f : ('uploads/'.$base);
}

/**
 * Dapatkan no_surat kanonik dari: no_surat langsung ATAU dari file_url (opsional).
 * Urutan lookup: disposisi → disposisi_pengajuan → tindak_lanjut → notif → masuk → keluar → pengajuan(no_surat/no_perihal)
 */
function canon_no_surat(PDO $pdo, ?string $noSurat, ?string $fileUrl): ?string {
  $no = trim((string)$noSurat);
  if ($no !== '') return $no;

  $fk = norm_file_key($fileUrl);
  if (!$fk) return null;

  $tables = [
    ['surat_disposisi','no_surat','file_url'],
    ['surat_disposisi_pengajuan','no_surat','file_url'],
    ['surat_disposisi_tindak_lanjut','no_surat','file_url'],
    ['surat_notif','no_surat','file_url'],
    ['surat_masuk','no_surat','file_url'],
    ['surat_keluar','no_surat','file_url'],
    // pengajuan kadang pakai no_perihal sebagai identitas
    ['surat_pengajuan','no_surat','file_url'],
    ['surat_pengajuan','no_perihal','file_url'],
  ];
  foreach ($tables as [$t,$colNo,$colFile]) {
    $q = $pdo->prepare("SELECT {$colNo} FROM {$t}
                        WHERE {$colFile} = :raw OR {$colFile} = :base
                        ORDER BY id DESC LIMIT 1");
    $q->execute(['raw'=>$fk,'base'=>basename($fk)]);
    $v = trim((string)$q->fetchColumn());
    if ($v !== '') return $v;
  }
  return null;
}

// ====== context user ======
$user        = $_SESSION['user'];
$user_nik    = $user['nik']    ?? null;
$user_nama   = $user['nama']   ?? '';
$user_status = $user['status'] ?? '';
$user_unit   = trim((string)($user['unit'] ?? ''));

// role utk navbar
$role = $user_status;
$can_access_eoffice = in_array($role, ['Super Admin']);

// peran yang boleh lintas unit (tetap seperti awal)
$roles_full_access = ['Super Admin', 'Direktur', 'Sekretariat', 'Admin', 'Member'];
$by_role_full      = in_array($user_status, $roles_full_access, true) ? 1 : 0;

// ====== ambil parameter ======
$param_no_surat = $_GET['no_surat'] ?? ($_GET['id'] ?? null);
$param_fileurl  = $_GET['file_url'] ?? null;                // ← OPSIONAL barunya

// AJAX?
$isAjax = isset($_GET['ajax']) ||
          (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// paksa PDO lempar exception
if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// ====== RESOLVE no_surat kanonik ======
$no_surat = canon_no_surat($pdo, $param_no_surat, $param_fileurl);

// ====== POST: hapus pesan ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_pesan_id'])) {
  $hapusId      = (int) ($_POST['hapus_pesan_id'] ?? 0);
  $hapusNoSurat = $_POST['no_surat'] ?? '';

  $stmtCheck = $pdo->prepare("SELECT pengirim_nik, pengirim_unit FROM pesan WHERE id = ?");
  $stmtCheck->execute([$hapusId]);
  $own = $stmtCheck->fetch(PDO::FETCH_ASSOC);

  $bolehHapus = false;
  if (!empty($own['pengirim_nik']) && !empty($user_nik)) {
    $bolehHapus = ($own['pengirim_nik'] === $user_nik);
  } else {
    $bolehHapus = (trim((string)$own['pengirim_unit']) === $user_unit);
  }

  if ($bolehHapus) {
    $pdo->prepare("DELETE FROM pesan WHERE id = ?")->execute([$hapusId]);
    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>true]);
      exit;
    } else {
      header("Location: ?no_surat=".urlencode($hapusNoSurat)."&status=deleted");
      exit;
    }
  } else {
    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8', true, 403);
      echo json_encode(['ok'=>false,'error'=>'Akses ditolak']);
      exit;
    } else {
      header("Location: ?no_surat=".urlencode($hapusNoSurat)."&status=error&msg=Akses%20ditolak");
      exit;
    }
  }
}

// ====== notifikasi GET non-AJAX ======
if (!$isAjax && isset($_GET['status'])) {
  if ($_GET['status'] === 'success') {
    echo "<div class='notif success'>Pesan berhasil dikirim.</div>";
  } elseif ($_GET['status'] === 'error') {
    $msg = h($_GET['msg'] ?? 'Terjadi kesalahan');
    echo "<div class='notif error'>{$msg}</div>";
  } elseif ($_GET['status'] === 'deleted') {
    echo "<div class='notif success'>Pesan berhasil dihapus.</div>";
  }
}

// ====== wajib ada no_surat setelah resolve ======
if (!$no_surat) {
  echo $isAjax ? '' : "<div class='notif error'>Nomor surat tidak ditemukan.</div>";
  exit;
}

// ====== cek keterlibatan (participant-sees-all) ======
$by_participation_full = 0;
$chk = $pdo->prepare("
  SELECT 1
  FROM pesan
  WHERE no_surat = :no_surat
    AND (
         pengirim_nik = :me_nik
      OR penerima_nik = :me_nik
      OR UPPER(TRIM(pengirim_unit)) = UPPER(TRIM(:me_unit))
      OR UPPER(TRIM(penerima_unit)) = UPPER(TRIM(:me_unit))
    )
  LIMIT 1
");
$chk->execute([
  ':no_surat' => $no_surat,
  ':me_nik'   => $user_nik,
  ':me_unit'  => $user_unit,
]);
if ($chk->fetchColumn()) $by_participation_full = 1;

$has_full_access = ($by_role_full || $by_participation_full) ? 1 : 0;

// ====== ambil pesan thread ini ======
$stmt = $pdo->prepare("
  SELECT *
  FROM pesan
  WHERE no_surat = :no_surat
    AND (
         :full = 1
      OR pengirim_nik = :me_nik
      OR penerima_nik = :me_nik
      OR UPPER(TRIM(pengirim_unit)) = UPPER(TRIM(:me_unit))
      OR UPPER(TRIM(penerima_unit)) = UPPER(TRIM(:me_unit))
    )
  ORDER BY waktu ASC
");
$stmt->execute([
  ':no_surat' => $no_surat,
  ':me_nik'   => $user_nik,
  ':me_unit'  => $user_unit,
  ':full'     => $has_full_access,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ====== (opsional) prefill status dari tindak lanjut (biarkan) ======
$pref_status = null;
try {
  $qPref = $pdo->prepare("SELECT disposisi_kepada FROM surat_disposisi_tindak_lanjut WHERE no_surat = ? ORDER BY id_tindaklanjut DESC LIMIT 1");
  $qPref->execute([$no_surat]);
  $pref_status = trim((string)$qPref->fetchColumn()) ?: null;
} catch (Throwable $e) { $pref_status = null; }

// ====== AJAX render ======
if ($isAjax) {
  if (!$rows) {
    echo '<div class="notif">Belum ada pesan untuk surat ini.</div>';
    exit;
  }
  $printed = [];
  foreach ($rows as $row) {
    $key = ($row['pengirim_nik'] ?? '').'|'.trim((string)($row['pesan'] ?? '')).'|'.substr((string)($row['waktu'] ?? ''),0,19);
    if (isset($printed[$key])) continue; // dedupe universal
    $printed[$key] = true;

    $isSender = (!empty($row['pengirim_nik']) && $row['pengirim_nik'] === $user_nik);
    $bubbleClass = $isSender ? 'chat-bubble right' : 'chat-bubble left';
    $labelSrc = $row['pengirim_nama'] ?? $row['pengirim_unit'] ?? '';
    $label    = $isSender ? 'Anda' : h($labelSrc);
    $pesanTxt = trim((string)($row['pesan'] ?? ''));
    $pesanSafe= $pesanTxt !== '' ? nl2br(h($pesanTxt)) : '<i>(Tidak ada isi pesan)</i>';
    $waktu    = date('d M Y H:i', strtotime($row['waktu'] ?? 'now'));
    ?>
    <div class="<?= $bubbleClass ?>">
      <strong><?= $label ?></strong><br>
      <div class="chat-text"><?= $pesanSafe ?></div>
      <div class="chat-time"><?= h($waktu) ?></div>

      <?php if ($isSender): ?>
        <form method="post" style="display:inline;">
          <input type="hidden" name="hapus_pesan_id" value="<?= (int)$row['id'] ?>">
          <input type="hidden" name="no_surat" value="<?= h($no_surat) ?>">
          <button type="submit" style="background:none;border:none;color:red;cursor:pointer;">🗑️</button>
        </form>
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
.send {
  width: 70%;         
  margin-left: 0;
  height: 60px;       
  margin-right: auto;
  display: flex;
  gap: 10px;
  padding: 10px;
  /* border-top: 1px solid #ddd; */
}

.send textarea {
    flex: 1;
    height: 60px;
    resize: none;
    padding: 8px;
    border-radius: 8px;
    border: 1px solid #ccc;
    font-size: 14px;
}

.send button {
    background-color: #007bff;
    color: #fff;
    height: 60px;
    /* width: 40px; */
    border: none;
    border-radius: 8px;
    padding: 10px 15px;
    cursor: pointer;
}

.send button:hover {
    background-color: #0056b3;
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

    <!-- <div class="balok-1">
      <button type="button" class="btn-tambah" onclick="exportTableToExcel()">Export</button>
    </div> -->

    <!-- <div class="balok-2">
      <div class="search-bar">
        <input type="text" placeholder="..." id="searchInput" oninput="searchTable()" />
        <button>Cari</button>
      </div>
    </div> -->

  <h2 class="judul-surat-luar">Disposisi Tindak Lanjut</h2>

  <div class="kontainer-balok">
    <div class="wrap">
      <div class="head">
        <a href="surat_disposisi_tindak_lanjut.php">← Kembali</a>
        <h2 style="margin:0">Chat: <?= htmlspecialchars($no_surat, ENT_QUOTES) ?></h2>
      </div>

      <!-- <?php if ($pref_status): ?>
        <div class="notif info">Pesan akan dikirim ke semua user berstatus: <b><?= htmlspecialchars($pref_status) ?></b></div>
      <?php endif; ?> -->

      <div class="card">
        <div id="chatBox" class="chat-box"><div style="opacity:.6">Memuat...</div></div>

        <form id="formSend" class="send" method="post" action="pesan_buat.php">
          <input type="hidden" name="no_surat" value="<?= htmlspecialchars($no_surat, ENT_QUOTES) ?>">
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
      <p>© Copyright IT Support Citra Husada.</p>
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

    fetch(formSend.action, { method:'POST', body:fd, credentials:'same-origin' })
      .then(r => r.text())
      .then(() => { formSend.pesan.value=''; loadChat(); })
      .finally(() => { btn.disabled = false; });
  });

  setInterval(loadChat, 10000);
  loadChat();

  function updateToVisibility() {
    const type = document.querySelector('input[name="to_type"]:checked')?.value || 'status';
    const elStatus = document.getElementById('toStatus');
    const elNama   = document.getElementById('toNama');
    if (type === 'status') {
      elStatus.style.display = '';
      elNama.style.display   = 'none';
    } else {
      elStatus.style.display = 'none';
      elNama.style.display   = '';
    }
  }
  document.querySelectorAll('input[name="to_type"]').forEach(r => {
    r.addEventListener('change', updateToVisibility);
  });
  // Prefill: jika ada $pref_status, default tetap "status"
  updateToVisibility();


  </script>
</body>
</html>