# Deployment Fleet Planner ke VPS Hostinger

Panduan ini menyiapkan **Fleet Planner** sebagai service baru di VPS yang sudah menjalankan ERP, HR portal, dan recruitment system dengan Docker + Traefik. Jangan ubah compose, network, atau konfigurasi Traefik milik aplikasi lain.

Target production:

- Domain: `https://fleet.nusantaraabadijaya.com`
- Runtime: Laravel 11, PHP 8.4, FrankenPHP
- Frontend: Vite + React + TypeScript, dibuild saat Docker image dibuat
- Reverse proxy: Traefik existing di network eksternal `workspace_local-dev`
- Database: MySQL production existing/shared, bukan container baru dari project ini

## File Yang Disiapkan

- `Dockerfile` — multi-stage build: Node build untuk asset Vite, Composer production install, runtime FrankenPHP PHP 8.4.
- `docker/entrypoint.sh` — membuat folder writable dan menjalankan `config:cache`, `route:cache`, `view:cache` saat container production start.
- `docker-compose.yml` — **hanya service Fleet Planner**, membaca variabel dari `.env`, join ke network eksternal `workspace_local-dev`, label Traefik untuk domain Fleet.
- `.env.example` — template env production yang dicopy menjadi `.env` di VPS.
- `.env.production.example` — salinan template production untuk operator yang masih memakai nama lama.
- `bootstrap/app.php` — mempercayai `X-Forwarded-*` headers dari reverse proxy agar HTTPS Traefik dikenali Laravel.

## Prasyarat VPS

Pastikan ini sudah tersedia sebelum mulai:

1. Docker Engine dan Docker Compose plugin sudah terpasang.
2. Traefik sudah berjalan dan sudah join ke network Docker `workspace_local-dev`.
3. Network `workspace_local-dev` sudah ada. Cek dengan:

   ```bash
   docker network ls | grep workspace_local-dev
   ```

4. DNS `fleet.nusantaraabadijaya.com` sudah mengarah ke IP VPS Hostinger.
5. Traefik existing sudah punya entrypoint `websecure` dan cert resolver, biasanya `letsencrypt`.
6. MySQL production sudah tersedia dan bisa diakses dari container di network `workspace_local-dev`.

> Catatan: project ini tidak membuat service MySQL baru agar tidak mengganggu ERP/HR/recruitment yang sudah jalan.

## 1. Clone Atau Pull Repo

Masuk ke folder kerja di VPS. Contoh:

```bash
cd /var/www
git clone <URL_REPO_FLEET_PLANNER> fleet-planner
cd fleet-planner
```

Jika repo sudah pernah diclone:

```bash
cd /var/www/fleet-planner
git pull origin main
```

Ganti `main` jika branch production memakai nama lain.

## 2. Siapkan Database MySQL Production

Buat database dan user MySQL untuk Fleet Planner di MySQL production existing. Contoh jika akses dari host VPS:

```bash
mysql -u root -p
```

Lalu di prompt MySQL:

```sql
CREATE DATABASE fleet_planner CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fleet_planner'@'%' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON fleet_planner.* TO 'fleet_planner'@'%';
FLUSH PRIVILEGES;
```

Jika MySQL sudah berjalan sebagai container di stack lain, gunakan host/container name yang reachable dari network `workspace_local-dev` sebagai `DB_HOST`.

## 3. Buat File `.env`

Copy template production:

```bash
cp .env.example .env
```

Edit `.env`:

```bash
nano .env
```

Minimal isi/cek value berikut:

```dotenv
APP_NAME="Fleet Planner"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_TIMEZONE=Asia/Makassar
APP_URL=https://fleet.nusantaraabadijaya.com

DB_CONNECTION=mysql
DB_HOST=nama-host-mysql-production
DB_PORT=3306
DB_DATABASE=fleet_planner
DB_USERNAME=fleet_planner
DB_PASSWORD=GANTI_PASSWORD_KUAT

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
TRAEFIK_CERT_RESOLVER=letsencrypt
```

Jangan commit file `.env` ke Git.

## 4. Build Docker Image

Build image Fleet Planner:

```bash
docker compose build fleet-planner-app
```

Build ini akan:

1. Menjalankan `npm ci`.
2. Menjalankan `npm run build` untuk asset Vite/React/TypeScript.
3. Menjalankan `composer install --no-dev --optimize-autoloader`.
4. Menyiapkan runtime FrankenPHP PHP 8.4.

## 5. Generate `APP_KEY`

Jika `APP_KEY` masih kosong, generate key dari image yang baru dibuat:

