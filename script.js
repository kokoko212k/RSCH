document.addEventListener("DOMContentLoaded", function () {
  const fadeElements = document.querySelectorAll('.fade-in');
  const dropdown = document.querySelector('.dropdown');
  const dropdownContent = document.querySelector('.dropdown-content');
  const koleksiContainer = document.getElementById("koleksi-container");
  const searchButton = document.querySelector('.koleksi-search button'); // Tombol pencarian
  const searchInput = document.querySelector('.koleksi-search input'); // Input pencarian


  // Fungsi untuk menampilkan elemen fade-in saat scroll
  const showOnScroll = () => {
    fadeElements.forEach(el => {
      const rect = el.getBoundingClientRect();
      if (rect.top < window.innerHeight - 100) {
        el.classList.add('show');
      }
    });
  };
  
  window.addEventListener('scroll', showOnScroll);
  showOnScroll(); // Trigger saat halaman load pertama kali
  
  // Fungsi untuk men-toggle dropdown
  function toggleDropdown() {
    dropdown.classList.toggle('active');
  }

  // Menambahkan event listener klik pada dropdown untuk toggle
  dropdown.addEventListener('click', function (event) {
    event.stopPropagation(); // Agar klik pada dropdown tidak menutup dropdown
    toggleDropdown();
  });

  // Fungsi untuk menutup dropdown jika klik di luar dropdown
  document.addEventListener('click', function (event) {
    if (!dropdown.contains(event.target)) { // Jika klik di luar dropdown
      dropdown.classList.remove('active');
    }
  });

  // Fungsi untuk menampilkan daftar buku
  const displayBooks = (books) => {
    koleksiContainer.innerHTML = ''; // Kosongkan kontainer sebelum menambahkan buku baru

    if (books.length > 0) {
      books.forEach(buku => {
        const koleksiItem = document.createElement("div");
        koleksiItem.classList.add("koleksi-item");

        koleksiItem.innerHTML = `
          <a href="detail-buku.html?id=${buku.id}">
            <img src="${buku.imageUrl}" alt="${buku.judul}" class="koleksi-img" />
            <div class="koleksi-info">
              <h3>${buku.judul}</h3>
              <p><strong>Penulis:</strong> ${buku.penulis}</p>
              <p><strong>Tahun:</strong> ${buku.tahun}</p>
              <p><strong>Deskripsi:</strong> ${buku.deskripsi}</p>
            </div>
          </a>
        `;

        koleksiContainer.appendChild(koleksiItem);
      });
    } else {
      koleksiContainer.innerHTML = '<p>Tidak ada buku yang sesuai dengan pencarian.</p>';
    }
  };

  // Ambil data buku dari localStorage
  const storedBooks = JSON.parse(localStorage.getItem('daftarBuku')) || [];
  console.log("Data buku yang diambil:", storedBooks);
  
  // Tampilkan semua buku saat pertama kali
  displayBooks(storedBooks);

  // Event listener untuk tombol pencarian
  searchButton.addEventListener("click", function () {
    const searchQuery = searchInput.value.trim().toLowerCase(); // Ambil nilai pencarian dan buang spasi kosong

    if (searchQuery) {
      // Filter buku berdasarkan judul atau penulis
      const filteredBooks = storedBooks.filter(buku => 
        buku.judul.toLowerCase().includes(searchQuery) ||
        buku.penulis.toLowerCase().includes(searchQuery)
      );
      
      displayBooks(filteredBooks); // Tampilkan buku yang terfilter
    } else {
      // Tampilkan semua buku jika pencarian kosong
      displayBooks(storedBooks);
    }
  });
});

// LOGIN
document.addEventListener("DOMContentLoaded", function () {
  const loginForm = document.getElementById("login-form");
  const errorMsg = document.getElementById("login-error");

  loginForm.addEventListener("submit", function (e) {
    e.preventDefault(); // Mencegah reload

    const username = document.getElementById("username").value;
    const password = document.getElementById("password").value;

    fetch("proses_login.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: `username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`
    })
    .then(response => {
      // Kalau PHP mengirim redirect (berarti login sukses)
      if (response.redirected) {
        window.location.href = response.url;
      } else {
        return response.text();
      }
    })
    .then(data => {
      if (data && !data.includes("<!DOCTYPE")) {
        // Kalau tidak di-redirect dan ada error dari PHP
        errorMsg.textContent = data;
      }
    })
    .catch(err => {
      errorMsg.textContent = "Terjadi kesalahan. Coba lagi.";
      console.error(err);
    });
  });
});

// Toggle Dropdown User
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

  function searchTable() {
  const input = document.getElementById("searchInput").value.toLowerCase();
  const rows = document.querySelectorAll("#tabelSuratMasuk .data-row");

  rows.forEach(row => {
    const cells = row.querySelectorAll("td");
    let found = false;

    cells.forEach(cell => {
      if (cell.textContent.toLowerCase().includes(input)) {
        found = true;
      }
    });

    row.style.display = found ? "table-row" : "none";
  });

  // Tampilkan tombol reset jika ada input
  const resetContainer = document.getElementById("reset-container");
  if (input.length > 0) {
    resetContainer.style.display = "block";
  }
}

  function searchTable() {
  const input = document.getElementById("searchInput").value.toLowerCase();
  const rows = document.querySelectorAll("#tabelSuratMasuk .data-row");

  rows.forEach(row => {
    const cells = row.querySelectorAll("td");
    let found = false;

    cells.forEach(cell => {
      if (cell.textContent.toLowerCase().includes(input)) {
        found = true;
      }
    });

    row.style.display = found ? "table-row" : "none";
  });

  // Tampilkan tombol reset jika ada input
  const resetContainer = document.getElementById("reset-container");
  if (input.length > 0) {
    resetContainer.style.display = "block";
  }
}

