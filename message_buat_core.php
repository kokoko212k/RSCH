<?php
// file: message_lihat_core.php
// WAJIB: $TABLE sudah di-set dari wrapper, contoh: $TABLE = 'pesan_1';
if (!isset($TABLE)) { http_response_code(500); exit('TABLE not set'); }

session_start();
require 'config.php';

if (!isset($_SESSION['user'])) { header("Location: login.php"); exit; }

$user         = $_SESSION['user'];
$user_nik     = isset($user['nik'])    ? $user['nik']    : null;
$user_nama    = isset($user['nama'])   ? $user['nama']   : '';
$user_status  = isset($user['status']) ? $user['status'] : '';
$user_unit    = trim((string)(isset($user['unit']) ? $user['unit'] : ''));

$no_surat = isset($_GET['no_surat']) ? $_GET['no_surat'] : (isset($_GET['id']) ? $_GET['id'] : null);

$isAjax = (isset($_GET['ajax']) ||
          (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));

if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// hapus pesan (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_pesan_id'])) {
  $hapusId      = (int) (isset($_POST['hapus_pesan_id']) ? $_POST['hapus_pesan_id'] : 0);
  $hapusNoSurat = isset($_POST['no_surat']) ? $_POST['no_surat'] : '';

  $stmtCheck = $pdo->prepare("SELECT pengirim_nik, pengirim_unit FROM {$TABLE} WHERE id = ?");
  $stmtCheck->execute(array($hapusId));
  $own = $stmtCheck->fetch(PDO::FETCH_ASSOC);

  $bolehHapus = false;
  if (!empty($own['pengirim_nik']) && !empty($user_nik)) {
      $bolehHapus = ($own['pengirim_nik'] === $user_nik);
  } else {
      $bolehHapus = (trim((string)$own['pengirim_unit']) === $user_unit);
  }

  if ($bolehHapus) {
    $stmtDelete = $pdo->prepare("DELETE FROM {$TABLE} WHERE id = ?");
    $stmtDelete->execute(array($hapusId));

    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array('ok'=>true)); exit; }
    header("Location: ?no_surat=" . urlencode($hapusNoSurat) . "&status=deleted"); exit;
  } else {
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8', true, 403); echo json_encode(array('ok'=>false,'error'=>'Akses ditolak')); exit; }
    header("Location: ?no_surat=" . urlencode($hapusNoSurat) . "&status=error&msg=Akses%20ditolak"); exit;
  }
}

if (!$no_surat) { echo $isAjax ? '' : "<div class='notif error'>Nomor surat tidak ditemukan.</div>"; exit; }

