# Seeder Scenarios — Fleet Maintenance Planner

Tanggal pemeriksaan data: 2026-07-07  
Target UAT: `http://127.0.0.1:8001`  
Database: SQLite aplikasi lokal `/Users/mac/Documents/fleet-planner`

> Catatan: data ini diambil dari database sebelum dan selama UAT browser. UAT sudah membuat/approve WO manual `#11` dan complete WO `#1`, sehingga beberapa status sudah berubah dari baseline seed awal. Threshold aktif: `upcoming_days=28`, `ancang_ancang_days=14`, `warning_days=7`, `upcoming_km=2000`, `ancang_ancang_km=1000`, `warning_km=500`, `min_inspection_data=3`.

| # | Skenario | Unit / Plat | Site | Data pendukung | Akun uji |
|---|---|---|---|---|---|
| 1 | Unit Warranty (KM < 50.000) | `BPN-001` | Site Balikpapan | Odometer awal `48.200`, setelah UAT complete `48.600`; tetap `< 50.000 KM`. Badge `Warranty` terlihat di detail WO `#1`. | `planner.balikpapan@example.com`, `mekanik.balikpapan@example.com` |
| 2 | Unit High Usage Window 1 | `SMR-001` | Site Samarinda | `high_usage_flags.id=1`, item `Ban`, avg `260 KM/hari`, estimated due `2 hari`, `flagged_at=2026-07-07 02:19:46`, belum ada action. | `planner.samarinda@example.com` |
| 3 | Unit High Usage Window 2 | `PKU-001` | Site Pekanbaru | `high_usage_flags.id=2`, item `Ban`, avg `240 KM/hari`, estimated due `2 hari`, `flagged_at=2026-07-01 02:19:46`, `action_taken=deferred`. | `planner.pekanbaru@example.com` |
| 4 | Unit Breakdown (freeze aktif) | `JKT-001` | Site Jakarta | Unit status `breakdown`; WO `#3` dan `#7`, item `Service B`, item status `breakdown`, `freeze_start` aktif. | `planner.jakarta@example.com`, `mekanik.jakarta@example.com` |
| 5 | Unit dengan WO Item Blocked | `MKS-001` | Site Makassar | WO `#4` dan `#8`, item `Accu`, item status `blocked`, reason `Demo UAT: sparepart belum tersedia.` | `planner.makassar@example.com` |
| 6 | Unit Overdue | `MDN-001` | Site Medan | WO `#6` dan `#10`, item `Wiper Blade`, item status `overdue`; due `102.100 KM / 2026-07-04`, current odo `102.200`. | `planner.medan@example.com`, `spv_ho@example.com` |
| 7 | Unit dengan WO Complete | `SBY-001` | Site Surabaya | WO `#5` dan `#9`, item `Greasing`, item status `complete`, completed `67.800 KM` pada `2026-07-06`. UAT juga membuat WO `#1` BPN-001 complete. | `planner.surabaya@example.com` |
| 8 | Unit di kolom preview Upcoming | `BPN-002` | Site Balikpapan | Item `Service A`, setelah UAT postpone due `85.000 KM / 2026-07-31`, current odo `76.300`, sisa `24 hari`; masuk window `upcoming_days=28`. | `planner.balikpapan@example.com` |
| 9 | Unit di kolom preview Ancang-ancang | `BJM-002` | Site Banjarmasin | Item `Service A`, due `50.200 KM / 2026-07-19`, current odo `45.200`, sisa `12 hari`; masuk window `ancang_ancang_days=14`. | `planner.banjarmasin@example.com` |
| 10 | Unit dengan data inspeksi sangat sedikit | `DPS-001` | Site Denpasar | Jumlah inspeksi `2`, di bawah `min_inspection_data=3`; current odo `61.200`, avg km/hari `NULL`; warning muncul di Projections. | `planner.denpasar@example.com`, `spv_ho@example.com` |

## Akun

- Superadmin: `superadmin@example.com` / `123123`
- Spv HO: `spv_ho@example.com` / `123123`
- Planner Area: `planner.<site>@example.com` / `123123`
- Mekanik: `mekanik.<site>@example.com` / `123123`

## Command Terkait

- Command overdue: `php artisan maintenance:check-overdue`
