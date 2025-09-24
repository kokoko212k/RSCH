const daftarSOP = [
  {
    id: "1",
    judul: "STANDAR OPERASIONAL PROSEDUR PENDAFTARAN PASIEN RAWAT JALAN",
    deskripsi: "Mengatur langkah-langkah dalam proses pendaftaran pasien rawat jalan agar berjalan lancar dan sesuai standar pelayanan rumah sakit.",
    link: "detail_sop1.html"
  },
  {
    id: "2",
    judul: "STANDAR OPERASIONAL PROSEDUR PELAYANAN RAWAT INAP",
    deskripsi: "Panduan pelaksanaan pelayanan rawat inap untuk memastikan kenyamanan dan keamanan pasien.",
    link: "detail_sop2.html"
  },
  {
    id: "3",
    judul: "STANDAR OPERASIONAL PROSEDUR PENGELOLAAN LIMBAH MEDIS",
    deskripsi: "Prosedur pengelolaan limbah medis agar sesuai dengan aturan dan tidak membahayakan lingkungan.",
    link: "detail_sop3.html"
  }
];

const container = document.getElementById("sop-container");
container.innerHTML = "";

daftarSOP.forEach((sop) => {
  const item = document.createElement("div");
  item.className = "koleksi-item";
  item.style.cursor = "pointer";

  item.innerHTML = `
    <h3>${sop.judul}</h3>
    <p>${sop.deskripsi}</p>
  `;

  // Tambahkan event klik untuk buka halaman detail
  item.onclick = () => {
    window.location.href = sop.link;
  };

  container.appendChild(item);
});

