# Rukana

Platform web layanan warga tingkat desa: surat pengantar berjenjang RT → RW → desa, laporan warga, dan pengumuman bertarget, dengan bantuan AI (Claude).

| Folder | Isi |
|---|---|
| `backend/` | Laravel 13 REST API, Sanctum (SPA, sesi cookie), MySQL |
| `frontend/` | Vue 3 + Vite + Pinia + Vue Router + Tailwind CSS 4 |
| `docs/` | Rancangan (skema database) |

## Kebutuhan

- PHP 8.4 + Composer
- Node 22 LTS (`nvm use 22`)
- Docker (MySQL 8.4 dijalankan lewat `docker-compose.yml`, port 3307)

## Menjalankan

```bash
# 1. Database
docker compose up -d

# 2. Backend (http://127.0.0.1:8000)
cd backend
composer install
cp .env.example .env && php artisan key:generate   # sekali saja
php artisan migrate --seed
php artisan serve
php artisan schedule:work   # terminal terpisah: pengumuman terjadwal + Monthly Insight tiap tanggal 1
php artisan queue:work --timeout=300   # terminal terpisah: Smart Complaint dan Monthly Insight

# 3. Frontend (http://localhost:5173)
cd frontend
npm install
npm run dev
```

Buka aplikasi lewat **http://localhost:5173**. Vite memproksikan `/api` dan `/sanctum` ke backend, jadi SPA dan API satu origin dan cookie sesi Sanctum berlaku tanpa CORS. Target proksi bisa diganti lewat `VITE_API_PROXY_TARGET`.

## Akun dummy

Semua kata sandi: `password`. Di mode pengembangan, halaman Masuk menampilkan daftar akun ini.

| Peran | No. HP |
|---|---|
| Operator Desa | 081100000001 |
| Kepala Desa | 081100000002 |
| Ketua RW 03 | 081100000300 |
| Ketua RT 01/RW 03 | 081100000301 |
| Warga RT 01/RW 03 | 081200030101 |
| Warga belum diverifikasi | 081200030109 |

Pola no. HP (RW 03/04, RT 01/02): ketua RW `08110000{RW}00`, ketua RT `08110000{RW}{RT}`, warga `0812000{RW tanpa nol}{RT}{01–03}`, warga belum diverifikasi `…09`.

## Rekap & ekspor

Dasbor pengurus (RT, RW, operator, kepala desa) punya panel **Rekap layanan** per periode dan wilayah. RW dan operator bisa mengunduhnya sebagai Excel (`.xlsx`, lewat OpenSpout) atau PDF (dompdf). Surat dan laporan dihitung dari yang masuk dalam periode; rating dari yang diberikan dalam periode. Berkas ekspor tidak memuat NIK, nomor HP, maupun nama pelapor laporan, dan setiap unduhan tercatat di audit log (`export.downloaded`).

## Peta sebaran laporan

Menu **Peta Laporan** (RT, RW, operator, kepala desa) menampilkan titik laporan di peta OpenStreetMap + Leaflet, dengan filter periode, wilayah, kategori, dan status. Lokasi yang punya 2 laporan atau lebih dalam radius ±100 m ditandai sebagai **titik berulang**. Hanya laporan yang punya titik peta yang tampil; laporan yang hanya berisi patokan lokasi ikut dihitung tapi tidak digambar. Peta tidak memuat nama pelapor, nomor HP, maupun patokan lokasi.

Bila belum ada titik laporan, peta dibuka di koordinat `MAP_CENTER_LAT` / `MAP_CENTER_LNG` (zoom `MAP_CENTER_ZOOM`) dari `backend/.env`. Isi dengan koordinat kantor atau balai desa.

## Fitur AI (Claude)

Tiga fitur memakai Claude lewat SDK resmi `anthropic-ai/sdk`. Isi `ANTHROPIC_API_KEY` di `backend/.env`; model bawaan `claude-opus-5-5` (ganti lewat `ANTHROPIC_MODEL`). Tanpa kunci, aplikasi tetap jalan dan fitur AI menampilkan "belum dikonfigurasi".

- **Smart Complaint**: setiap laporan baru dianalisis di queue (perlu `queue:work`). Pengurus melihat saran kategori, urgensi, tingkat penanganan, dan ringkasan di detail laporan. AI tidak mengubah laporan; keputusan pengurus dicatat untuk menghitung ketepatan (tampil di Ringkasan Bulanan). Nama, no. HP, dan patokan lokasi pelapor tidak dikirim ke AI.
- **Ringkasan Bulanan (Monthly Insight)**: angka dihitung `ServiceRecap` (sama dengan dasbor), AI hanya menyusun narasi. Setiap angka di narasi dicek terhadap data; narasi dengan angka lain ditolak. Disusun otomatis tiap tanggal 1 untuk bulan lalu (`insights:generate`), atau oleh RW/operator dari menu.
- **Ask Rukana**: RT, RW, operator, dan kepala desa bertanya dalam bahasa sehari-hari. AI hanya bisa memanggil tool rekap; scope wilayah disisipkan backend dari akun, jadi pertanyaan tentang wilayah lain ditolak dan tercatat.

Semua pemanggilan AI dan tool tercatat di `ai_tool_calls` dan `audit_logs` (`ai.invoked`). Tes tidak pernah memanggil API (memakai `Tests\Support\FakeAiGateway`).

## Tes

```bash
cd backend && php artisan test
cd frontend && npm run lint && npm run build
```
