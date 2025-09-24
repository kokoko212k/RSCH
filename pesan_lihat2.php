<style>
.chat-bubble {
  max-width: 60%;
  padding: 8px 12px;
  margin: 6px;
  border-radius: 12px;
  clear: both;
}
.chat-bubble.left {
  background: #f0f0f0;
  float: left;
}
.chat-bubble.right {
  background: #d1e7ff;
  float: right;
}
.chat-time {
  font-size: 0.75em;
  color: #666;
  margin-top: 4px;
}    
</style>
<?php
include 'config.php';
session_start();

$user        = $_SESSION['user'] ?? null;
$user_nama   = $user['nama']   ?? '';
$user_status = $user['status'] ?? '';
$no_surat    = $_GET['no_surat'] ?? ($_GET['id'] ?? null);

// Deteksi mode AJAX (fetch)
$isAjax = isset($_GET['ajax']) || 
          (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// (Opsional) pastikan PDO lempar exception (biar gak ada warning “bocor” ke output)
if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// POST: Hapus pesan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_pesan_id'])) {
    $hapusId     = (int) $_POST['hapus_pesan_id'];
    $hapusNoSurat= $_POST['no_surat'] ?? '';

    $stmtCheck = $pdo->prepare("SELECT pengirim FROM pesan WHERE id = ?");
    $stmtCheck->execute([$hapusId]);
    $pengirim = $stmtCheck->fetchColumn();

    if ($pengirim === $user_status || $pengirim === $user_nama) {
        $stmtDelete = $pdo->prepare("DELETE FROM pesan WHERE id = ?");
        $stmtDelete->execute([$hapusId]);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true]);
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

// GET: Notifikasi status → hanya tampilkan kalau BUKAN AJAX
if (!$isAjax && isset($_GET['status'])) {
    if ($_GET['status'] === 'success') {
        echo "<div class='notif success'>Pesan berhasil dikirim.</div>";
    } elseif ($_GET['status'] === 'error') {
        $msg = htmlspecialchars($_GET['msg'] ?? 'Terjadi kesalahan');
        echo "<div class='notif error'>$msg</div>";
    } elseif ($_GET['status'] === 'deleted') {
        echo "<div class='notif success'>Pesan berhasil dihapus.</div>";
    }
}

if (!$no_surat) {
    echo $isAjax ? '' : "<div class='notif error'>Nomor surat tidak ditemukan.</div>";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM pesan WHERE no_surat = ? ORDER BY waktu ASC");
$stmt->execute([$no_surat]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "<div class='notif'>Belum ada pesan untuk surat ini.</div>";
    exit;
}

foreach ($rows as $row) {
    $isSender    = ($row['pengirim'] === $user_status || $row['pengirim'] === $user_nama);
    $bubbleClass = $isSender ? 'chat-bubble right' : 'chat-bubble left';
    $label       = $isSender ? 'Anda' : htmlspecialchars($row['pengirim'] ?? '');
    $pesanTxt    = trim($row['pesan'] ?? '');
    $pesanSafe   = $pesanTxt !== '' ? nl2br(htmlspecialchars($pesanTxt)) : '<i>(Tidak ada isi pesan)</i>';
    $waktu       = date('d M Y H:i', strtotime($row['waktu']));
    ?>
    <div class="<?= $bubbleClass ?>">
        <strong><?= $label ?></strong><br>
        <div class="chat-text"><b>Pesan:</b> <?= $pesanSafe ?></div>
        <div class="chat-time"><?= $waktu ?></div>

        <?php if ($isSender): ?>
          <form method="post" data-ajax="delete" style="display:inline;">
            <input type="hidden" name="hapus_pesan_id" value="<?= (int)$row['id'] ?>">
            <input type="hidden" name="no_surat" value="<?= htmlspecialchars($no_surat, ENT_QUOTES) ?>">
            <button type="submit" style="background:none;border:none;color:red;cursor:pointer;">🗑️</button>
          </form>
          <button 
            onclick="editPesan(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['pesan'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($no_surat, ENT_QUOTES) ?>')" 
            style="background:none;border:none;color:blue;cursor:pointer;">
            ✏️
          </button>
        <?php endif; ?>
    </div>
    <?php
}
?>