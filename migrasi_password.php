<?php
// migrate_passwords.php
// !!! FILE INI SANGAT PENTING: HAPUS SETELAH PROSES MIGRASI SELESAI !!!

session_start(); // Tidak wajib untuk migrasi, tapi tidak masalah ada

// --- Konfigurasi Koneksi Database Anda ---
// Pastikan ini sesuai dengan pengaturan database Anda
$host = 'localhost';
$user = 'root';
$pass = ''; // Biasanya kosong jika menggunakan Laragon/XAMPP default
$dbname = 'rsch'; // Ganti dengan nama database Anda

$conn = new mysqli($host, $user, $pass, $dbname);

// Cek koneksi database
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

echo "<html><head><title>Migrasi Password Database</title></head><body>";
echo "<h1>Memulai Proses Migrasi Password...</h1>";
echo "<p>Mohon jangan tutup halaman ini sampai proses selesai sepenuhnya.</p>";
echo "<div style='border: 1px solid #ccc; padding: 10px; height: 300px; overflow-y: scroll; background-color: #f9f9f9;'>";
ob_flush(); // Membersihkan output buffer
flush();    // Mengirim output ke browser segera

// --- Mengambil Semua Data Pengguna dari Database ---
// Pastikan nama tabel dan kolom 'password' sudah benar
$sql_select = "SELECT nik, password FROM users"; // Sesuaikan 'users' jika nama tabel Anda berbeda
$result = $conn->query($sql_select);

if ($result->num_rows > 0) {
    $migrated_count = 0;
    $skipped_count = 0;

    while ($row = $result->fetch_assoc()) {
        $nik = $row['nik'];
        $plaintext_password = $row['password'];

        // --- Cek apakah Password Sudah Di-Hash (Penting!) ---
        // Ini mencegah password di-hash ganda jika skrip dijalankan lebih dari sekali.
        // Regex ini mendeteksi format hash bcrypt ($2y$, $2a$, atau $2b$) yang umum.
        if (!preg_match('/^\$(2y|2a|2b)\$\d{2}\$.{53}$/i', $plaintext_password)) {
            // Jika belum di-hash, maka hash password plaintext tersebut
            $hashed_password = password_hash($plaintext_password, PASSWORD_DEFAULT);

            // --- Memperbarui Password yang Sudah Di-Hash Kembali ke Database ---
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE nik = ?");
            // "ss" berarti kedua parameter (hashed_password dan nik) adalah string
            $update_stmt->bind_param("ss", $hashed_password, $nik);

            if ($update_stmt->execute()) {
                echo "<p style='color: green;'>NIK: " . htmlspecialchars($nik) . " - Password berhasil di-hash.</p>";
                $migrated_count++;
            } else {
                echo "<p style='color: red;'>NIK: " . htmlspecialchars($nik) . " - Gagal meng-hash password: " . $update_stmt->error . "</p>";
            }
            $update_stmt->close(); // Tutup statement update
        } else {
            echo "<p style='color: blue;'>NIK: " . htmlspecialchars($nik) . " - Password sudah di-hash (dilewati).</p>";
            $skipped_count++;
        }
        ob_flush();
        flush(); // Pastikan output segera ditampilkan di browser
    }
    echo "</div>"; // Tutup div scrollable
    echo "<h2>Proses Migrasi Selesai!</h2>";
    echo "<p>Total password yang berhasil di-hash: <strong>" . $migrated_count . "</strong></p>";
    echo "<p>Total password yang dilewati (karena sudah di-hash): <strong>" . $skipped_count . "</strong></p>";

} else {
    echo "</div>"; // Tutup div scrollable
    echo "<p>Tidak ada pengguna ditemukan di database Anda.</p>";
}

$conn->close(); // Tutup koneksi database
echo "</body></html>";
?>