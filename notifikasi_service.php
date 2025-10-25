<?php
/**
 * notify_service.php
 * Helper notifikasi terpusat (PDO).
 *
 * PRASYARAT SKEMA:
 * - notifikasi(id, title, body, action_url, type, event_key, created_by, created_at)
 * - notifikasi_targets(id, notifikasi_id, user_id, status, unit, created_at)
 * - detail_notifikasi(notifikasi_id, user_id, read_at)
 *
 * CATATAN:
 * - Disarankan UNIQUE KEY di notifikasi(event_key) bila pakai event_key (idempoten).
 * - Fungsi create_notification() mendukung upsert event_key via ON DUPLICATE KEY UPDATE.
 */

if (!function_exists('arr')) {
  function arr($value): array {
    if (is_null($value) || $value === '') return [];
    if (is_array($value)) return array_values(array_unique(array_filter($value, fn($v)=>$v!=='' && $v!==null)));
    return [$value];
  }
}

/**
 * Buat notifikasi + target penerima.
 *
 * $data = [
 *   'title'      => 'Judul',
 *   'body'       => 'Isi …',
 *   'action_url' => 'surat_masuk.php?id=123',
 *   'type'       => 'surat',            // default 'general'
 *   'event_key'  => 'surat:123:status4',// opsional (idempoten)
 *   'created_by' => 5,                  // opsional (user pembuat)
 *   'targets'    => [
 *       'user_id' => [2, 7],            // opsional
 *       'status'  => ['Sekretariat'],   // opsional
 *       'unit'    => ['FARMASI RAWAT JALAN'] // opsional
 *   ]
 * ];
 *
 * @return int $notifikasi_id
 */
function create_notification(PDO $pdo, array $data): int {
  $title      = trim($data['title'] ?? '');
  $body       = $data['body'] ?? null;
  $action_url = $data['action_url'] ?? null;
  $type       = $data['type'] ?? 'general';
  $event_key  = $data['event_key'] ?? null;
  $created_by = $data['created_by'] ?? null;

  $tUsers   = arr($data['targets']['user_id'] ?? []);
  $tStatus  = arr($data['targets']['status']  ?? []);
  $tUnits   = arr($data['targets']['unit']    ?? []);

  if ($title === '') {
    throw new InvalidArgumentException('title wajib diisi');
  }
  if (empty($tUsers) && empty($tStatus) && empty($tUnits)) {
    throw new InvalidArgumentException('targets minimal isi salah satu: user_id / status / unit');
  }

  try {
    $pdo->beginTransaction();

    // Insert notifikasi (idempoten via event_key bila ada UNIQUE KEY)
    if ($event_key) {
      $sql = "INSERT INTO notifikasi (title, body, action_url, type, event_key, created_by)
              VALUES (:title, :body, :url, :type, :ek, :cb)
              ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                title = VALUES(title),
                body = VALUES(body),
                action_url = VALUES(action_url),
                type = VALUES(type),
                created_by = VALUES(created_by)";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':title' => $title,
        ':body'  => $body,
        ':url'   => $action_url,
        ':type'  => $type,
        ':ek'    => $event_key,
        ':cb'    => $created_by,
      ]);
      $notifikasi_id = (int)$pdo->lastInsertId();
      if ($notifikasi_id === 0) {
        // fallback bila engine tidak set LAST_INSERT_ID pada duplicate (harusnya set)
        $q = $pdo->prepare("SELECT id FROM notifikasi WHERE event_key = :ek LIMIT 1");
        $q->execute([':ek' => $event_key]);
        $notifikasi_id = (int)$q->fetchColumn();
      }
    } else {
      $st = $pdo->prepare("INSERT INTO notifikasi (title, body, action_url, type, created_by)
                           VALUES (:title, :body, :url, :type, :cb)");
      $st->execute([
        ':title' => $title,
        ':body'  => $body,
        ':url'   => $action_url,
        ':type'  => $type,
        ':cb'    => $created_by,
      ]);
      $notifikasi_id = (int)$pdo->lastInsertId();
    }

    // Insert target (gunakan INSERT IGNORE agar idempoten kalau dipanggil ulang)
    if (!empty($tUsers)) {
      $st = $pdo->prepare("INSERT IGNORE INTO notifikasi_targets (notifikasi_id, user_id) VALUES (:nid, :uid)");
      foreach ($tUsers as $uid) {
        if ($uid === '' || $uid === null) continue;
        $st->execute([':nid'=>$notifikasi_id, ':uid'=>$uid]);
      }
    }
    if (!empty($tStatus)) {
      $st = $pdo->prepare("INSERT IGNORE INTO notifikasi_targets (notifikasi_id, status) VALUES (:nid, :status)");
      foreach ($tStatus as $s) {
        if ($s === '' || $s === null) continue;
        $st->execute([':nid'=>$notifikasi_id, ':status'=>$s]);
      }
    }
    if (!empty($tUnits)) {
      $st = $pdo->prepare("INSERT IGNORE INTO notifikasi_targets (notifikasi_id, unit) VALUES (:nid, :unit)");
      foreach ($tUnits as $u) {
        if ($u === '' || $u === null) continue;
        $st->execute([':nid'=>$notifikasi_id, ':unit'=>$u]);
      }
    }

    $pdo->commit();
    return $notifikasi_id;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/**
 * Hitung jumlah unread untuk user saat ini.
 */
function getUnreadCount(PDO $pdo, int $uid, ?string $status, ?string $unit): int {
  $sql = "
    SELECT COUNT(*) AS unread
    FROM notifikasi n
    JOIN notifikasi_targets t
      ON t.notifikasi_id = n.id
      AND (
           t.user_id = :uid
        OR (t.status IS NOT NULL AND t.status = :status)
        OR (t.unit   IS NOT NULL AND t.unit   = :unit)
      )
    LEFT JOIN detail_notifikasi dn
      ON dn.notifikasi_id = n.id AND dn.user_id = :uid
    WHERE dn.user_id IS NULL
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':uid'    => $uid,
    ':status' => $status,
    ':unit'   => $unit,
  ]);
  return (int) $st->fetchColumn();
}

