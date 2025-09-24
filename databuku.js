const daftarBuku = [
  {
    id: "1",
    judul: "KAMUS LENGKAP KEDOKTERAN",
    penulis: "Penulis 1",
    tahun: "2022",
    deskripsi: "Ringkasan isi buku ini tentang layanan kesehatan digital dan manajemen rumah sakit.",
    imageUrl: "Buku/Buku RSCH 1.jpeg"
  },
  {
    id: "2",
    judul: "MANAJEMEN INFORMASI KESEHATAN",
    penulis: "Penulis 2",
    tahun: "2023",
    deskripsi: "Buku ini membahas inovasi teknologi kesehatan dan pengembangan sistem informasi.",
    imageUrl: "Buku/Buku RSCH 2.jpeg"
  },
  {
    id: "3",
    judul: "ANATOMI UNTUK MAHASISWA KEDOKTERAN GIGI",
    penulis: "Penulis 3",
    tahun: "2024",
    deskripsi: "Buku ini membahas tentang anatomi dasar yang harus dipahami oleh mahasiswa kedokteran gigi.",
    imageUrl: "Buku/Buku RSCH 3.jpeg"
  }
];

// Simpan data ke localStorage
localStorage.setItem('daftarBuku', JSON.stringify(daftarBuku));

console.log("Data buku sudah disimpan:", localStorage.getItem('daftarBuku')); 
  