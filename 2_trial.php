// Statistik 1: jumlah kunjungan per bulan
$stmt = $pdo->query("
    SELECT DATE_FORMAT(tanggal_kunjungan, '%Y-%m') AS bulan, COUNT(*) AS total 
    FROM log_kunjungan 
    GROUP BY bulan 
    ORDER BY bulan DESC
");
$kunjungan = $stmt->fetchAll();

// Statistik 2: 5 genre terpopuler per bulan
$stmt = $pdo->query("
    SELECT genre, DATE_FORMAT(tanggal_baca, '%Y-%m') AS bulan, COUNT(*) AS total 
    FROM log_baca_buku 
    GROUP BY genre, bulan 
    ORDER BY bulan DESC, total DESC
");
$genre = $stmt->fetchAll();
