# Functional Specification Document
# Fleet Maintenance Planner — PT Nirwana Anugerah Jaya

**Versi:** 1.0  
**Tanggal:** Juni 2026  
**Status:** Draft  

**Update 7 Juli 2026:** struktur role dikonsolidasi dari 6 menjadi 4 role (Superadmin, Mekanik, Planner Area, Spv HO).

---

## Daftar Isi

1. [Overview](#1-overview)
2. [Aktor & Role](#2-aktor--role)
3. [Master Data](#3-master-data)
4. [Input Harian](#4-input-harian)
5. [Logika Trigger Task](#5-logika-trigger-task)
6. [Status & Lifecycle Task](#6-status--lifecycle-task)
7. [Work Order (WO)](#7-work-order-wo)
8. [Proyeksi](#8-proyeksi)
9. [Notifikasi](#9-notifikasi)
10. [Laporan & History](#10-laporan--history)
11. [Validasi & Logika Sistem](#11-validasi--logika-sistem)
12. [Tech Stack](#12-tech-stack)
13. [Database Schema](#13-database-schema)

---

## 1. Overview

### Tujuan
Aplikasi **Fleet Maintenance Planner** adalah sistem web untuk menggantikan Excel planner yang digunakan PT Nirwana Anugerah Jaya (PT NAJ) dalam mengelola maintenance kendaraan di 18 lokasi operasional.

### Ruang Lingkup
- Tracking dan eksekusi 20 item maintenance standar per unit
- Planning berbasis KM dan waktu
- Deteksi kondisi abnormal (High Usage, Blocked, Breakdown)
- Proyeksi kebutuhan maintenance 1–3 bulan ke depan
- Workflow approval antar role

### Batasan
- Aplikasi berdiri sendiri (standalone), tidak terintegrasi langsung dengan ERP NAJ
- Data unit dapat di-sync dari database sumber secara manual (direct DB connection, read-only)
- Tidak ada manajemen stok spare part
- Notifikasi hanya in-app, update saat halaman di-refresh

---

## 2. Aktor & Role

### Hierarki Role

| Role | Deskripsi |
|---|---|
| **Superadmin** | Full access sistem, manage semua user dan role |
| **Mekanik** | Input KM harian, report kondisi unit, update task Complete |
| **Planner Area** | Akses unit di lokasi sendiri, review task, input kondisi unit, submit Replace/Postpone/Blocked, report Breakdown, assign mekanik |
| **Spv HO** | Approve/reject semua jenis task dan action, kelola master data, lihat proyeksi global, terima notifikasi kebutuhan part |

### Hak Akses per Role

| Fitur | Superadmin | Planner Area | Spv HO | Mekanik |
|---|---|---|---|---|
| Master data | ✓ | — | ✓ | — |
| Manage user | ✓ | — | — | — |
| Input KM harian | ✓ | ✓ (area sendiri) | — | ✓ |
| Review task | ✓ | ✓ (area sendiri) | ✓ | — |
| Submit Replace/Postpone/Blocked | ✓ | ✓ (area sendiri) | — | — |
| Report Breakdown | ✓ | ✓ (area sendiri) | — | ✓ |
| Assign mekanik | ✓ | ✓ (area sendiri) | — | — |
| Approve/reject task | ✓ | — | ✓ | — |
| Complete task + input KM | ✓ | ✓ (area sendiri) | — | ✓ |
| Lihat proyeksi global | ✓ | — | ✓ | — |
| Lihat proyeksi per area | ✓ | ✓ (area sendiri) | ✓ | — |
| Lihat kebutuhan part | ✓ | — | ✓ | — |
| Lihat laporan | ✓ | ✓ (area sendiri) | ✓ | ✓ (terbatas) |

---

## 3. Master Data

### 3.1 Data Unit
Setiap unit kendaraan memiliki data:
- Nomor polisi (aktif)
- History nomor polisi lama
- Tipe/merk kendaraan
- Tahun pembuatan
- Lokasi/site
- Customer/perusahaan pengguna
- Status unit (Aktif, Breakdown)
- Odometer terakhir

**Aturan:**
- Hanya Superadmin dan Spv HO yang dapat tambah/edit/nonaktifkan unit
- Perubahan nomor polisi menyimpan history plat lama beserta tanggal perubahan
- Unit dengan KM < 50.000 otomatis diberi flag **Warranty**

### 3.2 20 Item Maintenance Standar
Update real-data: item **Ban** dipecah menjadi **Ban Depan**, **Ban Belakang**, dan **Ban Serep** karena pola pemakaian berbeda signifikan. Interval default berlaku sebagai fallback; beberapa item dapat memiliki override interval per kategori kendaraan.

| No | Item |
|---|---|
| 1 | PM Check / Reguler Services |
| 2 | Service A |
| 3 | Service B |
| 4 | Brake Pad |
| 5 | Brake Shoe |
| 6 | Accu |
| 7 | Kampas Kopling Set |
| 8 | Wiper Blade |
| 9 | Ban Depan |
| 10 | Ban Belakang |
| 11 | Ban Serep |
| 12 | Greasing |
| 13 | V-Belt |
| 14 | Sarung Jok |
| 15 | Karpet Karet |
| 16 | Karpet Dasar |
| 17 | Flushing Radiator |
| 18 | Flushing Steering |
| 19 | Flushing Injector |
| 20 | Flushing Rem |

Setiap item memiliki:
- `interval_km` — jarak KM antar penggantian
- `interval_days` — jarak hari antar penggantian

Nilai `interval_days` awal mengikuti data histori Excel existing. Item yang belum memiliki bukti histori memakai nilai sementara yang perlu dikonfirmasi PT NAJ. Nilai `interval_km` adalah estimasi awal bengkel dan juga perlu konfirmasi.

### 3.3 Lokasi Operasional
Lokasi/site operasional mengikuti 18 site asli dari data existing: ADARO, BPN, GORONTALO, KENDARI, LOA KULU, LOAJANAN, LOREH, M. LAWA, MAKASSAR, MANADO, MKS, SANGA SANGA, SANGATTA, SMD, SOROAKO, TABANG, TGR, TJ. REDEB.

### 3.3 Threshold (Dapat Dikonfigurasi)
Disimpan di master data, dapat diubah oleh Superadmin dan Spv HO:
- **Warning KM** — berapa KM sebelum due_km task mulai muncul
- **Warning hari** — berapa hari sebelum due_date task mulai muncul
- **Threshold High Usage** — persentase perubahan avg KM/hari yang dianggap signifikan
- **Minimum data inspeksi** — jumlah minimum inspeksi untuk kalkulasi avg KM/hari yang valid (default: 3)

### 3.4 Setup Awal Per Unit
Setelah unit ditambahkan, Spv HO atau Planner Area wajib input:
- `last_done_km` per item
- `last_done_date` per item

Sistem otomatis hitung:
```
next_due_km   = last_done_km + interval_km
next_due_date = last_done_date + interval_days
```

---

## 4. Input Harian

### 4.1 Input KM oleh Mekanik
Setiap hari, Mekanik input:
- Nama Mekanik (dari login)
- Unit (pilih dari daftar unit di areanya)
- Tanggal
- KM saat ini

**Validasi:**
- KM baru harus lebih besar dari KM terakhir yang tercatat
- Jika KM lebih kecil → sistem tampilkan warning, input ditolak
- Satu unit hanya boleh diinput 1 kali per hari
- Jika mekanik salah input, mekanik yang bersangkutan boleh membatalkan input miliknya sendiri di hari yang sama, lalu input ulang
- Unit yang sudah diinput hari ini tidak muncul lagi di daftar pilihan Input KM sampai input tersebut dibatalkan
- Jika jumlah inspeksi untuk unit tersebut kurang dari threshold minimum → sistem tampilkan warning "Data inspeksi unit ini masih sedikit, perhitungan avg KM/hari belum akurat"

### 4.2 Trigger Kalkulasi
Setiap ada input KM baru → sistem otomatis:
1. Update odometer unit
2. Hitung progress 20 item
3. Cek kondisi trigger (Normal, High Usage)
4. Generate task jika threshold tercapai
5. Deteksi status Breakdown berakhir (jika unit sebelumnya Breakdown)

---

## 5. Logika Trigger Task

### 5.1 Kondisi Normal
Task otomatis dibuat saat salah satu kondisi terpenuhi (mana yang lebih dulu):

```
current_odo >= next_due_km - warning_km
ATAU
today >= next_due_date - warning_days
```

### 5.2 Kondisi High Usage
Sistem mendeteksi perubahan signifikan pada rata-rata pemakaian KM per hari.

**Kalkulasi avg KM/hari:**
```
avg_km_per_day = (odo_terakhir - odo_pertama) / jumlah_hari
```
Minimum data: sesuai threshold minimum inspeksi di master data.  
Jika data kurang → warning ditampilkan, High Usage tidak dideteksi.

**Deteksi High Usage:**
Jika avg KM/hari terkini naik signifikan (melebihi threshold di master data) sehingga estimasi due date jauh lebih cepat dari perkiraan:

```
estimasi_due = (next_due_km - current_odo) / avg_km_per_day
```

Jika `estimasi_due` jauh lebih pendek dari sisa hari normal → flag High Usage.

**Flow High Usage:**

- **5 hari pertama sejak flag:**
  - Notifikasi ke Planner Area
  - Planner Area putuskan: jadikan task sekarang atau tunggu trigger normal
  - Jika Ya → task dibuat, butuh Spv HO approve
  - Jika Tidak → sistem monitor terus

- **5 hari kedua (setelah 5 hari pertama tidak ada tindakan):**
  - Planner Area wajib set: kapan unit bisa dipegang, due KM baru, due date baru
  - Diajukan ke Spv HO untuk approve
  - Setelah approve → task dibuat

High Usage berlaku untuk semua 20 item.

### 5.3 Kondisi Blocked
- Input manual oleh Planner Area atau Mekanik
- Alasan: unit tidak bisa dipegang customer
- Due date dan KM **tetap berjalan** — tidak ada freeze
- Status Blocked hanya sebagai label informasi pada task
- Task tetap bisa menjadi overdue

### 5.4 Kondisi Breakdown
- Input manual oleh Planner Area atau Mekanik
- Alasan: unit rusak/mogok, tidak beroperasi
- Due date (waktu) **freeze** karena unit tidak jalan
- KM juga freeze karena unit tidak bergerak
- **Unfreeze otomatis** saat ada input KM baru untuk unit tersebut → sistem deteksi unit aktif kembali
- Hari freeze ditambahkan ke `next_due_date`

**Inspeksi wajib setelah Breakdown:**  
Setelah unit aktif kembali, Planner Area atau Mekanik wajib input:
- Part apa yang diganti saat breakdown
- Sistem update `last_done_km` dan `last_done_date` untuk item tersebut
- Baru cycle lanjut normal

### 5.5 Warranty (KM < 50.000)
- Task muncul dengan label/flag **"Warranty"**
- Flow: Planner Area → Spv HO approve → Planner Area koordinasi ke dealer
- Tidak ada notifikasi kebutuhan part ke Spv HO (part dari dealer)
- Selesai di dealer → Planner Area input Complete + KM → cycle reset normal
- Saat KM ≥ 50.000 → flag Warranty hilang otomatis, flow normal

---

## 6. Status & Lifecycle Task

### 6.1 Status Task

| Status | Definisi |
|---|---|
| **On Hold** | Task muncul, belum diaction Planner Area |
| **Blocked** | Unit tidak bisa dipegang customer — due tetap berjalan |
| **Breakdown** | Unit rusak — due di-freeze |
| **Replace** | Diajukan untuk diganti, menunggu Spv HO approve |
| **Postpone** | Diajukan untuk ditunda, menunggu Spv HO approve |
| **In Progress** | Approved oleh Spv HO, sedang dikerjakan Mekanik |
| **Complete** | Selesai dikerjakan, KM penggantian sudah diinput |
| **Overdue** | Melewati due date/KM tanpa action |

### 6.2 Lifecycle Lengkap

```
Task muncul (On Hold)
↓
Planner Area review kondisi fisik unit
↓
Pilih action:

[Replace]
→ Diajukan ke Spv HO
→ Spv HO approve → status In Progress → Spv HO notif (siapkan part)
→ Mekanik eksekusi
→ Planner Area / Mekanik: Complete + input KM penggantian
→ Sistem update:
    last_done_km   = KM saat complete
    last_done_date = tanggal complete
    next_due_km    = last_done_km + interval_km
    next_due_date  = last_done_date + interval_days
→ Cycle ulang

[Postpone]
→ Planner Area input: alasan + next_due_km baru + next_due_date baru
→ Diajukan ke Spv HO
→ Spv HO approve → next_due bergeser sesuai input Planner Area
→ Task tertutup, muncul lagi saat next_due baru tercapai
→ History tercatat: alasan penundaan, due lama, due baru

[Blocked]
→ Planner Area / Mekanik input: alasan
→ Status task berubah ke Blocked
→ Due date tetap berjalan
→ Task bisa overdue
→ Saat unit bisa dipegang → Planner Area update status → flow Replace/Postpone

[Breakdown]
→ Planner Area / Mekanik input: unit Breakdown
→ Due date freeze
→ Input KM baru → sistem deteksi unit aktif kembali → freeze selesai
→ Planner Area / Mekanik input inspeksi: part yang diganti saat breakdown
→ Sistem update last_done item tersebut
→ Cycle lanjut normal
```

### 6.3 WO (Work Order)
- Satu WO dapat berisi **multiple item** yang due di waktu yang sama untuk satu unit (satu kunjungan)
- Complete dilakukan **per item** — mekanik bisa complete sebagian item jika ada yang belum bisa dikerjakan
- Setiap item yang complete reset interval masing-masing secara independen

---

## 7. Work Order (WO)

### 7.1 Pembuatan WO
- WO otomatis terbuat saat satu atau lebih item pada satu unit mencapai threshold
- WO juga bisa dibuat manual oleh Planner Area untuk: breakdown mendadak, temuan mekanik, kondisi darurat
- Satu WO = satu unit = satu kunjungan = bisa banyak item

### 7.2 Kanban Board
Tampilan utama task dalam bentuk Kanban:

| On Hold | In Progress | Complete |
|---|---|---|
| Task menunggu action | Task sudah approved, sedang dikerjakan | Task selesai |

Filter tersedia:
- Per lokasi/site
- Per unit
- Per item
- Per assignee
- Per status

### 7.3 Tampilan
- Warna dan ikon sebagai indikator status — minimalisir teks panjang
- Badge Warranty untuk unit KM < 50.000
- Badge Overdue untuk task yang melewati due date/KM
- Badge High Usage untuk task yang di-trigger kondisi High Usage

---

## 8. Proyeksi

### 8.1 Kalkulasi
```
avg_km_per_day = (odo_terakhir - odo_pertama) / jumlah_hari_inspeksi

est_odo_akhir_periode = current_odo + (avg_km_per_day × sisa_hari_periode)

Item masuk proyeksi jika:
- next_due_km <= est_odo_akhir_periode, ATAU
- next_due_date <= tanggal_akhir_periode
```

Jika data inspeksi kurang dari threshold minimum → tampilkan warning "Data tidak cukup untuk proyeksi akurat".

### 8.2 Periode
Pengguna pilih periode: **1 bulan / 2 bulan / 3 bulan**

### 8.3 View Proyeksi

**View Jadwal (per unit):**
Semua item yang akan due untuk satu unit dalam periode yang dipilih.

**View per Item:**
Semua unit yang akan due untuk satu item tertentu.

**View Part:**
Dikelompokkan per part, dengan estimasi quantity:
```
Ban 265/70 R17
├── KT 8404 YR │ Site BPN │ Due: 15 Juli │ Est. qty: 4
├── KT 8620 YR │ Site BPN │ Due: 22 Juli │ Est. qty: 2
└── KT 8154 YH │ Site SGT │ Due: 28 Juli │ Est. qty: 4
Total estimasi: 10 pcs
```
Quantity adalah **estimasi dasar** — bukan angka pasti. Quantity aktual diketahui saat mekanik eksekusi.

### 8.4 Akses Proyeksi
- **Superadmin** → semua area dan view part
- **Spv HO** → proyeksi global, semua area, drill down per site, dan view part
- **Planner Area** → proyeksi area sendiri

### 8.5 Sifat
- Real-time — update otomatis saat ada: task complete, postpone, inspeksi baru, blocked/unblocked
- Bukan snapshot

---

## 9. Notifikasi

Notifikasi bersifat **in-app** — ditampilkan saat halaman di-refresh. Tidak ada push notification atau WhatsApp.

| Event | Notifikasi ke |
|---|---|
| Task auto-generated (On Hold) | Planner Area |
| High Usage terdeteksi | Planner Area |
| Task diajukan (Replace/Postpone/Blocked) | Spv HO |
| Task approved (Replace) | Spv HO |
| Task overdue | Spv HO |
| Unit Breakdown diinput | Spv HO |
| High Usage 5 hari kedua (belum ada tindakan) | Planner Area + Spv HO |

---

## 10. Laporan & History

### 10.1 History per Unit
- Riwayat penggantian seluruh 20 item
- Riwayat nomor polisi (plat lama dan baru beserta tanggal)
- Riwayat status (Blocked, Breakdown)
- Riwayat penundaan (Postpone) beserta alasan

### 10.2 Laporan
- Rekap WO per bulan per area
- Rekap per item (item mana yang paling sering due)
- Rekap per unit (unit mana yang paling banyak task)
- Rekap overdue per area

### 10.3 Akses Laporan
Semua role dapat mengakses laporan dengan level detail yang berbeda:
- Mekanik → hanya unit yang pernah mereka tangani
- Planner Area → hanya area mereka
- Spv HO → semua area, termasuk fokus part dan kebutuhan
- Superadmin → semua area

---

## 11. Validasi & Logika Sistem

### 11.1 Input KM
- KM baru harus > KM terakhir yang tercatat → jika tidak, warning + tolak input
- Satu unit hanya boleh diinput 1 kali per hari
- Input hari ini bisa dibatalkan dan diinput ulang oleh mekanik yang bersangkutan di hari yang sama
- Jika jumlah inspeksi < threshold minimum → warning "Data inspeksi masih sedikit"

### 11.2 Breakdown Detection
- Breakdown berakhir otomatis saat ada input KM baru untuk unit yang berstatus Breakdown
- Sistem tambahkan jumlah hari freeze ke `next_due_date` semua item yang freeze
- Planner Area / Mekanik wajib input inspeksi breakdown sebelum task lanjut normal

### 11.3 High Usage
- Dihitung dari histori inspeksi — minimum sesuai threshold master data
- Jika data kurang → High Usage tidak dideteksi, hanya warning
- 5 hari dihitung sejak pertama kali flag High Usage muncul

### 11.4 Concurrency
- Jika unit sudah memiliki input KM pada tanggal yang sama, input berikutnya ditolak sampai input hari itu dibatalkan

### 11.5 Warranty
- Flag Warranty otomatis aktif saat KM < 50.000
- Flag hilang otomatis saat KM ≥ 50.000
- Threshold warranty: **50.000 KM** (fixed, tidak bisa diubah di master data)

---

## 12. Tech Stack

| Layer | Teknologi |
|---|---|
| Backend | Laravel 11 |
| Frontend | React + Inertia.js |
| Database | MySQL |
| Autentikasi | Laravel Sanctum |
| Background Job | Laravel Queue + Supervisor |
| Scheduler | Laravel Scheduler via Cron Job |
| Notifikasi | Database Notification (in-app) |
| Styling | Tailwind CSS |
| Web Server | Nginx |
| Hosting | VPS Hostinger |

---

## 13. Database Schema

### Tabel Utama

```sql
-- User dan role
users
  id, name, email, password, role, site_id, region_id,
  created_at, updated_at

-- Planner region operasional
regions
  id, name,
  created_at, updated_at

-- Lokasi/site
sites
  id, name, region, region_id,
  created_at, updated_at

-- Master unit kendaraan
units
  id, site_id, customer,
  current_plate, type, brand, vehicle_category, year,
  current_odo, status (active/breakdown),
  created_at, updated_at

-- History nomor polisi
unit_plate_histories
  id, unit_id, plate_number, active_from, active_until,
  created_at

-- 20 item standar
planning_items
  id, name, interval_km, interval_days,
  created_at, updated_at

-- Override interval per kategori kendaraan
planning_item_overrides
  id, planning_item_id, vehicle_category,
  interval_km nullable, interval_days nullable,
  created_at, updated_at

-- Threshold sistem (master data)
system_thresholds
  id, key, value, description,
  updated_by, updated_at

-- Start point per unit per item
unit_plannings
  id, unit_id, planning_item_id,
  last_done_km, last_done_date,
  next_due_km, next_due_date, is_estimated,
  created_at, updated_at

-- Work Order
work_orders
  id, unit_id, site_id,
  trigger_type (normal/high_usage/manual/breakdown),
  status (open/in_progress/complete/cancelled),
  submitted_by, approved_by, approved_at,
  notes, created_at, updated_at

-- Item dalam WO
work_order_items
  id, work_order_id, unit_planning_id, planning_item_id,
  action (replace/postpone/blocked/breakdown),
  status (on_hold/in_progress/complete/postponed/blocked/breakdown/overdue),
  reason, notes,
  new_due_km, new_due_date,
  freeze_start, freeze_end,
  completed_odo, completed_date,
  submitted_by, approved_by, approved_at,
  created_at, updated_at

-- Log inspeksi KM harian
inspection_logs
  id, unit_id, mechanic_id,
  inspection_date, odometer,
  created_at

-- Notifikasi
notifications
  id, user_id, type, title, message,
  data (JSON), read_at,
  created_at
```

---

*Dokumen ini adalah hasil diskusi dan belum final. Setiap business logic perlu dikonfirmasi ulang sebelum development dimulai.*

*Versi: 1.0 — Juni 2026*

---

## Update 2026-07-09 — Region Planner Area

- Planner Area sekarang di-scope per Region, bukan per Site.
- Region awal: Kalimantan dan Sulawesi.
- Kalimantan mencakup BPN, SMD, M. LAWA, LOREH, LOAJANAN, LOA KULU, SANGA SANGA, TGR, TJ. REDEB, ADARO, TABANG, dan SANGATTA.
- Sulawesi mencakup MANADO, SOROAKO, KENDARI, GORONTALO, MAKASSAR, dan MKS.
- User Planner Area memakai `users.region_id`; `users.site_id` tetap dipakai untuk Mekanik.
- Mekanik tetap scoped 1 site seperti desain sebelumnya.
- Superadmin dan Spv HO tetap dapat melihat semua region dan site.