/**
 * Ambil daftar inbox user saat ini (dengan flag unread).
 * @return array<array>
 */
function getInbox(PDO $pdo, int $uid, ?string $status, ?string $unit, int $limit = 100): array {
  $limit = max(1, min($limit, 500)); // guard
  $sql = "
    SELECT 
      n.id, n.title, n.body, n.action_url, n.type, n.created_at,
      (dn.user_id IS NULL) AS is_unread
    FROM notifikasi n
    JOIN notifikasi_targets t
      ON t.notifikasi_id = n.id
      AND (
           t.user_id = :uid
        OR (t.status IS NOT NULL AND t.status = :status)
        OR (t.unit   IS NOT NULL AND t.unit   = :unit)
      )
    LEFT JOIN detail_notifikasi dn
      ON dn.notifikasi_id = n.id AND dn.user_id = :uid
    ORDER BY n.created_at DESC
    LIMIT {$limit}
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':uid'    => $uid,
    ':status' => $status,
    ':unit'   => $unit,
  ]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Tandai satu notif sebagai sudah dibaca oleh user.
 * Idempoten (PRIMARY KEY (notifikasi_id, user_id)).
 */
function markAsRead(PDO $pdo, int $uid, int $notifikasi_id): void {
  $st = $pdo->prepare("INSERT IGNORE INTO detail_notifikasi (notifikasi_id, user_id) VALUES (:nid, :uid)");
  $st->execute([':nid'=>$notifikasi_id, ':uid'=>$uid]);
}

/**
 * (Opsional) Tandai semua notif yang ditujukan ke user sebagai sudah dibaca.
 */
function markAllAsRead(PDO $pdo, int $uid, ?string $status, ?string $unit): int {
  // Ambil id notifikasi yang relevan & belum dibaca
  $sqlIds = "
    SELECT n.id
    FROM notifikasi n
    JOIN notifikasi_targets t
      ON t.notifikasi_id = n.id
      AND (
           t.user_id = :uid
        OR (t.status IS NOT NULL AND t.status = :status)
        OR (t.unit   IS NOT NULL AND t.unit   = :unit)
      )
    LEFT JOIN detail_notifikasi dn
      ON dn.notifikasi_id = n.id AND dn.user_id = :uid
    WHERE dn.user_id IS NULL
  ";
  $st = $pdo->prepare($sqlIds);
  $st->execute([':uid'=>$uid, ':status'=>$status, ':unit'=>$unit]);
  $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);

  if (empty($ids)) return 0;

  $pdo->beginTransaction();
  $ins = $pdo->prepare("INSERT IGNORE INTO detail_notifikasi (notifikasi_id, user_id) VALUES (:nid, :uid)");
  $count = 0;
  foreach ($ids as $nid) {
    $ins->execute([':nid'=>(int)$nid, ':uid'=>$uid]);
    $count += (int)$ins->rowCount(); // yang benar-benar baru
  }
  $pdo->commit();
  return $count;
}