// cek partisipasi
$by_participation_full = 0;
$chk = $pdo->prepare("
  SELECT 1
  FROM {$TABLE}
  WHERE no_surat = :no_surat
    AND (
         pengirim_nik = :me_nik
      OR penerima_nik = :me_nik
      OR UPPER(TRIM(pengirim_unit)) = UPPER(TRIM(:me_unit))
      OR UPPER(TRIM(penerima_unit)) = UPPER(TRIM(:me_unit))
    )
  LIMIT 1
");
$chk->execute(array(
  ':no_surat' => $no_surat,
  ':me_nik'   => $user_nik,
  ':me_unit'  => $user_unit,
));
if ($chk->fetchColumn()) $by_participation_full = 1;

// role full access
$roles_full_access = array('Super Admin', 'Direktur', 'Sekretariat', 'Admin', 'Member');
$by_role_full      = in_array($user_status, $roles_full_access, true) ? 1 : 0;
$has_full_access   = ($by_role_full || $by_participation_full) ? 1 : 0;

// ambil pesan
$stmt = $pdo->prepare("
  SELECT *
  FROM {$TABLE}
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
$stmt->execute(array(
  ':no_surat' => $no_surat,
  ':me_nik'   => $user_nik,
  ':me_unit'  => $user_unit,
  ':full'     => $has_full_access,
));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AJAX render
if ($isAjax) {
  if (!$rows) { echo '<div class="notif">Belum ada pesan untuk surat ini.</div>'; exit; }
  $printed = array();
  foreach ($rows as $row) {
    $key = (isset($row['pengirim_nik']) ? $row['pengirim_nik'] : '') . '|' .
           trim((string)(isset($row['pesan']) ? $row['pesan'] : '')) . '|' .
           substr((string)(isset($row['waktu']) ? $row['waktu'] : ''), 0, 19);
    if (isset($printed[$key])) continue;
    $printed[$key] = true;

    $isSender   = (!empty($row['pengirim_nik']) && $row['pengirim_nik'] === $user_nik);
    $bubbleClass= $isSender ? 'chat-bubble right' : 'chat-bubble left';
    $labelSrc   = isset($row['pengirim_nama']) ? $row['pengirim_nama'] : (isset($row['pengirim_unit']) ? $row['pengirim_unit'] : '');
    $label      = $isSender ? 'Anda' : htmlspecialchars((string)$labelSrc, ENT_QUOTES, 'UTF-8');
    $pesanTxt   = trim((string)(isset($row['pesan']) ? $row['pesan'] : ''));
    $pesanSafe  = $pesanTxt !== '' ? nl2br(htmlspecialchars($pesanTxt, ENT_QUOTES, 'UTF-8')) : '<i>(Tidak ada isi pesan)</i>';
    $waktu      = date('d M Y H:i', strtotime(isset($row['waktu']) ? $row['waktu'] : 'now'));
    ?>
    <div class="<?= $bubbleClass ?>">
      <strong><?= $label ?></strong><br>
      <div class="chat-text"><?= $pesanSafe ?></div>
      <div class="chat-time"><?= $waktu ?></div>
      <?php if ($isSender): ?>
        <form method="post" style="display:inline;">
          <input type="hidden" name="hapus_pesan_id" value="<?= (int)$row['id'] ?>">
          <input type="hidden" name="no_surat" value="<?= htmlspecialchars($no_surat, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" style="background:none;border:none;color:red;cursor:pointer;">🗑️</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
  }
  exit;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Pesan</title>
<link rel="stylesheet" href="style.css">
<style>
.chat-box{display:flex;flex-direction:column;gap:10px;padding:15px;background:#fff;border-radius:8px;border:1px solid #eee}
.chat-bubble{max-width:60%;padding:10px 15px;border-radius:15px;position:relative;word-wrap:break-word}
.chat-bubble.left{background:#f1f0f0;align-self:flex-start;border-top-left-radius:0}
.chat-bubble.right{background:#cce5ff;align-self:flex-end;border-top-right-radius:0}
.chat-time{font-size:.75em;color:gray;margin-top:5px;text-align:right}
.send{display:flex;gap:10px;margin-top:10px}
.send textarea{flex:1;min-height:70px}
</style>
</head>
<body>
  <div class="wrap" style="max-width:900px;margin:30px auto">
    <a href="javascript:history.back()">← Kembali</a>
    <h2 style="margin:10px 0">Chat: <?= htmlspecialchars($no_surat, ENT_QUOTES) ?> (<?= htmlspecialchars($TABLE) ?>)</h2>
    <div id="chatBox" class="chat-box"><div style="opacity:.6">Memuat...</div></div>

    <form id="formSend" class="send" method="post" action="<?= htmlspecialchars(isset($SEND_ENDPOINT) ? $SEND_ENDPOINT : 'message_buat_core.php') ?>">
      <input type="hidden" name="no_surat" value="<?= htmlspecialchars($no_surat, ENT_QUOTES) ?>">
      <textarea name="pesan" placeholder="Ketik pesan..." required></textarea>
      <button type="submit">Kirim</button>
    </form>
  </div>

<script>
var noSurat  = <?= json_encode($no_surat) ?>;
var chatBox  = document.getElementById('chatBox');
var formSend = document.getElementById('formSend');

function loadChat(){
  chatBox.innerHTML = '<div style="opacity:.6">Memuat...</div>';
  var url = location.pathname + '?ajax=1&no_surat=' + encodeURIComponent(noSurat);
  fetch(url, { headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin' })
    .then(function(r){ return r.text(); })
    .then(function(html){
      chatBox.innerHTML = (html && html.trim())
        ? '<div class="chat-container">'+html+'</div>'
        : '<div style="opacity:.6">Belum ada pesan.</div>';
      chatBox.scrollTop = chatBox.scrollHeight;
    })
    .catch(function(err){
      chatBox.innerHTML = '<div style="color:#b00020">Gagal memuat: '+err.message+'</div>';
    });
}
formSend.addEventListener('submit', function(e){
  e.preventDefault();
  var fd = new FormData(formSend);
  var btn = formSend.querySelector('button');
  btn.disabled = true;
  fetch(formSend.action, { method:'POST', body:fd, credentials:'same-origin' })
    .then(function(r){ return r.text(); })
    .then(function(){ formSend.pesan.value=''; loadChat(); })
    .finally(function(){ btn.disabled=false; });
});
setInterval(loadChat, 10000);
loadChat();
</script>
</body>
</html>
