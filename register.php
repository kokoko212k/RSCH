<?php
session_start();
require 'config.php'; // koneksi PDO

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nik = $_POST['nik'];
    $nama = $_POST['nama'];
    $status = $_POST['status'];
    $tempat_lahir = $_POST['tempat_lahir'];
    $tanggal_lahir = $_POST['tanggal_lahir'];
    $jenis_kelamin = $_POST['jenis_kelamin'];
    $alamat_ktp = $_POST['alamat_ktp'];
    $password = $_POST['password'];


    // Cek apakah NIK sudah terdaftar
    $stmt = $pdo->prepare("SELECT * FROM users WHERE NIK= :nik");
    $stmt->execute(['nik' => $nik]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['message'] = "NIK sudah terdaftar.";
        header("Location: akun.html"); // halaman register
        exit;
    }

    // Simpan data ke database
    $stmt = $pdo->prepare("INSERT INTO users 
        (NIK, Nama, status, Tempat_Lahir, Tanggal_Lahir, Jenis_Kelamin, Alamat_KTP, Password)
        VALUES 
        (:nik, :nama, :status, :tempat_lahir, :tanggal_lahir, :jenis_kelamin, :alamat_ktp, :password)");

    $stmt->execute([
        'nik' => $nik,
        'nama' => $nama,
        'status' => $status,
        'tempat_lahir' => $tempat_lahir,
        'tanggal_lahir' => $tanggal_lahir,
        'jenis_kelamin' => $jenis_kelamin,
        'alamat_ktp' => $alamat_ktp,
        'password' => $hash
    ]);

    $_SESSION['message'] = "Pendaftaran berhasil! Silakan login.";
    header("Location: login.html");
    exit;
}
?>
