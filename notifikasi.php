<?php
session_start();
require 'config.php';
require 'notifikasi_service.php';

$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: login.php'); exit; }

// --- resolve user_id secara robust ---
$user_id = $user['user']['id'] ?? ($user['id'] ?? null);
if (!$user_id) {
  // coba fallback via NIK
  $nik = $user['nik'] ?? ($user['user']['nik'] ?? null);
  if ($nik) {
    $q = $pdo->prepare("SELECT id FROM users WHERE nik = ? LIMIT 1");
    $q->execute([$nik]);
    $user_id = (int)$q->fetchColumn();
    if ($user_id) {
      $_SESSION['user']['id'] = $user_id;
    }
  }
}

if (!$user_id) { http_response_code(403); exit('User tidak valid'); }

// ambil status & unit juga
$status = $user['status'] ?? ($user['user']['status'] ?? ($user['role'] ?? null));
$role   = $status;
$unit   = $user['unit']   ?? ($user['user']['unit']   ?? null);


if ($user_id <= 0) {
  // (opsional) bantu debug saat dev:
  // echo '<pre>'; var_dump($_SESSION['user']); echo '</pre>'; exit;
  http_response_code(403);
  exit('User tidak valid');
}



if (isset($_GET['open'])) {
  $nid = (int)$_GET['open'];
  if ($nid > 0) {
    markAsRead($pdo, $user_id, $nid);

    // redirect ke action_url kalau ada
    $q = $pdo->prepare("SELECT action_url FROM notifikasi WHERE id=?");
    $q->execute([$nid]);
    $link = $q->fetchColumn();

    header('Location: ' . ($link ?: 'notifikasi.php'));
    exit;
  }
}


// Role yang bisa akses E-Office dan fitur khusus
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

$allowedEofficePages = $rolePages[$role] ?? [];
$can_access_eoffice  = !empty($allowedEofficePages);
$can_access_special = in_array($role, ['Admin', 'Sekretariat', 'Direktur', 'Super Admin', 'Member']);

/* ---------- BADGE: jumlah belum dibaca untuk user ini ---------- */
$sqlBadge = "
  SELECT COUNT(*)
  FROM notifikasi n
  LEFT JOIN detail_notifikasi r
         ON r.notifikasi_id = n.id AND r.user_id = :uid
  WHERE r.notifikasi_id IS NULL
";
$st = $pdo->prepare($sqlBadge);
$st->execute(['uid'=>$user_id]);
$jumlahNotif = getUnreadCount($pdo, $user_id, $status, $unit);

$sqlList = "
  SELECT n.id, n.title, n.body, n.action_url, n.created_at,
         (r.notifikasi_id IS NULL) AS is_unread
  FROM notifikasi n
  LEFT JOIN detail_notifikasi r
         ON r.notifikasi_id = n.id AND r.user_id = :uid
  ORDER BY n.created_at DESC
  LIMIT 100
";
$st = $pdo->prepare($sqlList);
$st->execute(['uid'=>$user_id]);
$items = getInbox($pdo, $user_id, $status, $unit, 100); // ambil 100 item terakhir
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Notifikasi</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
</head>
<style>
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
  position: relative;
  top:13px; right:64px;
  min-width:18px; height:18px;
  padding:0 5px;
  border-radius:999px;
  background:#ff3b30; color:#fff;
  font-size:12px; line-height:18px;
}

/* ====== Inbox (mirip Gmail mobile) ====== */
.inbox { padding-inline: 12px; }
.inbox h1 { display:flex; align-items:center; gap:8px; margin:14px 0; }

