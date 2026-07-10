# Deployment Fleet Maintenance Planner

Panduan ini menyiapkan deployment Docker untuk Fleet Maintenance Planner dengan FrankenPHP, MySQL, queue worker, scheduler, dan Traefik sebagai reverse proxy. File ini hanya membahas deployment; kode aplikasi tidak perlu diubah.

## File deployment

- `Dockerfile`: multi-stage build untuk aset frontend dan runtime Laravel di FrankenPHP.
- `docker-compose.yml`: service `app`, `mysql`, `queue`, dan `scheduler` dengan label Traefik.
- `.env.production.example`: template environment production.
- `.dockerignore`: mengecualikan file lokal yang tidak perlu masuk image.

## Prasyarat VPS

- Docker Engine dan Docker Compose plugin sudah terpasang.
- Traefik sudah berjalan di network Docker eksternal `workspace_local-dev`.
- DNS domain sudah mengarah ke IP VPS.
- Resolver sertifikat Traefik, misalnya `letsencrypt`, sudah dikonfigurasi di Traefik.

Jika network Traefik belum ada, buat sekali di VPS:

```bash
docker network create workspace_local-dev
```

## Setup awal

Clone atau salin project ke VPS, lalu masuk ke folder project:

```bash
cd /var/www/fleet-planner
```

Buat file environment production dari template:

```bash
cp .env.production.example .env.production
```

Edit `.env.production`, minimal isi nilai berikut:

```dotenv
APP_KEY=
APP_URL=https://domain-anda.com
APP_DOMAIN=domain-anda.com
DB_PASSWORD=password-kuat
DB_ROOT_PASSWORD=password-root-kuat
TRAEFIK_CERT_RESOLVER=letsencrypt
```

Semua command Docker Compose di panduan ini memakai `--env-file .env.production` agar variabel seperti `APP_DOMAIN`, `DB_DATABASE`, dan password database ikut dipakai saat Compose membaca konfigurasi.

Generate `APP_KEY` di dalam container setelah image berhasil dibangun:

```bash
docker compose --env-file .env.production build app
docker compose --env-file .env.production run --rm app php artisan key:generate --show
```

Salin hasil key ke `APP_KEY` di `.env.production`.

## Menjalankan stack

Build dan jalankan semua service:

```bash
docker compose --env-file .env.production up -d --build
```

Cek status container:

```bash
docker compose --env-file .env.production ps
```

Jalankan migrasi database production:

```bash
docker compose --env-file .env.production exec app php artisan migrate --force
```

Buat symbolic link storage publik:

```bash
docker compose --env-file .env.production exec app php artisan storage:link
```

Optimalkan cache Laravel untuk production:

```bash
docker compose --env-file .env.production exec app php artisan optimize
```

Jika ada perubahan kode setelah deploy, restart worker agar membaca kode terbaru:

```bash
docker compose --env-file .env.production exec app php artisan queue:restart
docker compose --env-file .env.production restart app queue scheduler
```

## Traefik

`docker-compose.yml` memakai label berikut:

- Router: `fleet-planner`
- Host rule: ``Host(`${APP_DOMAIN}`)``
- Entrypoint: `websecure`
- TLS resolver: `${TRAEFIK_CERT_RESOLVER:-letsencrypt}`
- Container port: `8080`
- Network eksternal: `workspace_local-dev`

Pastikan service Traefik berada di network yang sama:

```bash
docker network inspect workspace_local-dev
```

Jika Traefik memakai nama entrypoint atau cert resolver berbeda, sesuaikan nilai label di `docker-compose.yml` atau variabel `TRAEFIK_CERT_RESOLVER`.

## Queue dan scheduler

Service `queue` menjalankan:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=120
```

Service `scheduler` menjalankan `php artisan schedule:run` setiap menit. Keduanya memakai image yang sama dengan app dan volume storage/cache yang sama.

Lihat log worker:

```bash
docker compose --env-file .env.production logs -f queue scheduler
```

## Migrasi data awal

Production menggunakan MySQL kosong. Data lokal tidak otomatis masuk ke image Docker.

Jika perlu mengimpor data lama, gunakan fitur import aplikasi atau command project yang memang sudah tersedia. Jalankan hanya setelah migrasi production berhasil dan user admin sudah siap.

## Backup database

Buat folder backup di host:

```bash
mkdir -p /var/backups/fleet-planner
```

Backup manual:

```bash
docker compose --env-file .env.production exec -T mysql mysqldump -u root -p"CHANGE_ME_ROOT_PASSWORD" fleet_planner > /var/backups/fleet-planner/fleet_$(date +%F_%H-%M).sql
```

Contoh cron harian pukul 02:00:

```cron
0 2 * * * cd /var/www/fleet-planner && docker compose --env-file .env.production exec -T mysql mysqldump -u root -p"CHANGE_ME_ROOT_PASSWORD" fleet_planner > /var/backups/fleet-planner/fleet_$(date +\%F_\%H-\%M).sql
```

Simpan backup penting di lokasi terpisah seperti object storage atau server backup lain.

## Troubleshooting

Cek log app dan database:

```bash
docker compose --env-file .env.production logs -f app mysql
```

Clear cache Laravel jika konfigurasi berubah:

```bash
docker compose --env-file .env.production exec app php artisan optimize:clear
docker compose --env-file .env.production exec app php artisan optimize
```

Rebuild image setelah update dependency:

```bash
docker compose --env-file .env.production build --no-cache app
docker compose --env-file .env.production up -d
```

Jika domain belum bisa diakses, cek tiga hal dulu: DNS domain, network `workspace_local-dev`, dan log Traefik.
