# Project Context: Fleet Maintenance Planner

## Environment
- Project path: `~/Herd/fleet-planner`
- URL local: `http://fleet-planner.test`
- OS: macOS + Laravel Herd
- PHP: 8.4 (via Herd)
- Node: v26

## Tech Stack
- Backend: Laravel 11
- Frontend: React + TypeScript + Inertia.js
- Styling: Tailwind CSS v4
- Database: SQLite (local) / MySQL (production VPS Hostinger)
- Auth: Laravel Sanctum + Laravel Breeze
- Build tool: Vite

## Struktur Project
```
~/Herd/fleet-planner/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Models/
│   └── Providers/
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── database.sqlite
├── resources/
│   ├── css/
│   │   └── app.css
│   └── js/
│       ├── Components/
│       ├── Layouts/
│       │   ├── AuthenticatedLayout.tsx
│       │   └── GuestLayout.tsx
│       ├── Pages/
│       │   ├── Auth/
│       │   └── Dashboard.tsx
│       ├── types/
│       │   ├── index.d.ts
│       │   └── global.d.ts
│       └── app.tsx
├── routes/
│   ├── web.php
│   └── auth.php
└── .env
```

## Role Aplikasi
| Role | Deskripsi |
|---|---|
| Superadmin | Full access, manage user & role |
| Mekanik | Input KM harian, update task complete |
| Planner Area | Unit di area sendiri, review task, input kondisi unit, submit task, report Breakdown, assign mekanik |
| Spv HO | Approve/reject semua task dan action, master data, proyeksi global, notifikasi kebutuhan part |

## Konvensi Koding
- Gunakan TypeScript untuk semua file React (.tsx)
- Gunakan Inertia.js untuk semua navigasi dan form submission (bukan fetch/axios langsung)
- Gunakan Laravel Resource untuk semua response data
- Gunakan Form Request untuk semua validasi
- Gunakan Policy untuk semua authorization per role
- Penamaan komponen React: PascalCase
- Penamaan file React: PascalCase.tsx
- Penamaan file Laravel: snake_case
- Selalu update `types/index.d.ts` jika ada model baru

## Aturan Penting untuk Codex
- Jangan gunakan Vue.js — project ini pakai React
- Jangan gunakan API route terpisah — gunakan Inertia.js
- Jangan modifikasi file yang tidak diminta
- Selalu jalankan migration setelah membuat file migration baru
- Setiap prompt Codex harus spesifik satu task, tidak boleh sekaligus banyak fitur

## Development Phase
### Phase 1 (Current)
1. Auth & Role (RBAC 6 role)
2. Master Data (unit, 20 item, 18 site operasional, threshold, user)
3. Input KM Harian
4. Trigger & Task Normal
5. Lifecycle Task Normal (Replace → Approve → Complete → Reset)

### Phase 2 (Next)
- High Usage detection
- Blocked & Breakdown
- Proyeksi 1-3 bulan
- Laporan & history
- Warranty flag

## Real Data Update
- Site operasional mengikuti 18 lokasi dari Excel existing: ADARO, BPN, GORONTALO, KENDARI, LOA KULU, LOAJANAN, LOREH, M. LAWA, MAKASSAR, MANADO, MKS, SANGA SANGA, SANGATTA, SMD, SOROAKO, TABANG, TGR, TJ. REDEB.
- Planning item standar berjumlah 20; item Ban lama dipisah menjadi Ban Depan, Ban Belakang, dan Ban Serep.
- Interval maintenance dapat dioverride per kategori kendaraan: pickup_suv, truk_ringan, bus.
- Import data existing tersedia untuk Units dan Unit Plannings dari CSV; Unit Plannings diproses melalui queue job.

## Update 2026-07-09 — Region di atas Site

- PT NAJ hanya memakai 2 Planner Area operasional: Kalimantan dan Sulawesi.
- Planner Area tidak lagi 1 user per site; scope Planner Area adalah semua site dalam `regions` melalui `users.region_id`.
- Mekanik tetap 1 user per site melalui `users.site_id`.
- Pembagian site Region:
  - Kalimantan: BPN, SMD, M. LAWA, LOREH, LOAJANAN, LOA KULU, SANGA SANGA, TGR, TJ. REDEB, ADARO, TABANG, SANGATTA.
  - Sulawesi: MANADO, SOROAKO, KENDARI, GORONTALO, MAKASSAR, MKS.
- User demo Planner Area: `planner.kalimantan@example.com` dan `planner.sulawesi@example.com`, password `123123`.
