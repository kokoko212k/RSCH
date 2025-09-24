<?php
session_start();

// Koneksi database
$conn = mysqli_connect('localhost', 'root', '', 'rsch');
if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

// Proses kirim komentar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = htmlspecialchars($_POST['name']);
    $email = htmlspecialchars($_POST['email']);
    $comment = htmlspecialchars($_POST['comment']);
    $parentId = isset($_POST['parentId']) && $_POST['parentId'] !== '' ? (int)$_POST['parentId'] : null;

    if ($name && $email && $comment) {
        $stmt = mysqli_prepare($conn, "INSERT INTO komentar (nama, email, isi_komentar, tanggal, parent_id) VALUES (?, ?, ?, NOW(), ?)");
        mysqli_stmt_bind_param($stmt, "sssi", $name, $email, $comment, $parentId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Ambil semua komentar
$comments = [];
$result = mysqli_query($conn, "SELECT * FROM komentar ORDER BY tanggal ASC");

while ($row = mysqli_fetch_assoc($result)) {
    $comments[] = $row;
}

mysqli_free_result($result);

// Fungsi buat render komentar beranak
function renderComments($comments, $parentId = null) {
    $filtered = array_filter($comments, function($c) use ($parentId) {
        $currentParentId = isset($c['parent_id']) ? $c['parent_id'] : null;
        return $currentParentId == $parentId;
    });

    if ($filtered) {
        echo '<div class="comment-block">';
        foreach ($filtered as $comment) {
            echo '<div class="comment">';
            echo '<div class="comment-info"><strong>' . htmlspecialchars($comment['nama']) . '</strong> | ' . htmlspecialchars($comment['email']) . ' | ' . $comment['tanggal'] . '</div>';
            echo '<div class="comment-content">' . nl2br(htmlspecialchars($comment['isi_komentar'])) . '</div>';

            // Tombol Balas
            echo '<button type="button" class="reply-btn" onclick="toggleReplyForm(' . $comment['id'] . ')">Balas</button>';

            // Form balasan disembunyikan dulu
            echo '<form method="POST" id="reply-form-' . $comment['id'] . '" style="display:none; margin-top:10px;">
                    <input type="hidden" name="parentId" value="' . $comment['id'] . '">
                    <input type="text" name="name" placeholder="Nama" required>
                    <input type="email" name="email" placeholder="Email" required>
                    <textarea name="comment" placeholder="Balasan..." required></textarea>
                    <button type="submit">Balas</button>
                  </form>';

            // Rekursif buat balasan
            renderComments($comments, $comment['id']);

            echo '</div>'; // Tutup .comment
        }
        echo '</div>'; // Tutup .comment-block
    }
}


// Ambil data user dari session jika ada
$user = $_SESSION['user'] ?? null;
// Ambil role/status user (atau kosong kalau belum login)
$role = $user['status'] ?? null;
// Cek apakah user punya akses ke fitur E-Office
$can_access_eoffice = in_array($role, ['Admin', 'Sekretariat', 'Direktur', 'Super Admin']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Form Komentar PHP</title>
    <link rel="stylesheet" href="style.css" />
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
    <style>
        .comment-container {
            max-width: 700px;
            margin: auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;                        
            margin-bottom: 20px;            
        }
        .comment-container h2 {
            text-align: center;
        }
        input, textarea {
            width: 100%;
            padding: 8px;
            margin-top: 8px;
            margin-bottom: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        button {
            background: #1a73e8;
            color: white;
            padding: 10px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 16px;

        }
        button:hover {
            background: #1558b0;
        }
        .comment-block {
            margin-left: 20px; 
            border-left: 2px solid #eee;
            padding-left: 10px;
            margin-top: 10px;
        }
        .comment {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
            position: relative; 
        }
        .comment-info {
            font-size: 12px;
            color: #555;
        }
        .comment-content {
            margin-top: 5px;
        }
        .comment form[id^="reply-form-"] { 
            margin-top: 10px;
            margin-left: 0;
            padding-left: 0; 
            width: 100%;
            box-sizing: border-box; 
        }
        .reply-form {
            margin-top: 10px;
            margin-left: 0; 
            padding-left: 0; 
            width: 100%; 
        }
        .reply-btn {
          margin-top: 10px;
          background-color: #007bff;
          color: white;
          border: none;
          padding: 5px 10px;
          cursor: pointer;
        }


    </style>
</head>
<body>

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
      <a href="sub_beranda.php" class="jelajahi-portal">Layanan</a>
      <?php if (isset($_SESSION['user'])): ?>
        <div class="user-dropdown">
          <i class="bx bxs-user-circle user-icon" onclick="toggleUserDropdown()"></i>
          <div class="user-menu" id="userMenu">
            <a href="profil.php">Profil</a>
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
        <li><a href="masukan.php" class="fitur-nav">Masukan</a></li>
        <?php if ($can_access_eoffice): ?>
        <li class="dropdown">
          <a class="fitur-nav" href="javascript:void(0);">E-Office</a>
          <div class="dropdown-content">
            <a href="surat_masuk.php">Surat Masuk</a>
            <a href="surat_keluar.php">Surat Keluar</a>
            <a href="surat_disposisi_pengajuan.php">Disposisi Pengajuan</a>
            <a href="surat_disposisi.php">Disposisi Surat</a>
            <a href="surat_disposisi_tindak_lanjut.php">Disposisi Tindak Lanjut</a>
            <a href="surat_notif.php">Surat Notif</a>          
            <a href="surat_pengajuan.php">Pengajuan</a>          
            <!-- <a href="surat_internal.php">Surat Internal</a>         -->
          </div>
        </li>
        <?php endif; ?>
      </ul>
    </div>
  </nav>

<div class="comment-container">
    <h2>Form Komentar</h2>
    <form method="POST">
        <input type="text" name="name" placeholder="Nama" required>
        <input type="email" name="email" placeholder="Email" required>
        <textarea name="comment" placeholder="Komentar Anda..." required></textarea>
        <button type="submit">Kirim</button>
    </form>

    <hr>

    <h3>Komentar:</h3>
    <?php renderComments($comments); ?>
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
      <p>© Copyright Humas Marketing Citra Husada.</p>
    </div>
  </footer>
  <script src="script.js"></script>
  <script>
      // === DROPDOWN USER LOGIN ===
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

function toggleReplyForm(commentId) {
    var form = document.getElementById('reply-form-' + commentId);

    // Toggle the display property
    if (form.style.display === 'none' || form.style.display === '') { // Check for both 'none' and empty string
        form.style.display = 'block';
    } else {
        form.style.display = 'none';
    }
}

  </script>
</body>
</html>
