<?php
session_start();

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - Portal Home</title>
  <link rel="stylesheet" href="style.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <style>
    .login-container {
      max-width: 400px;
      margin: 80px auto;
      background-color: #ffffff;
      padding: 40px 30px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      border-radius: 10px;
      text-align: center;
    }

    .login-container h2 {
      font-weight: 500;
      margin-bottom: 10px;
    }

    .login-container p {
      color: #666;
      margin-bottom: 30px;
    }

    .login-container input[type="text"],
    .login-container input[type="password"] {
      width: 100%;
      padding: 12px;
      margin: 10px 0;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 14px;
    }

    .login-container .login-btn {
      background-color: #1a73e8;
      color: white;
      border: none;
      padding: 12px 24px;
      font-size: 16px;
      border-radius: 6px;
      margin-top: 20px;
      cursor: pointer;
    }

    .login-container .login-btn:hover {
      background-color: #1558b0;
    }

  </style>
</head>
<body>

  <div class="main-content">

    <!-- Navbar Atas -->
    <div class="navbar-top">
      <div class="logo">
        <img src="Properti/LOGO_RSCH.png" alt="Logo" />
        <div class="logo-text">
          <div class="main-title">RUANG BACA VIRTUAL</div>
        </div>
      </div>
      <div class="top-buttons">
        <a href="login.php" class="login-btn">Login</a>
      </div>
    </div>

    <!-- Navbar Bawah -->
    <nav class="navbar-bottom">
      <div class="navbar-bottom-container">
        <ul>
          <li><a href="1_trial.php" class="fitur-nav">Beranda</a></li>
          <li class="dropdown">
            <a href="javascript:void(0);" onclick="toggleDropdown()">Berita</a>
            <div class="dropdown-content">
              <a href="https://www.goal.com" target="_blank">Bola</a>
              <a href="https://sport.detik.com/" target="_blank">Sport</a>
              <a href="https://www.liputan6.com/showbiz" target="_blank">Showbiz</a>
              <a href="https://www.viva.co.id/gaya-hidup" target="_blank">LifeStyle</a>
              <a href="https://www.oto.com/berita" target="_blank">Otomotif</a>
            </div>
          </li>
          <li><a href="koleksi.php" class="fitur-nav">Koleksi</a></li>
          <!-- <li><a href="bacaan.php" class="fitur-nav">Bacaan</a></li> -->
          <!-- <li><a href="masukan.php" class="fitur-nav">Masukan</a></li> -->
        </ul>
        <!-- <div class="search-bar-bottom">
          <input type="text" placeholder="Cari..." />
          <button>Cari</button>
        </div> -->
      </div>
    </nav>

    <!-- Login Container -->
    <div class="login-container">
    <h2>Login</h2>
    <p>Gunakan akun anda untuk masuk</p>

    <form class="login-form" method="POST" action="login2.php" autocomplete="off">
      <!-- “umpan” tersembunyi untuk menangkap autofill -->
      <input type="text" name="fake-username" autocomplete="username" style="position:absolute; left:-9999px; height:0; opacity:0;">
      <input type="password" name="fake-password" autocomplete="new-password" style="position:absolute; left:-9999px; height:0; opacity:0;">

      <!-- field asli --> 
      <input type="text" name="nik" placeholder="NIK"
            autocomplete="off" inputmode="numeric">
      <input type="password" name="password" placeholder="Password"
            autocomplete="new-password">
      <button type="submit" class="login-btn">Login</button>
    </form>
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
        <a href="https://www.youtube.com/channel/UCWrutgBiaPK0vCk_pYxwGhw"><i class="bx bxl-youtube sosmed-icon"></i></a>
        <a href="https://www.facebook.com/rscitrahusadajember/"><i class="bx bxl-facebook sosmed-icon"></i></a>
        <a href="https://www.tiktok.com/@rscitrahusadajember"><i class="bx bxl-tiktok sosmed-icon"></i></a>
        <a href="https://www.instagram.com/rscitrahusadajember/"><i class="bx bxl-instagram sosmed-icon"></i></a>
      </div>
      <div class="footer-section">
        <h3>Kontak Kami</h3>
        <p>(+62 331) 486200 ext: 142<br>08979049176</p>
        <p>Jalan Teratai No. 22, Patrang. Kab. Jember<br>Jawa Timur, Indonesia 68117</p>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© Copyright IT Support Citra Husada.</p>
    </div>
  </footer>
  <script src="script.js"></script>
  <script>
    if (window.location.search.includes('registered') || window.location.search.includes('error')) {
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  </script>
</body>
</html>