.mail-item{
  display:flex;
  align-items:center;
  gap:12px;
  padding:12px 10px;
  border-bottom:1px solid #ececec;
  text-decoration:none;
  background:#fff;
  transition:background .15s ease;
  position:relative;
}
.mail-item:hover{ background:#fafafa; }
.mail-item.unread .subject{ font-weight:700; }
.mail-item.unread .from{ font-weight:600; }
.mail-item.unread .dot{
  display:inline-block; width:8px; height:8px; border-radius:50%;
  background:#1a73e8; margin-left:6px; vertical-align:middle;
}

/* Avatar kiri */
.mail-item .avatar{
  width:42px; height:42px; flex:0 0 42px;
  border-radius:50%;
  background:#f2f6ff;
  display:flex; align-items:center; justify-content:center;
  font-size:22px; color:#1a73e8;
}

/* Bagian tengah */
.mail-item .main{ flex:1 1 auto; min-width:0; display:flex; flex-direction:column; gap:2px; }

/* Baris 1: from + time */
.mail-item .row1{ display:flex; align-items:center; }
.mail-item .from{
  font-size:14px; color:#1f1f1f;
  flex:1 1 auto; min-width:0;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.mail-item .meta-time{
  font-size:12px; color:#6b7280; margin-left:10px; flex:0 0 auto;
}

/* Baris 2: subject (1 line clamp) */
.mail-item .row2.subject{
  font-size:14px; color:#1f1f1f;
  display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical;
  overflow:hidden;
}

/* Baris 3: snippet (2 line clamp) */
.mail-item .row3.snippet{
  font-size:12.5px; color:#6b7280;
  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
  overflow:hidden;
}

/* Ikon kanan */
.mail-item .right-icons{
  flex:0 0 auto; display:flex; align-items:center; gap:6px;
  color:#9aa0a6; font-size:18px;
}

/* Badge reuse (sudah ada) – pastikan tampil rapi di header h1 */
.inbox .badge{
  background:#ff3b30; color:#fff; border-radius:999px;
  padding:2px 8px; font-size:12px; line-height:18px;
}

/* Dark mode ringan (opsional) */
@media (prefers-color-scheme: dark){
  .mail-item{ background:#111214; border-bottom-color:#202124; }
  .mail-item:hover{ background:#15161a; }
  .mail-item .from, .mail-item .row2.subject{ color:#e5e7eb; }
  .mail-item .row3.snippet, .mail-item .meta-time, .right-icons{ color:#9aa0a6; }
  .mail-item .avatar{ background:#0b1a33; color:#86b7ff; }
  .inbox .badge{ background:#e35d5d; }
}
/* Empty state */
.empty-inbox{
  margin: 24px 8px 40px;
  padding: 28px 20px;
  border: 1px dashed #d7d7d7;
  border-radius: 14px;
  background: #fafafa;
  text-align: center;
}
.empty-inbox .empty-icon{
  font-size: 42px;
  color: #9aa0a6;
  margin-bottom: 8px;
}
.empty-inbox h3{ margin: 6px 0 4px; font-size: 18px; }
.empty-inbox p{ margin: 0 0 14px; color: #6b7280; }

/* Tombol primer kecil */
.btn-primary{
  display: inline-block;
  padding: 10px 14px;
  border-radius: 10px;
  background: #1a73e8;
  color: #fff; text-decoration: none;
  font-weight: 600; font-size: 14px;
}
.btn-primary:hover{ opacity: .9; }
#notifPanel.notif-panel{
  position: absolute; right: 90px; top: 58px;
  width: 360px; max-height: 70vh; overflow: auto;
  background:#fff; border:1px solid #e6e6e6; border-radius: 10px;
  box-shadow: 0 8px 30px rgba(0,0,0,.1); z-index: 1001;
}
#notifPanel .notif-head{display:flex;justify-content:space-between;align-items:center;
  padding:10px 12px;border-bottom:1px solid #f0f0f0;}
#notifPanel .lihat-semua{font-size:12px;}
#notifPanel .notif-item{display:block;padding:10px 12px;border-bottom:1px solid #f6f6f6;
  text-decoration:none;color:#222;}
#notifPanel .notif-item.unread{background:#f7fbff;}
#notifPanel .notif-item .title{font-weight:600;margin-bottom:4px;}
#notifPanel .notif-item .meta{font-size:12px;color:#777;}

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
        </div>
      </div>
      <div class="top-buttons">
        <?php if (in_array($role, ['Super Admin', 'Admin', 'Sekretariat', 'Member', 'Direktur'])): ?>
          <a href="sub_beranda.php" class="jelajahi-portal">Layanan</a>
          <button type="button" class="notif-bell" id="btnNotif" title="Notifikasi">
            <i class='bx bxs-bell'></i>
            <?php if ($jumlahNotif > 0): ?><span class="badge" id="notifBadge"><?= $jumlahNotif ?></span><?php endif; ?>
          </button>

          <!-- Panel dropdown -->
          <div id="notifPanel" class="notif-panel" style="display:none">
            <div class="notif-head">
              <strong>Notifikasi</strong>
              <a href="notifikasi.php" class="lihat-semua">Lihat semua »</a>
            </div>
            <div id="notifList" class="notif-list"><div style="opacity:.6">Memuat...</div></div>
          </div>
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
        <!-- <div class="search-bar-bottom">
          <input type="text" placeholder="Cari..." />
          <button>Cari</button>
        </div> -->
      </div>
    </nav>

    <div class="page inbox">
      <h1>
        Notifikasi
        <?php if ($jumlahNotif > 0): ?>
          <span class="badge"><?= $jumlahNotif ?></span>
        <?php endif; ?>
      </h1>

      <?php if (empty($items)): ?>
        <div class="empty-inbox">
          <div class="empty-icon"><i class='bx bxs-inbox'></i></div>
          <h3>Belum ada notifikasi</h3>
          <p>Semua notifikasi akan muncul di sini.</p>
          <!-- opsional: tombol uji -->
          <!-- <a class="btn-primary" href="notifikasi.php?seed=1">Buat 3 notifikasi contoh</a> -->
        </div>
      <?php else: ?>
        <?php foreach ($items as $n): ?>
          <a class="mail-item <?= $n['is_unread'] ? 'unread' : '' ?>"
            href="notifikasi.php?open=<?= (int)$n['id'] ?>">
            <div class="avatar"><i class='bx bxs-bell'></i></div>

            <div class="main">
              <div class="row1">
                <div class="from"><?= htmlspecialchars($n['title'] ?: 'Sistem') ?></div>
                <div class="meta-time"><?= htmlspecialchars(date('g:i A', strtotime($n['created_at']))) ?></div>
              </div>
              <div class="row2 subject">
                <?= htmlspecialchars($n['title'] ?? 'Notifikasi') ?>
                <?php if ($n['is_unread']): ?><span class="dot"></span><?php endif; ?>
              </div>
              <?php if (!empty($n['body'])): ?>
                <div class="row3 snippet"><?= htmlspecialchars($n['body']) ?></div>
              <?php endif; ?>
            </div>

            <div class="right-icons">
              <?php if (!empty($n['action_url'])): ?>
                <i class='bx bx-link-external' title="Ada tautan aksi"></i>
              <?php else: ?>
                <i class='bx bx-dots-horizontal-rounded'></i>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
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
            <a href="https://www.youtube.com/channel/UCWrutgBiaPK0vCk_pYxwGhw"><i class="bx bxl-youtube sosmed-icon"></i></a>
            <a href="https://www.facebook.com/rscitrahusadajember/"><i class="bx bxl-facebook sosmed-icon"></i></a>
            <a href="https://www.tiktok.com/@rscitrahusadajember?_t=ZS-8ssxXvGOz9G&_r=1"><i class="bx bxl-tiktok sosmed-icon"></i></a>
            <a href="https://www.instagram.com/rscitrahusadajember/"><i class="bx bxl-instagram sosmed-icon"></i></a>
            <a href="https://rscitrahusada.com/" ><i class='bx bxs-home sosmed-icon'></i></a>
          </ul>
        <div class="footer-section">
        <div class="map-container">
          <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1690.947685920273!2d113.6812076459426!3d-8.169000958621156!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2dd694127910c383%3A0x42956e9612f6b07a!2sCitra%20Husada%20Hospital!5e0!3m2!1sen!2sid!4v1750478359122!5m2!1sen!2sid" width="300" height="200" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>      </div>
        </div>
        </div>

        <div class="footer-section">
          <h3>Kontak Kami</h3>
          <p>(+62 331) 486200 ext: 142<br>08979049176</p>
          <p>Jalan Teratai No. 22, Patrang. Kab. Jember<br>Jawa Timur, Indonesia 68117</p>
        </div>
      </div>
    </footer>
    <footer>
      <div class="footer-bottom">
        <p>© Copyright IT Support Citra Husada.</p>
      </div>
    </footer>
  </div>

  <script src="script.js"></script>
  <script>
    function toggleUserDropdown() {
      document.getElementById('userMenu').classList.toggle('show');
    }
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
(function(){
  const btn   = document.getElementById('btnNotif');
  const panel = document.getElementById('notifPanel');
  const list  = document.getElementById('notifList');
  const badge = document.getElementById('notifBadge');

  async function fetchPanel() {
    list.innerHTML = '<div style="opacity:.6">Memuat...</div>';
    const r = await fetch('<?= basename(__FILE__) ?>?get_notif_panel=1', {credentials:'same-origin'});
    const data = await r.json();
    if (!Array.isArray(data) || data.length === 0) {
      list.innerHTML = '<div style="opacity:.6;padding:12px">Tidak ada notifikasi.</div>'; return;
    }
    list.innerHTML = data.map(it => `
      <a href="${it.action_url || '#'}" class="notif-item ${it.is_read==1?'':'unread'}" data-id="${it.id}">
        <div class="title">${it.title || '(tanpa judul)'}</div>
        <div class="body">${it.body || ''}</div>
        <div class="meta">${it.type || ''}</div>
      </a>`).join('');
  }

  function openPanel(){ panel.style.display='block'; fetchPanel().catch(()=>{ list.innerHTML='<div style="color:#b00020;padding:12px">Gagal memuat.</div>'; }); }
  function closePanel(){ panel.style.display='none'; }

  if (btn) btn.addEventListener('click', (e)=>{ e.stopPropagation(); (panel.style.display==='block')?closePanel():openPanel(); });
  document.addEventListener('click', (e)=>{ if (!panel.contains(e.target) && e.target!==btn) closePanel(); });

  list.addEventListener('click', async (e)=>{
    const item = e.target.closest('.notif-item'); if (!item) return;
    const id = item.getAttribute('data-id');
    try {
      await fetch('<?= basename(__FILE__) ?>', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({action:'read_notif', id})
      });
      if (item.classList.contains('unread') && badge){
        const now = parseInt(badge.textContent||'0',10)||0;
        const next = Math.max(now-1,0);
        badge.textContent = next;
        if (next===0) badge.style.display='none';
      }
      item.classList.remove('unread');
      // biarkan lanjut ke href (action_url). Jika mau tetap di halaman, tambahkan e.preventDefault() di sini dan buka modal sendiri.
    } catch(_){}
  });
})();
</script>

</body>
</html>