```bash
docker compose run --rm fleet-planner-app php artisan key:generate --show
```

Copy output key, lalu paste ke `.env`:

```dotenv
APP_KEY=base64:HASIL_KEY_DARI_COMMAND
```

Setelah mengubah `.env`, lanjut start container.

## 6. Jalankan Container

Start service Fleet Planner:

```bash
docker compose up -d
```

Cek status:

```bash
docker compose ps
```

Pastikan service `fleet-planner-app` statusnya `running` atau `healthy`.

## 7. Jalankan Migration

Migration terbaru project ini mencakup tabel master data, units, planning items, unit plannings, work orders, work order items, high usage, approvals, notifications, dan transfer site/unit. Jalankan migration production:

```bash
docker compose exec fleet-planner-app php artisan migrate --force
```

Jika ini deployment pertama dan data awal/role/master data diperlukan, cek daftar seeder dulu:

```bash
docker compose exec fleet-planner-app php artisan db:seed --class=DatabaseSeeder --force
```

Jalankan seeder hanya jika owner memang ingin mengisi data awal. Jangan jalankan seeder di database production yang sudah berisi data tanpa memastikan isi seeder aman/idempotent.

## 8. Buat Storage Link

Jika aplikasi memakai file publik dari storage, jalankan:

```bash
docker compose exec fleet-planner-app php artisan storage:link
```

Jika muncul pesan link sudah ada, itu aman.

## 9. Refresh Cache Laravel Setelah Perubahan Env

Entrypoint container otomatis menjalankan cache production saat start. Jika `.env` berubah saat container sedang hidup, jalankan:

```bash
docker compose exec fleet-planner-app php artisan optimize:clear
docker compose restart fleet-planner-app
```

Saat restart, entrypoint akan membuat ulang `config:cache`, `route:cache`, dan `view:cache` memakai env terbaru.

> Penting: karena `config:cache` memakai nilai `.env`, restart container setiap kali `APP_URL`, `DB_*`, session, cache, queue, atau mail env berubah.

## 10. Cek Traefik Routing Dan SSL

Compose sudah memasang label Traefik berikut:

- Router: `fleet-planner`
- Rule: `Host(`fleet.nusantaraabadijaya.com`)`
- Entrypoint: `websecure`
- TLS: enabled
- Cert resolver: `${TRAEFIK_CERT_RESOLVER:-letsencrypt}`
- Backend port: `8080`
- Network: `workspace_local-dev`

Cek Traefik melihat container:

```bash
docker logs <nama-container-traefik> --tail=100
```

Jika Traefik dashboard tersedia, pastikan router `fleet-planner` muncul dan service mengarah ke `fleet-planner-app:8080`.

Cek domain dari browser:

```text
https://fleet.nusantaraabadijaya.com
```

Jika SSL belum keluar, pastikan DNS sudah benar, port 80/443 VPS terbuka, dan cert resolver Traefik existing aktif.

## 11. Troubleshooting

### Cek log aplikasi

```bash
docker compose logs -f fleet-planner-app
```

### Cek log Laravel di volume storage

```bash
docker compose exec fleet-planner-app tail -n 100 storage/logs/laravel.log
```

### Cek env terbaca di container

```bash
docker compose exec fleet-planner-app php artisan about
```

### Cek koneksi database

```bash
docker compose exec fleet-planner-app php artisan migrate:status
```

Jika error `SQLSTATE[HY000] [2002]`, cek `DB_HOST`, network Docker, firewall MySQL, dan user MySQL boleh connect dari container.

### Rebuild setelah pull kode terbaru

```bash
git pull origin main
docker compose build fleet-planner-app
docker compose up -d
docker compose exec fleet-planner-app php artisan migrate --force
```

### Restart bersih service Fleet saja

```bash
docker compose restart fleet-planner-app
```

### Stop service Fleet saja

```bash
docker compose down
```

Perintah ini hanya mematikan service dari folder compose Fleet Planner. Jangan menjalankan `docker compose down` dari folder ERP/HR/recruitment kecuali memang ingin mematikan service tersebut.

## 12. Batasan Scope

- Jangan edit konfigurasi Traefik existing kecuali owner memang perlu menambah resolver/entrypoint global.
- Jangan edit compose ERP, HR portal, atau recruitment system.
- Jangan membuat network baru; gunakan network eksternal existing `workspace_local-dev`.
- Jangan membuat container MySQL baru dari project ini; gunakan MySQL production existing/shared.
- Semua command di dokumen ini dijalankan manual oleh owner di VPS, bukan dari environment Codex lokal.
