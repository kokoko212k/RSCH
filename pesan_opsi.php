<?php
session_start();
include 'config.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// wajib login
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// ===================== Ambil konteks user =====================
$user         = $_SESSION['user'];
$user_nik     = $user['nik']    ?? null;          // pakai NIK
$user_nama    = $user['nama']   ?? '';
$user_status  = $user['status'] ?? '';
$user_unit    = trim((string)($user['unit'] ?? ''));

// dipakai navbar
$role = $user_status;
$can_access_eoffice = in_array($role, ['Super Admin']);

// role yang boleh lihat lintas unit untuk semua thread
$roles_full_access = ['Super Admin', 'Direktur', 'Sekretariat', 'Admin', 'Member'];
$by_role_full      = in_array($user_status, $roles_full_access, true) ? 1 : 0;

// ===================== Ambil parameter surat =====================
$no_surat = $_GET['no_surat'] ?? ($_GET['id'] ?? null);
  $chatOptions = [
    ['label' => 'Kanal 2', 'view' => 'pesan_lihat_2.php', 'post' => 'pesan_buat_2.php'],
    ['label' => 'Kanal 4', 'view' => 'pesan_lihat_4.php', 'post' => 'pesan_buat_4.php'],
    ['label' => 'Kanal 5', 'view' => 'pesan_lihat_5.php', 'post' => 'pesan_buat_5.php'],
  ];
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
.chat-picker-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.2); z-index:999;
}
.chat-picker{
  position:fixed; bottom:20px; left:50%; transform:translateX(-50%);
  background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.15);
  min-width:260px; max-width:90vw; overflow:hidden; z-index:1000;
}
.chat-picker header{
  padding:10px 14px; font-weight:600; border-bottom:1px solid #eee;
}
.chat-picker .item{
  display:flex; align-items:center; gap:10px;
  padding:12px 14px; border-bottom:1px solid #f2f2f2; cursor:pointer;
}
.chat-picker .item:last-child{ border-bottom:none; }
.chat-picker .item:hover{ background:#f7faff; }
.chat-picker .badge{
  margin-left:auto; min-width:20px; height:20px; border-radius:999px;
  display:inline-flex; align-items:center; justify-content:center;
  font-size:12px; background:#e9eefc;
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

  <h2 class="judul-surat-luar">Surat Notif</h2>

    <div class="kontainer-balok">
        <div class="wrap">
        <div class="head">
            <a href="surat_masuk.php">← Kembali</a>
            <h2 style="margin:0">Chat: <?= htmlspecialchars($no_surat, ENT_QUOTES) ?></h2>
        </div>
        </div>

        <div style="margin-top:18px">
            <button class="btn-chat"
                    type="button"
                    data-no-surat="<?= h($no_surat) ?>"
                    data-chat-options='<?= h(json_encode($chatOptions)) ?>'
                    style="display:inline-flex;align-items:center;gap:8px;padding:20px 30px;border-radius:10px;border:1px solid #ddd;background:#fff;cursor:pointer">
                <span>💬</span> <span>Pilih kanal chat</span>
            </button>
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
  <script>
  const noSurat  = <?= json_encode($no_surat) ?>;
//   function updateToVisibility() {
//     const type = document.querySelector('input[name="to_type"]:checked')?.value || 'status';
//     const elStatus = document.getElementById('toStatus');
//     const elNama   = document.getElementById('toNama');
//     if (type === 'status') {
//       elStatus.style.display = '';
//       elNama.style.display   = 'none';
//     } else {
//       elStatus.style.display = 'none';
//       elNama.style.display   = '';
//     }
//   }
//   document.querySelectorAll('input[name="to_type"]').forEach(r => {
//     r.addEventListener('change', updateToVisibility);
//   });
//   // Prefill: jika ada $pref_status, default tetap "status"
//   updateToVisibility();


  </script>
<script>
function openChatPicker(btn){
  const noSurat = btn.dataset.noSurat || '';
  let options = [];
  try { options = JSON.parse(btn.dataset.chatOptions || '[]') } catch(e){ options = []; }

  // kalau cuma 1 opsi → langsung masuk
  if (options.length === 1) {
    const view = options[0].view || 'pesan_lihat.php';
    location.href = view + '?no_surat=' + encodeURIComponent(noSurat);
    return;
  }
  // kalau kosong → fallback (ubah sesuai default kamu)
  if (options.length === 0) {
    location.href = 'pesan_lihat.php?no_surat=' + encodeURIComponent(noSurat);
    return;
  }

  // backdrop
  const backdrop = document.createElement('div');
  backdrop.className = 'chat-picker-backdrop';
  backdrop.addEventListener('click', () => backdrop.remove());

  // sheet
  const sheet = document.createElement('div');
  sheet.className = 'chat-picker';
  sheet.innerHTML = `<header>Pilih kanal chat</header>`;

  options.forEach(opt => {
    const item = document.createElement('div');
    item.className = 'item';
    item.innerHTML = `
      <div>🗨️ ${opt.label || opt.view}</div>
      <!-- optional badge unread per kanal, kalau punya angka -->
      ${opt.unread ? `<span class="badge">${opt.unread}</span>` : ``}
    `;
    item.addEventListener('click', (e) => {
      e.stopPropagation();
      const view = opt.view || 'pesan_lihat.php';
      backdrop.remove();
      // masuk ke halaman chat pilihan
      location.href = view + '?no_surat=' + encodeURIComponent(noSurat);
    });
    sheet.appendChild(item);
  });

  backdrop.appendChild(sheet);
  document.body.appendChild(backdrop);
}

// pasang handler ke semua tombol 💬
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-chat');
  if (!btn) return;
  e.preventDefault();
  openChatPicker(btn);
});
</script>
</body>
</html>
