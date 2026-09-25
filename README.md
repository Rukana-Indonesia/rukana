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

## Tes

```bash
cd backend && php artisan test
cd frontend && npm run lint && npm run build
```
