<?php
session_start();
include 'config.php';

// helper aman untuk HTML
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


// date_default_timezone_set('Asia/Jakarta');
$tanggal_hari_ini = date('Y-m-d');

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
$pdo->prepare("
    UPDATE surat_masuk 
    SET tanggal_diterima = :tanggal 
    WHERE tanggal_diterima IS NULL 
      AND file_url IN (SELECT file_url FROM surat_disposisi WHERE file_url IS NOT NULL)
")->execute(['tanggal' => $tanggal_hari_ini]);

$pdo->prepare("
    UPDATE surat_keluar 
    SET tanggal_diterima = :tanggal 
    WHERE tanggal_diterima IS NULL 
      AND file_url IN (SELECT file_url FROM surat_disposisi WHERE file_url IS NOT NULL)
")->execute(['tanggal' => $tanggal_hari_ini]);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['flash'])) {
    echo "<script>alert(" . json_encode($_SESSION['flash']) . ");</script>";
    unset($_SESSION['flash']);
}



unset($_SESSION['buka_disposisi']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_note'], $_POST['id'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $id  = (int) $_POST['id'];
    $note = trim(substr($_POST['note'] ?? '', 0, 255));

    $pdo->prepare("UPDATE surat_disposisi SET note = :note WHERE id = :id")
        ->execute(['note' => $note, 'id' => $id]);

    // sinkron ke surat_masuk / surat_keluar via file_url
    $surat = $pdo->prepare("SELECT file_url FROM surat_disposisi WHERE id = :id");
    $surat->execute(['id' => $id]);
    $file = $surat->fetchColumn();

    if ($file) {
        $pdo->prepare("UPDATE surat_masuk  SET note = :note WHERE file_url = :file_url")->execute(['note'=>$note,'file_url'=>$file]);
        $pdo->prepare("UPDATE surat_keluar SET note = :note WHERE file_url = :file_url")->execute(['note'=>$note,'file_url'=>$file]);
    }

    echo 'ok';
    exit();

}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['instruksi'], $_POST['id'])) {
    $id = (int) $_POST['id'];
    $instruksi = trim($_POST['instruksi'] ?? '');
    $note = trim(substr($_POST['note'] ?? '', 0, 255));
    $tanggal_disposisi = $tanggal_hari_ini;

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM surat_disposisi WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $dataSurat = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dataSurat) {
            $file_url = $dataSurat['file_url'];
            $no_surat = $dataSurat['no_surat'];
            $ditujukan_kepada = $dataSurat['ditujukan_kepada'] ?? '';

            if ($instruksi === '') {
                $pdo->prepare("UPDATE surat_disposisi SET note = :note WHERE id = :id")
                    ->execute(['note' => $note, 'id' => $id]);
                echo 'success';
                exit();
            }
            // pastikan $file_url terisi lebih dulu (pakai fallback bila kosong)
            if (!$file_url) {
                $q = $pdo->prepare("SELECT file_url FROM surat_masuk WHERE no_surat = ? ORDER BY id DESC LIMIT 1");
                $q->execute([$no_surat]);
                $file_url = $q->fetchColumn() ?: '';

                if (!$file_url) {
                    $q = $pdo->prepare("SELECT file_url FROM surat_keluar WHERE no_surat = ? ORDER BY id DESC LIMIT 1");
                    $q->execute([$no_surat]);
                    $file_url = $q->fetchColumn() ?: '';
                }

                if (!$file_url) {
                    $q = $pdo->prepare("SELECT file_url FROM surat_pengajuan WHERE no_perihal = ? ORDER BY id DESC LIMIT 1");
                    $q->execute([$no_surat]);
                    $file_url = $q->fetchColumn() ?: '';
                }
            }

            // update instruksi + note + status
            $pdo->prepare("
                UPDATE surat_disposisi 
                SET instruksi = :instruksi,
                    note = :note,
                    status_disposisi = 'Telah Diproses'
                WHERE id = :id
            ")->execute([
                'instruksi' => $instruksi,
                'note'      => $note,
                'id'        => $id
            ]);

            // sinkron INSTRUKSI ke surat_masuk/keluar
            foreach (['surat_masuk', 'surat_keluar'] as $table) {
                $pdo->prepare("UPDATE $table SET instruksi = :instruksi WHERE file_url = :file_url")
                    ->execute(['instruksi' => $instruksi, 'file_url' => $file_url]);
            }

            // 🔧 FIX bug: sinkron NOTE (sebelumnya query & bind keliru)
            foreach (['surat_masuk', 'surat_keluar'] as $table) {
                $pdo->prepare("UPDATE $table SET note = :note WHERE file_url = :file_url")
                    ->execute(['note' => $note, 'file_url' => $file_url]);
            }

            // Normalisasi supaya bisa match "uploads/foo.pdf" vs "foo.pdf"
            $fu = trim((string)$file_url);
            $bn = $fu ? basename($fu) : ''; // "foo.pdf" saja

            foreach (['surat_masuk', 'surat_keluar'] as $table) {
              $stmt = $pdo->prepare("
                UPDATE $table 
                  SET tanggal_disposisi = :tgl
                WHERE ( (file_url = :fu)
                      OR (file_url = :bn)
                      OR (CONCAT('uploads/', file_url) = :fu)
                      OR (REPLACE(file_url, 'uploads/', '') = :bn) )
                  AND (tanggal_disposisi IS NULL OR tanggal_disposisi = '')
              ");
              $stmt->execute([
                'tgl' => $tanggal_disposisi,
                'fu'  => $fu,
                'bn'  => $bn,
              ]);
            }



            // normalisasi SAMA seperti yang dipakai tabel lain
            $stored_file = $file_url;
            if ($stored_file && strpos($stored_file, '/') === false) {
                $stored_file = 'uploads/' . $stored_file;
            }


            // Normalisasi instruksi: trim + ratakan spasi (termasuk NBSP) + case-insensitive
            $instruksi_norm = preg_replace('/\s+/u', ' ', trim($instruksi));
            $instruksi_norm = mb_convert_case($instruksi_norm, MB_CASE_TITLE, 'UTF-8');
            // --- Normalisasi & helper untuk matching file_url ---
            // $file_url sudah dipastikan terisi sebelumnya (fallback no_surat -> masuk/keluar/pengajuan)
            $fu = trim((string)$file_url);            // bisa "uploads/a.pdf" atau "a.pdf" atau "uploads/a.pdf,uploads/b.pdf"
            $bn = $fu !== '' ? basename($fu) : '';    // "a.pdf" saja

            // versi full berprefix 'uploads/' jika perlu
            $fu_full = ($fu !== '' && strpos($fu, '/') === false) ? ('uploads/' . $fu) : $fu;

            // pattern LIKE (pakai %..%)
            $like_fu       = '%' . $fu . '%';
            $like_bn       = '%' . $bn . '%';
            $like_fu_full  = '%' . $fu_full . '%';

            // versi "bersih" tanpa spasi untuk FIND_IN_SET pada kolom yang berisi list dipisah koma
            $fu_clean = str_replace(' ', '', ($fu_full !== '' ? $fu_full : $fu));
            $bn_clean = str_replace(' ', '', $bn);

            switch ($instruksi_norm) {
            case 'Diteruskan':
              // cek apakah no_surat ini berasal dari SURAT KELUAR (ada ditujukan_kepada di disposisi)
              $cekKeluar = $pdo->prepare("
                  SELECT file_url 
                  FROM surat_keluar 
                  WHERE no_surat = :no_surat 
                    AND (:tujuan = '' OR ditujukan_kepada = :tujuan)
                  ORDER BY id DESC LIMIT 1
              ");
              $cekKeluar->execute([
                  'no_surat' => $no_surat,
                  'tujuan'   => trim((string)$ditujukan_kepada)
              ]);
              $fileKeluar = $cekKeluar->fetchColumn();

              if ($fileKeluar !== false) {
                  // SUMBER = SURAT KELUAR → masukkan ke NOTIF (perlakuan sama seperti "Diteruskan Langsung")
                  $stored = $fileKeluar;
                  if ($stored && strpos($stored, '/') === false) $stored = 'uploads/' . $stored;

                  // (opsional) cegah dobel
                  $cek = $pdo->prepare("SELECT COUNT(*) FROM surat_notif WHERE no_surat = ? AND file_url = ?");
                  $cek->execute([$no_surat, $stored]);
                  if ($cek->fetchColumn() == 0) {
                      $stmtNotif = $pdo->prepare("
                          INSERT INTO surat_notif (tanggal, no_surat, file_url, waktu)
                          VALUES (:tanggal, :no_surat, :file_url, NOW())
                      ");
                      $stmtNotif->execute([
                          'tanggal'  => $tanggal_disposisi,
                          'no_surat' => $no_surat,
                          'file_url' => $stored,
                      ]);
                  }

                  echo 'success-redirect-notif'; // JS akan redirect ke surat_notif.php
                  exit;

              } else {
                  // BUKAN surat_keluar → tetap ke TINDAK LANJUT seperti biasa
                  $stmt = $pdo->prepare("
                    INSERT INTO surat_disposisi_tindak_lanjut (tanggal, no_surat, file_url)
                    VALUES (?, ?, ?)
                  ");
                  $stmt->execute([$tanggal_disposisi, $no_surat, $file_url]);
                  echo 'success-redirect-tindak';
                  exit;
              }

              case 'Diteruskan Langsung':
                // isi tanggal_disposisi di surat_keluar (jaga-jaga semua variasi file_url)
                $sql = "
                  UPDATE surat_keluar
                    SET tanggal_disposisi = :tgl
                  WHERE (
                          file_url = :fu
                      OR file_url = :bn
                      OR CONCAT('uploads/', file_url) = :fu
                      OR REPLACE(file_url,'uploads/','') = :bn
                      OR file_url LIKE :like_fu
                      OR file_url LIKE :like_bn
                      OR file_url LIKE :like_fu_full
                      OR FIND_IN_SET(:fu_clean, REPLACE(file_url,' ','')) > 0
                      OR FIND_IN_SET(:bn_clean, REPLACE(REPLACE(file_url,'uploads/',''),' ','')) > 0
                        )
                    AND (tanggal_disposisi IS NULL OR tanggal_disposisi = '')
                ";
                $pdo->prepare($sql)->execute([
                  'tgl'         => $tanggal_disposisi,
                  'fu'          => $fu,
                  'bn'          => $bn,
                  'like_fu'     => $like_fu,
                  'like_bn'     => $like_bn,
                  'like_fu_full'=> $like_fu_full,
                  'fu_clean'    => $fu_clean,
                  'bn_clean'    => $bn_clean,
                ]);

                // Notifikasi (pakai $stored_file yang sudah dipastikan berprefix 'uploads/')
                $stmtNotif = $pdo->prepare("
                  INSERT INTO surat_notif (tanggal, no_surat, file_url, waktu)
                  VALUES (:tanggal, :no_surat, :file_url, NOW())
                ");
                $stmtNotif->execute([
                  'tanggal'  => $tanggal_disposisi,
                  'no_surat' => $no_surat,
                  'file_url' => $stored_file,
                ]);

                echo 'success-redirect-notif';
                exit;


              default:
                echo 'success';
                exit;
            }

            $stmtNotif = $pdo->prepare("
                INSERT INTO surat_notif (tanggal, no_surat, file_url, waktu)
                VALUES (:tanggal, :no_surat, :file_url, NOW())
            ");
            $stmtNotif->execute([
                'tanggal'  => $tanggal_disposisi,
                'no_surat' => $no_surat,
                'file_url' => $stored_file,
            ]);

            echo 'success';
            exit();
        }
    }
    echo 'invalid';
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM surat_disposisi WHERE id = :id");
    $stmt->execute(['id' => $id]);
    header("Location: surat_disposisi.php");
    exit();
}


$no_surat_input = $_POST['no_surat'] ?? '';
$tujuan_input   = $_POST['ditujukan_kepada'] ?? null;

/* INSERT/UPDATE dari surat_disposisi_pengajuan → surat_disposisi*/
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['insert_from_disposisi_pengajuan'], $_POST['id'])) {

    $dspId = (int)$_POST['id'];

    // 1) Ambil sumber
    $q = $pdo->prepare("SELECT * FROM surat_disposisi_pengajuan WHERE id = :id LIMIT 1");
    $q->execute(['id' => $dspId]);
    $dsp = $q->fetch(PDO::FETCH_ASSOC);

    if (!$dsp) {
        $_SESSION['flash'] = "Data Disposisi Pengajuan tidak ditemukan.";
        header("Location: surat_disposisi.php");
        exit();
    }

    // 2) Normalisasi file_url → pastikan berprefix 'uploads/'
    $file_raw   = (string)($dsp['file_url'] ?? '');
    $file_base  = basename($file_raw);
    $file_store = (strpos($file_raw, 'uploads/') === 0) ? $file_raw : ('uploads/'.$file_base);

    // 3) (Opsional) ambil pelengkap dari surat_pengajuan bila ada
    $src = $pdo->prepare("
        SELECT *
          FROM surat_pengajuan
         WHERE file_url = :file_raw
            OR file_url = :file_base
            OR TRIM(no_surat) = TRIM(:no)
            OR TRIM(no_perihal) = TRIM(:no)
         ORDER BY id DESC
         LIMIT 1
    ");
    $src->execute([
        'file_raw'  => $file_raw,
        'file_base' => $file_base,
        'no'        => $dsp['no_surat'] ?? ''
    ]);
    $sp = $src->fetch(PDO::FETCH_ASSOC) ?: [];

    // 4) Siapkan nilai target untuk surat_disposisi
    $tanggal            = $dsp['tanggal'] ?? $sp['tanggal'] ?? date('Y-m-d');
    $no_surat           = trim($dsp['no_surat'] ?? $sp['no_surat'] ?? $sp['no_perihal'] ?? '');
    $ditujukan_kepada   = trim($sp['ditujukan_kepada'] ?? ''); // jika ada di pengajuan
    $arahan_src         = trim($dsp['arahan'] ?? '');           // contoh: "Disetujui", "Ditolak", dll.
    $map = [
        'Disetujui' => ['arahan' => 'Diterima', 'status' => 'Telah Diproses'],
        'Ditolak'   => ['arahan' => 'Ditolak',  'status' => 'Telah Diproses'],
    ];
    $key = mb_strtolower($arahan_src, 'UTF-8');
    $arahan_disposisi  = $map[$key]['arahan'] ?? '';
    $status_disposisi  = $map[$key]['status']    ?? 'Belum Diproses';

    // 5) Cek existing di surat_disposisi (hindari dobel)
    $cek = $pdo->prepare("
        SELECT id
          FROM surat_disposisi
         WHERE file_url = :file_raw
            OR file_url = :file_base
            OR TRIM(no_surat) = TRIM(:no)
         ORDER BY id DESC
         LIMIT 1
    ");
    $cek->execute([
        'file_raw'  => $file_raw,
        'file_base' => $file_base,
        'no'        => $no_surat
    ]);
    $existingId = $cek->fetchColumn();

    if ($existingId) {
        // 6a) UPDATE idempotent (isi yang kosong / netralkan nilai default)
        $upd = $pdo->prepare("
            UPDATE surat_disposisi
               SET tanggal           = COALESCE(NULLIF(tanggal,''), :tanggal),
                   no_surat          = COALESCE(NULLIF(no_surat,''), :no_surat),
                   ditujukan_kepada  = COALESCE(NULLIF(ditujukan_kepada,''), :tujuan),
                   file_url          = COALESCE(NULLIF(file_url,''), :file_url),
                   -- hanya isi arahan jika masih kosong (biar tidak timpa hasil kerja user)
                   arahan         = CASE WHEN (arahan IS NULL OR arahan = '') THEN :arahan ELSE arahan END,
                   status_disposisi  = CASE 
                                         WHEN (status_disposisi IS NULL OR status_disposisi = '' OR status_disposisi='Belum Diproses')
                                         THEN :status
                                         ELSE status_disposisi
                                       END
             WHERE id = :id
             LIMIT 1
        ");
        $upd->execute([
            'tanggal' => $tanggal,
            'no_surat'=> $no_surat,
            'tujuan'  => $ditujukan_kepada,
            'file_url'=> $file_store,
            'arahan'  => $arahan_disposisi,
            'status'   => $status_disposisi,
            'id'       => $existingId,
        ]);

        $_SESSION['flash'] = "Disposisi diperbarui dari Disposisi Pengajuan (ID #{$dspId}).";
    } else {
        // 6b) INSERT baru
        $ins = $pdo->prepare("
            INSERT INTO surat_disposisi
                (tanggal, no_surat, ditujukan_kepada, arahan, status_disposisi, file_url, note)
            VALUES
                (:tanggal, :no_surat, :tujuan, :arahan, :status, :file_url, '')
        ");
        $ins->execute([
            'tanggal'   => $tanggal,
            'no_surat'  => $no_surat,
            'tujuan'    => $ditujukan_kepada,
            'arahan'    => $arahan_disposisi,
            'status'    => $status_disposisi,
            'file_url'  => $file_store,
        ]);

        // $_SESSION['flash'] = "Disposisi ditambahkan dari Disposisi Pengajuan (ID #{$dspId}).";
    }

    // 7) Tandai UI & redirect
    $_SESSION['buka_disposisi'] = true;
    header("Location: surat_disposisi.php");
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cari_surat'])) {
    $tanggal = $no_surat = $ditujukan_kepada = $file_url = $sumber_surat = '';

    // 🔎 Cari di surat_masuk (tidak ada kolom ditujukan_kepada)
    $stmt = $pdo->prepare("SELECT * FROM surat_masuk WHERE no_surat = :no_surat");
    $stmt->execute(['no_surat' => $no_surat_input]);
    $data = $stmt->fetch();

    if ($data) {
        $tanggal = $data['tanggal_surat'];
        $no_surat = $data['no_surat'];
        $file_url = $data['file_url'];
        $sumber_surat = 'Surat Masuk';

        // update tanggal_diterima kalau kosong
        if (empty($data['tanggal_diterima'])) {
            $pdo->prepare("UPDATE surat_masuk SET tanggal_diterima = :tgl WHERE no_surat = :no")
                ->execute(['tgl' => $tanggal_hari_ini, 'no' => $no_surat]);
        }
    } else {
        // 🔎 Cari di surat_keluar (punya kolom ditujukan_kepada)
        $stmt = $pdo->prepare("SELECT * FROM surat_keluar WHERE no_surat = :no_surat AND ditujukan_kepada = :ditujukan_kepada");
        $stmt->execute([
            'no_surat' => $no_surat_input,
            'ditujukan_kepada' => $tujuan_input
        ]);
        $data = $stmt->fetch();

        if ($data) {
            $tanggal = $data['tanggal'];
            $no_surat = $data['no_surat'];
            $ditujukan_kepada = $data['ditujukan_kepada'];
            $file_url = $data['file_url'];
            $sumber_surat = 'Surat Keluar';

            if (empty($data['tanggal_diterima'])) {
                $pdo->prepare("UPDATE surat_keluar SET tanggal_diterima = :tgl WHERE no_surat = :no")
                    ->execute(['tgl' => $tanggal_hari_ini, 'no' => $no_surat]);
            }
        } else {
            // 🔎 Cari di surat_pengajuan (punya kolom ditujukan_kepada)
            $stmt = $pdo->prepare("SELECT * FROM surat_pengajuan WHERE no_perihal = :no_surat AND ditujukan_kepada = :ditujukan_kepada");
            $stmt->execute([
                'no_surat' => $no_surat_input,
                'ditujukan_kepada' => $tujuan_input
            ]);
            $data = $stmt->fetch();

            if ($data) {
                $tanggal = $data['tanggal'];
                $no_surat = $data['no_perihal'];
                $ditujukan_kepada = $data['ditujukan_kepada'];
                $file_url = $data['file_url'];
                $sumber_surat = 'Surat Pengajuan';
            }
        }
    }

    // ✅ kalau noteemu salah satu sumber surat, simpan ke disposisi
    if ($tanggal !== '') {
        if ($file_url && strpos($file_url, '/') === false) {
            $file_url = 'uploads/' . $file_url;
        }
        $stmt = $pdo->prepare("
            INSERT INTO surat_disposisi (tanggal, no_surat, ditujukan_kepada, instruksi, status_disposisi, file_url) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tanggal, $no_surat, $ditujukan_kepada, '', 'Belum Diproses', $file_url]);


        $_SESSION['buka_disposisi'] = true;
        $_SESSION['flash'] = "Data berhasil ditambahkan dari $sumber_surat ke disposisi.";
        header('Location: surat_masuk.php');
        exit();
    }
}
$stmt = $pdo->query("SELECT * FROM surat_disposisi ORDER BY id DESC");
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
$opsTanggal = [];
$opsNoSurat = [];
$opsTujuan  = [];
$opsInstr   = [];
$opsStatus  = [];

foreach ($result as $r) {
  // tanggal simpan dalam format YYYY-MM-DD (mudah dibandingkan)
  if (!empty($r['tanggal']))       $opsTanggal[$r['tanggal']] = true;
  if (!empty($r['no_surat']))      $opsNoSurat[$r['no_surat']] = true;
  if (!empty($r['ditujukan_kepada'])) $opsTujuan[$r['ditujukan_kepada']] = true;

  // normalisasi instruksi kosong / 'Belum Diproses' => 'Belum'
  $instr = trim((string)($r['instruksi'] ?? ''));
  $instrNorm = ($instr === '' || strcasecmp($instr, 'Belum Diproses') === 0) ? 'Belum' : $instr;
  if ($instrNorm !== '') $opsInstr[$instrNorm] = true;

  if (!empty($r['status_disposisi'])) $opsStatus[$r['status_disposisi']] = true;
}

$opsNoSurat = array_keys($opsNoSurat); sort($opsNoSurat, SORT_NATURAL);
$opsTujuan  = array_keys($opsTujuan);  sort($opsTujuan,  SORT_NATURAL);

// urutan instruksi yang umum dipakai
$urutanInstr = ['Belum', 'Diterima', 'Diteruskan', 'Diteruskan Langsung', 'Ditolak'];
$opsInstr    = array_values(array_unique(array_merge($urutanInstr, array_keys($opsInstr))));
$opsStatus   = array_keys($opsStatus); sort($opsStatus, SORT_NATURAL);

$jumlahNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifikasi")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Surat Disposisi</title>
  <link rel="stylesheet" href="style.css" />
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'> <!-- untuk ikon footer -->
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
    width: 20px; 
    font-size: 14px;
    padding: 1px;
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

  
  #f-tanggal {
    width: 20px;      /* ubah sesuai maumu */
    display: inline-block;
    box-sizing: border-box;
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

  /* .form-group input[type="date"] {
    width: 10%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 16px;
    height: 10px;
  } */
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

.dropdown-instruksi {
    width: 19px;
    padding: 5px;
}

/* kolom Note rapi dan tombol tidak ketimpa */
.note-cell { min-width: 360px; }                 /* kasih lebar minimum kolom Note */
.note-wrap {
  display: grid;                                  /* grid lebih stabil di dalam <table> */
  grid-template-columns: 1fr auto;                /* textarea | tombol */
  gap: 8px;
  align-items: start;
}
.note-wrap textarea {
  width: 100%;                                    /* isi kolom yang tersedia */
  min-height: 60px;
  resize: vertical;
  box-sizing: border-box;
}
.btn-save-note {
  padding: 6px 10px;
  border: none;
  border-radius: 6px;
  background: #3f98f7ff;
  color: #fff;
  white-space: nowrap;                            /* teks tombol tidak terpotong */
  cursor: pointer;
}
.btn-save-note[disabled] { opacity: .5; cursor: not-allowed; }

.note-status { font-size: 12px; color: rgba(44, 61, 243, 1); display: inline-block; margin-top: 4px; }

/* Responsif: kalau layar sempit, tumpuk ke bawah */
@media (max-width: 768px) {
  .note-cell { min-width: 240px; }
  .note-wrap { grid-template-columns: 1fr; }      /* textarea di atas, tombol di bawah */
  .btn-save-note { width: 100%; }
}
.export-dropdown { position: relative; display: inline-block; margin-bottom: 10px; }
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
/* tombol chat di kolom Pesan */
.btn-chat{ background:#eef4ff; border:1px solid #cfe0ff; padding:6px 10px; border-radius:6px; cursor:pointer; }
.chat-container{ display:flex; flex-direction:column; gap:10px; padding:10px; }
.chat-bubble{ max-width:60%; padding:10px 14px; border-radius:14px; margin-bottom:8px; }
.chat-bubble.left{ background:#f3f4f6; border-top-left-radius:4px; }
.chat-bubble.right{ background:#dbeafe; border-top-right-radius:4px; margin-left:auto; }
.chat-time{ font-size:.75em; color:#6b7280; margin-top:4px; text-align:right; }

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
  <h2 class="judul-surat-luar">Disposisi Surat</h2>
  <div class="kontainer-balok">
      <!-- Balok 1: Tombol tambah disposisi -->
      <div class="export-dropdown">
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
              <button>Cari</button>
          </div>
      </div>
      <!-- Balok 3: Tabel Data Disposisi -->
      <div class="balok-3">
          <table id="tabelSuratDisposisi" border="1" cellpadding="10" cellspacing="0">
              <tr>
                <th>
                  Tanggal
                  <div class="no-export" style="margin-top:6px">
                    <input type="date" id="f-tanggal"
                          onchange="applyFilters(); showResetButton();" />
                  </div>
                </th>
                <th>
                  No. Surat
                  <div class="no-export" style="margin-top:6px">
                    <select id="f-no_surat" onchange="applyFilters(); showResetButton();">
                      <option value=""></option>
                      <?php foreach ($opsNoSurat as $v): ?>
                        <option value="<?= h($v) ?>"><?= h($v) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </th>
                <th>
                  Ditujukan Kepada
                  <div class="no-export" style="margin-top:6px">
                    <select id="f-ditujukan" onchange="applyFilters(); showResetButton();">
                      <option value=""></option>
                      <?php foreach ($opsTujuan as $v): ?>
                        <option value="<?= h($v) ?>"><?= h($v) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </th>
                <th>
                  Instruksi
                  <div class="no-export" style="margin-top:6px">
                    <select id="f-instruksi" onchange="applyFilters(); showResetButton();">
                      <option value=""></option>
                      <?php foreach ($opsInstr as $v): ?>
                        <option value="<?= h($v) ?>"><?= h($v) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </th>
                <th>Note</th>
                <th>
                  Status
                  <div class="no-export" style="margin-top:6px">
                    <select id="f-status" onchange="applyFilters(); showResetButton();">
                      <option value=""></option>
                      <?php foreach ($opsStatus as $v): ?>
                        <option value="<?= h($v) ?>"><?= h($v) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </th>
                <th>File</th>
                <th>Pesan</th>
                <th>Aksi</th>
              </tr>
              <?php foreach ($result as $row): ?>
              <?php
                $instrRaw  = trim((string)($row['instruksi'] ?? ''));
                $instrNorm = ($instrRaw === '' || strcasecmp($instrRaw, 'Belum Diproses') === 0) ? 'Belum' : $instrRaw;
                $statusRaw = trim((string)($row['status_disposisi'] ?? ''));
                $tglRaw    = trim((string)($row['tanggal'] ?? ''));
              ?>
              <tr id="row-<?= (int)$row['id'] ?>"
                  class="data-row"
                  data-tanggal="<?= h($tglRaw) ?>"
                  data-no_surat="<?= h($row['no_surat'] ?? '') ?>"
                  data-ditujukan_kepada="<?= h($row['ditujukan_kepada'] ?? '') ?>"
                  data-instruksi="<?= h($instrNorm) ?>"
                  data-status="<?= h($statusRaw) ?>">
                  <td><?= h($row['tanggal']) ?></td>
                  <td><?= h($row['no_surat']) ?></td>
                  <td><?= h($row['ditujukan_kepada'] ?? '-') ?></td>
                  <td>
                      <span id="label-instruksi-<?= $row['id'] ?>">
                          <?= h($row['instruksi']) ?>
                      </span>
                      <select id="sel-<?= $row['id'] ?>" class="dropdown-instruksi"
                              onchange="updateInstruksi(<?= $row['id'] ?>, this.value)">                      
                          <option value="">Belum Diproses</option>
                          <option value="Diterima" <?= $row['instruksi'] == 'Diterima' ? 'selected' : '' ?>>Diterima</option>
                          <option value="Diteruskan" <?= $row['instruksi'] == 'Diteruskan' ? 'selected' : '' ?>>Diteruskan</option>
                          <option value="Diteruskan Langsung" <?= $row['instruksi'] == 'Diteruskan Langsung' ? 'selected' : '' ?>>Diteruskan Langsung</option>
                          <option value="Ditolak" <?= $row['instruksi'] == 'Ditolak' ? 'selected' : '' ?>>Ditolak</option>
                      </select>
                  </td>
                  <td class="note-cell">
                    <div class="note-wrap">
                      <textarea
                        id="note-<?= (int)$row['id'] ?>"
                        placeholder="..."
                        oninput="toggleSaveBtn(<?= (int)$row['id'] ?>)"
                        data-original="<?= h($row['note'] ?? '') ?>"
                      ><?= h($row['note'] ?? '') ?></textarea>

                      <button
                        id="btn-save-<?= (int)$row['id'] ?>"
                        type="button"
                        class="btn-save-note"
                        onclick="saveNote(<?= (int)$row['id'] ?>)"
                        disabled
                      >Simpan</button>
                    </div>
                    <span id="note-status-<?= (int)$row['id'] ?>" class="note-status"></span>
                  </td>
                  <td id="status-<?= $row['id'] ?>"><?= h($row['status_disposisi']) ?></td>
                  <td><a href="<?= $row['file_url'] ?>" target="_blank"><?= basename($row['file_url']) ?></a></td>
                  <td>
                    <?php if (!empty($row['ditujukan_kepada'])): ?>
                      <a class="btn-chat"
                        href="pesan_lihat_5.php?no_surat=<?= urlencode($row['no_surat']) ?>"
                        title="Buka halaman pesan untuk <?= htmlspecialchars($row['no_surat']) ?>">
                        💬
                      </a>
                    <?php else: ?>
                      <button class="btn-chat" disabled title="Isi 'Ditujukan Kepada' dulu">💬</button>
                    <?php endif; ?>
                  </td>
                  <td>
                      <a href="update_surat_disposisi.php?id=<?= $row['id'] ?>">✏️</a><br>
                      <a href="surat_disposisi.php?delete=<?= $row['id'] ?>" onclick="return confirm('Hapus disposisi ini?')">🗑️</a>
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
window.exportTableToExcel = function (last3Months) {
  if (typeof XLSX === 'undefined') { alert('Library XLSX belum ter-load.'); return; }

  const src = document.getElementById('tabelSuratDisposisi');
  if (!src) { alert('Tabel tidak ditemukan'); return; }

  // clone agar DOM asli tak berubah
  const table = src.cloneNode(true);

  // buang kontrol UI yang tak perlu ikut ekspor
  table.querySelectorAll('.no-export, select, form, button').forEach(el => el.remove());

  // --- FILTER 3 BULAN TERAKHIR (berdasarkan data-tanggal) ---
  function parseFlexibleDate(str){
    if (!str) return null;
    str = String(str).trim();
    if (str.length >= 10) str = str.slice(0,10);       // potong waktu kalau ada

    // YYYY-MM-DD
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      const [y,m,d] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;
    }
    // DD-MM-YYYY
    if (/^\d{2}-\d{2}-\d{4}$/.test(str)) {
      const [d,m,y] = str.split('-').map(Number);
      const dt = new Date(y, m-1, d);
      return isNaN(dt.getTime()) ? null : dt;
    }
    return null;
  }

  if (last3Months) {
    const today = new Date();
    const start = new Date(today.getTime() - 90*24*60*60*1000); // 90 hari ke belakang
    table.querySelectorAll('tr.data-row').forEach(tr => {
      const raw = tr.getAttribute('data-tanggal') || '';
      const dt  = parseFlexibleDate(raw);
      if (!dt || dt < start || dt > today) tr.remove(); // buang yang di luar range
    });
  }

  // buang kolom "File" & "Aksi" dari hasil ekspor
  const header = table.querySelector('tr');
  const ths = Array.from(header.children);
  const removeIdx = ths
    .map((th, i) => [ (th.textContent||'').trim().split('\n')[0], i ])
    .filter(([t]) => t === 'File' || t === 'Pesan'|| t === 'Aksi')
    .map(([, i]) => i);

  table.querySelectorAll('tr').forEach(tr => {
    Array.from(tr.children).forEach((td, i) => { if (removeIdx.includes(i)) td.remove(); });
  });

  // buat workbook
  const wb = XLSX.utils.table_to_book(table, { sheet: 'surat disposisi' });
  const ws = wb.Sheets['surat disposisi'];

  // lebar kolom otomatis kasar
  const firstRow = XLSX.utils.sheet_to_json(ws, { header: 1 })[0] || [];
  ws['!cols'] = firstRow.map(h => ({ wch: Math.min(Math.max(String(h||'').length + 2, 12), 40) }));

  XLSX.writeFile(wb, `surat_disposisi${last3Months ? '_3bulan' : '_all'}.xlsx`);

  // tutup dropdown
  document.querySelector('.export-dropdown')?.classList.remove('open');
};

// Normalisasi untuk keamanan perbandingan
function norm(s){ return (s||'').toString().trim().toLowerCase(); }

// Ambil nilai semua kontrol header
function getFilterValues(){
  return {
    tanggal:   (document.getElementById('f-tanggal')?.value || ''), // YYYY-MM-DD
    no_surat:  norm(document.getElementById('f-no_surat')?.value || ''),
    ditujukan: norm(document.getElementById('f-ditujukan')?.value || ''),
    instruksi: norm(document.getElementById('f-instruksi')?.value || ''),
    status:    norm(document.getElementById('f-status')?.value || '')
  };
}

function applyFilters(){
  const fv = getFilterValues();
  const rows = document.querySelectorAll('.data-row');

  rows.forEach(row => {
    let show = true;

    // Tanggal: cocokkan persis YYYY-MM-DD
    if (fv.tanggal) {
      const t = row.getAttribute('data-tanggal') || '';
      if (t !== fv.tanggal) show = false;
    }

    // no_surat
    if (show && fv.no_surat) {
      const v = norm(row.getAttribute('data-no_surat'));
      if (!v.includes(fv.no_surat)) show = false;
    }

    // ditujukan_kepada
    if (show && fv.ditujukan) {
      const v = norm(row.getAttribute('data-ditujukan_kepada'));
      if (!v.includes(fv.ditujukan)) show = false;
    }

    // instruksi (pakai instruksi ter-normalisasi: 'Belum', 'Diterima', ...)
    if (show && fv.instruksi) {
      const v = norm(row.getAttribute('data-instruksi'));
      if (v !== fv.instruksi) show = false;
    }

    // status
    if (show && fv.status) {
      const v = norm(row.getAttribute('data-status'));
      if (v !== fv.status) show = false;
    }

    row.style.display = show ? 'table-row' : 'none';
  });
}

// Tampilkan/ sembunyikan tombol reset
function showResetButton(){
  const has =
    (document.getElementById('f-tanggal')?.value || '').trim() !== '' ||
    (document.getElementById('f-no_surat')?.value || '').trim() !== '' ||
    (document.getElementById('f-ditujukan')?.value || '').trim() !== '' ||
    (document.getElementById('f-instruksi')?.value || '').trim() !== '' ||
    (document.getElementById('f-status')?.value || '').trim() !== '' ||
    (document.getElementById('searchInput')?.value || '').trim() !== '';

  const rc = document.getElementById('reset-container');
  if (rc) rc.style.display = has ? 'block' : 'none';
}

// Reset semua filter + tampilkan semua baris
function resetFilters(){
  const ids = ['f-tanggal','f-no_surat','f-ditujukan','f-instruksi','f-status','searchInput'];
  ids.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  document.querySelectorAll('.data-row').forEach(r => r.style.display = 'table-row');
  showResetButton();
}

// Integrasikan dengan Search
function searchTable(){
  const input = norm(document.getElementById("searchInput")?.value || '');
  const rows = document.querySelectorAll("#tabelSuratDisposisi .data-row");

  rows.forEach(row => {
    const cells = row.querySelectorAll("td");
    let found = false;
    cells.forEach(cell => {
      if (norm(cell.textContent).includes(input)) found = true;
    });
    // gabungkan dengan hasil filter lain: kalau sudah "none", biarkan none
    if (row.style.display !== 'none') row.style.display = found ? 'table-row' : 'none';
  });

  showResetButton();
}

// Saat instruksi di BARIS diubah → sinkronkan data-* supaya filter tetap akurat
function afterRowInstruksiChanged(id, instruksi){
  const tr = document.getElementById('row-' + id);
  if (!tr) return;
  const normVal = (!instruksi || instruksi.toLowerCase() === 'belum diproses') ? 'Belum' : instruksi;
  tr.dataset.instruksi = normVal;
}

document.addEventListener('DOMContentLoaded', () => {
  // panggil sekali untuk set state tombol reset
  showResetButton();
});


// function updateInstruksi(id, instruksi) {
//     if (instruksi === 'Belum') return;

//     fetch('surat_disposisi.php', {
//         method: 'POST',
//         headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
//         body: `id=${id}&instruksi=${instruksi}`
//     })
//     .then(response => response.text())
//     .then(data => {
//         data = data.trim();   // ← bersihin
//         if (data === 'success') {
//             // update tampilan biasa
//             document.getElementById('label-instruksi-' + id).innerText = instruksi; 
//             document.getElementById('status-' + id).innerText = 'Telah Diproses';   
//         } 
//         else if (data === 'success-redirect') {
//             // update tampilan + redirect ke tindak lanjut
//             document.getElementById('label-instruksi-' + id).innerText = instruksi; 
//             document.getElementById('status-' + id).innerText = 'Telah Diproses';   
//             window.location.href = 'surat_disposisi_tindak_lanjut.php?id=' + id;
//         } 
//         else {
//             alert('Gagal memperbarui data.');
//         }
//     });
// }

function saveKet(id, note) {
  fetch('surat_disposisi.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `save_note=1&id=${encodeURIComponent(id)}&note=${encodeURIComponent(note || '')}`
  })
  .then(r => r.text())
  .then(t => {
    const clean = t.replace(/<[^>]*>/g, '').trim().toLowerCase();
    if (clean === 'ok' || clean === 'success') return;
    throw new Error(clean || 'unknown');
  })
  .catch(err => alert('Gagal menyimpan note: ' + err.message));
}


function updateInstruksi(id, instruksi) {
  const noteEl = document.getElementById('note-' + id);
  const note   = noteEl ? noteEl.value : '';

  fetch('surat_disposisi.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `id=${encodeURIComponent(id)}&instruksi=${encodeURIComponent(instruksi)}&note=${encodeURIComponent(note)}`
  })
  .then(r => r.text())
  .then(data => {
    data = data.trim();

    if (data === 'success') {
      document.getElementById('label-instruksi-' + id).innerText = instruksi || 'Belum Diproses';
      document.getElementById('status-' + id).innerText = instruksi ? 'Telah Diproses' : 'Belum Diproses';
      afterRowInstruksiChanged(id, instruksi);

    } else if (data === 'success-redirect-tindak') {
      document.getElementById('label-instruksi-' + id).innerText = instruksi;
      document.getElementById('status-' + id).innerText = 'Telah Diproses';
      afterRowInstruksiChanged(id, instruksi);
      window.location.href = 'surat_disposisi_tindak_lanjut.php?id=' + id;

    } else if (data === 'success-redirect-notif') {
      document.getElementById('label-instruksi-' + id).innerText = instruksi;
      document.getElementById('status-' + id).innerText = 'Telah Diproses';
      afterRowInstruksiChanged(id, instruksi);
      // ambil no_surat dari baris -> buat highlight di halaman notif
      const tr = document.getElementById('row-' + id);
      const noSurat = tr ? (tr.getAttribute('data-no_surat') || '') : '';
      const qs = noSurat ? ('?no_surat=' + encodeURIComponent(noSurat)) : '';
      window.location.href = 'surat_notif.php' + qs;
    }
  });
}


function toggleSaveBtn(id){
  const ta  = document.getElementById('note-' + id);
  const btn = document.getElementById('btn-save-' + id);
  if (!ta || !btn) return;
  btn.disabled = (ta.value === (ta.dataset.original ?? ''));
}

function saveNote(id){
  const ta     = document.getElementById('note-' + id);
  const btn    = document.getElementById('btn-save-' + id);
  const status = document.getElementById('note-status-' + id);
  if (!ta || !btn) return;

  const val = (ta.value || '').slice(0, 255); // batasi 255 char (sesuai server)
  btn.disabled = true;
  const oldText = btn.textContent;
  btn.textContent = 'Menyimpan...';

  fetch('surat_disposisi.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `save_note=1&id=${encodeURIComponent(id)}&note=${encodeURIComponent(val)}`
  })
  .then(r => r.text())
  .then(t => {
    const clean = t.replace(/<[^>]*>/g, '').trim().toLowerCase();
    if (clean === 'ok' || clean === 'success') {
      ta.dataset.original = val;              // tandai versi tersimpan
      status.textContent = '✓ Tersimpan';
      setTimeout(() => status.textContent = '', 1500);
    } else {
      throw new Error(clean || 'unknown');
    }
  })
  .catch(err => {
    alert('Gagal menyimpan note: ' + err.message);
  })
  .finally(() => {
    btn.textContent = oldText;
    toggleSaveBtn(id); // re-evaluate (akan disable lagi kalau tak ada perubahan)
  });
}

// (opsional) shortcut Ctrl+S / Cmd+S ketika fokus di textarea
document.addEventListener('keydown', (e) => {
  const isSave = (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's';
  if (!isSave) return;
  const ta = document.activeElement;
  if (!ta || ta.tagName !== 'TEXTAREA' || !ta.id.startsWith('note-')) return;
  e.preventDefault();
  const id = ta.id.replace('note-', '');
  saveNote(id);
});

(function(){
  const dd = document.querySelector('.export-dropdown');
  const btn = dd?.querySelector('.export-toggle');
  if (btn) {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      dd.classList.toggle('open');
    });
    document.addEventListener('click', () => dd.classList.remove('open'));
  }
})();
  </script>  
</body>
</html>





