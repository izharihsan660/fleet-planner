# Deployment Fleet Planner ke VPS Hostinger

Panduan ini menyiapkan **Fleet Planner** sebagai service baru di VPS yang sudah menjalankan ERP, HR portal, dan recruitment system dengan Docker + Traefik. Jangan ubah compose, network, atau konfigurasi Traefik milik aplikasi lain.

Target production:

- Domain: `https://fleet.nusantaraabadijaya.com`
- Runtime: Laravel 11, PHP 8.4, FrankenPHP
- Frontend: Vite + React + TypeScript, dibuild saat Docker image dibuat
- Reverse proxy: Traefik existing di network eksternal `workspace_local-dev`
- Database: MySQL 8.4 dedicated container `fleet-planner-mysql`

## File Yang Disiapkan

- `Dockerfile` — multi-stage build: Node build untuk asset Vite, Composer production install, runtime FrankenPHP PHP 8.4.
- `docker/entrypoint.sh` — membuat folder writable dan menjalankan `config:cache`, `route:cache`, `view:cache` saat container production start.
- `docker-compose.yml` — service `fleet-planner-app` dan MySQL dedicated `fleet-planner-mysql`; hanya app yang join network Traefik eksternal `workspace_local-dev`.
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
6. Tidak perlu MySQL shared/global. Compose Fleet Planner akan membuat container MySQL sendiri bernama `fleet-planner-mysql`.

> Catatan: jangan ubah container MySQL aplikasi lain seperti inventory/karir/ERP/HR/recruitment. MySQL Fleet Planner berdiri sendiri di compose project ini.

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

Fleet Planner memakai container MySQL dedicated yang otomatis dibuat oleh `docker-compose.yml`:

- Service: `fleet-planner-mysql`
- Image: `mysql:8.4`
- Volume data: `fleet_planner_mysql_data`
- Network: `fleet-planner-internal` saja, tidak diexpose ke host dan tidak join ke Traefik

Tidak perlu menjalankan `mysql` client manual untuk membuat database/user. Saat container MySQL pertama kali start, image MySQL akan otomatis membuat database dan user dari env berikut:

```dotenv
DB_DATABASE=fleet_planner
DB_USERNAME=fleet_planner
DB_PASSWORD=isi_password_user_database
MYSQL_ROOT_PASSWORD=isi_password_root_mysql
```

Pastikan `DB_PASSWORD` dan `MYSQL_ROOT_PASSWORD` diisi sebelum `docker compose up -d` pertama kali. Jika volume MySQL sudah pernah dibuat, perubahan env database tidak otomatis mengubah user/password lama; untuk reset total perlu backup lalu hapus volume secara sadar.

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
DB_HOST=fleet-planner-mysql
DB_PORT=3306
DB_DATABASE=fleet_planner
DB_USERNAME=fleet_planner
DB_PASSWORD=GANTI_PASSWORD_KUAT
MYSQL_ROOT_PASSWORD=GANTI_ROOT_PASSWORD_KUAT

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

Start service Fleet Planner dan MySQL dedicated:

```bash
docker compose up -d
```

Cek status:

```bash
docker compose ps
```

Pastikan service `fleet-planner-mysql` berstatus `healthy`, lalu `fleet-planner-app` berstatus `running` atau `healthy`.

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

Untuk setup ini, nilai normal adalah:

```dotenv
DB_HOST=fleet-planner-mysql
DB_PORT=3306
```

Cek health MySQL dedicated:

```bash
docker compose ps fleet-planner-mysql
docker compose logs -f fleet-planner-mysql
```

### Container app restart loop meski HTTP sukses

Image FrankenPHP default dapat mengaktifkan worker mode melalui `FRANKENPHP_CONFIG="worker ./public/index.php"`. Fleet Planner belum memakai Laravel Octane dan `public/index.php` standar tidak menjalankan loop `frankenphp_handle_request()`, sehingga worker mode bisa membuat container restart terus.

Fix permanen di repo:

- `docker-compose.yml` mengosongkan `FRANKENPHP_CONFIG` pada service `fleet-planner-app`.
- `Dockerfile` tidak boleh meng-hardcode `ENV FRANKENPHP_CONFIG=...` ke worker mode.

Validasi setelah pull perubahan:

```bash
docker compose config
```

### `storage:link` gagal permission denied

Jika `php artisan storage:link` gagal membuat symlink di `public/`, biasanya folder `public/` di image masih owned by `root` sementara proses PHP berjalan sebagai `www-data`.

Fix permanen di repo: `Dockerfile` menjalankan `chown -R www-data:www-data /app/public` pada final/runtime stage setelah semua `COPY` yang menyentuh folder `public/`, termasuk hasil build Vite.

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
- Jangan membuat network Traefik baru; app tetap memakai network eksternal existing `workspace_local-dev`.
- Jangan menyentuh container MySQL app lain seperti `laravel-mysql`, `karir-mysql`, ERP, HR, atau recruitment.
- MySQL baru yang dibuat hanya `fleet-planner-mysql` dari compose project Fleet Planner ini.
- Semua command di dokumen ini dijalankan manual oleh owner di VPS, bukan dari environment Codex lokal.
