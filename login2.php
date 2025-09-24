<?php
session_start();

// Konfigurasi koneksi
$host = 'localhost';
$user = 'root';
$pass = ''; 
$dbname = 'rsch';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Ambil input dari form
$nik = trim($_POST['nik'] ?? '');
$enteredPassword = trim($_POST['password'] ?? ''); 

$stmt = $conn->prepare("SELECT nik, password, status, nama, tempat_lahir, tanggal_lahir, jenis_kelamin, alamat_ktp FROM users WHERE nik = ?");

$stmt->bind_param("s", $nik);
$stmt->execute();
$result = $stmt->get_result();

// Cek apakah user ditemukan
if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();

    // Pastikan `$user['password']` berisi password yang sudah di-hash
    if (password_verify($enteredPassword, $user['password'])) {
        // Hapus 'password' dari data sesi demi keamanan
        unset($user['password']);
        // Simpan semua data user ke dalam sesi
        $_SESSION['user'] = $user;
        header("Location: sub_beranda.php");
        exit;
    }
}

// Jika gagal login (NIK tidak ditemukan atau password salah)
$_SESSION['message'] = 'Login gagal. NIK atau password salah.';
header("Location: login.php");
exit;

?>