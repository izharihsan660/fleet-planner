# Functional Specification Document
# Fleet Maintenance Planner — PT Nirwana Anugerah Jaya

**Versi:** 1.0  
**Tanggal:** Juni 2026  
**Status:** Draft  

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
Aplikasi **Fleet Maintenance Planner** adalah sistem web untuk menggantikan Excel planner yang digunakan PT Nirwana Anugerah Jaya (PT NAJ) dalam mengelola maintenance 279 unit kendaraan di 15 lokasi.

### Ruang Lingkup
- Tracking dan eksekusi 18 item maintenance standar per unit
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
| **Admin Planner HO** | Full access semua area, kelola master data, lihat proyeksi global |
| **Admin Site** | Akses unit di lokasi sendiri, review task, input kondisi unit |
| **Spv Ops** | Approve/reject semua jenis task dan action |
| **Logistik** | Lihat task approved dan proyeksi kebutuhan part |
| **Mekanik** | Input KM harian, report kondisi unit, update task Complete |

### Hak Akses per Role

| Fitur | Superadmin | Planner HO | Admin Site | Spv Ops | Logistik | Mekanik |
|---|---|---|---|---|---|---|
| Master data | ✓ | ✓ | — | — | — | — |
| Manage user | ✓ | — | — | — | — | — |
| Input KM harian | ✓ | — | — | — | — | ✓ |
| Review task | ✓ | ✓ | ✓ (area sendiri) | ✓ | — | — |
| Submit Replace/Postpone/Blocked | ✓ | — | ✓ | — | — | — |
| Report Breakdown | ✓ | — | ✓ | — | — | ✓ |
| Approve/reject task | ✓ | — | — | ✓ | — | — |
| Complete task + input KM | ✓ | — | ✓ | — | — | ✓ |
| Lihat proyeksi global | ✓ | ✓ | — | ✓ | — | — |
| Lihat proyeksi per area | ✓ | ✓ | ✓ (area sendiri) | ✓ | ✓ | — |
| Lihat laporan | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

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
- Hanya Superadmin dan Admin Planner HO yang dapat tambah/edit/nonaktifkan unit
- Perubahan nomor polisi menyimpan history plat lama beserta tanggal perubahan
- Unit dengan KM < 50.000 otomatis diberi flag **Warranty**

### 3.2 18 Item Maintenance Standar
Berlaku sama untuk semua unit tanpa pengecualian.

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
| 9 | Ban |
| 10 | Greasing |
| 11 | V-Belt |
| 12 | Sarung Jok |
| 13 | Karpet Karet |
| 14 | Karpet Dasar |
| 15 | Flushing Radiator |
| 16 | Flushing Steering |
| 17 | Flushing Injector |
| 18 | Flushing Rem |

Setiap item memiliki:
- `interval_km` — jarak KM antar penggantian
- `interval_days` — jarak hari antar penggantian

### 3.3 Threshold (Dapat Dikonfigurasi)
Disimpan di master data, dapat diubah oleh Superadmin dan Admin Planner HO:
- **Warning KM** — berapa KM sebelum due_km task mulai muncul
- **Warning hari** — berapa hari sebelum due_date task mulai muncul
- **Threshold High Usage** — persentase perubahan avg KM/hari yang dianggap signifikan
- **Minimum data inspeksi** — jumlah minimum inspeksi untuk kalkulasi avg KM/hari yang valid (default: 3)

### 3.4 Setup Awal Per Unit
Setelah unit ditambahkan, Admin Planner HO atau Admin Site wajib input:
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
- Jika ada dua input untuk unit yang sama di hari yang sama → ambil nilai KM terbesar
- Jika jumlah inspeksi untuk unit tersebut kurang dari threshold minimum → sistem tampilkan warning "Data inspeksi unit ini masih sedikit, perhitungan avg KM/hari belum akurat"

### 4.2 Trigger Kalkulasi
Setiap ada input KM baru → sistem otomatis:
1. Update odometer unit
2. Hitung progress 18 item
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
  - Notifikasi ke Admin Site
  - Admin Site putuskan: jadikan task sekarang atau tunggu trigger normal
  - Jika Ya → task dibuat, butuh Spv approve
  - Jika Tidak → sistem monitor terus

- **5 hari kedua (setelah 5 hari pertama tidak ada tindakan):**
  - Admin Site wajib set: kapan unit bisa dipegang, due KM baru, due date baru
  - Diajukan ke Spv untuk approve
  - Setelah approve → task dibuat

High Usage berlaku untuk semua 18 item.

### 5.3 Kondisi Blocked
- Input manual oleh Admin Site atau Mekanik
- Alasan: unit tidak bisa dipegang customer
- Due date dan KM **tetap berjalan** — tidak ada freeze
- Status Blocked hanya sebagai label informasi pada task
- Task tetap bisa menjadi overdue

### 5.4 Kondisi Breakdown
- Input manual oleh Admin Site atau Mekanik
- Alasan: unit rusak/mogok, tidak beroperasi
- Due date (waktu) **freeze** karena unit tidak jalan
- KM juga freeze karena unit tidak bergerak
- **Unfreeze otomatis** saat ada input KM baru untuk unit tersebut → sistem deteksi unit aktif kembali
- Hari freeze ditambahkan ke `next_due_date`

**Inspeksi wajib setelah Breakdown:**  
Setelah unit aktif kembali, Admin Site atau Mekanik wajib input:
- Part apa yang diganti saat breakdown
- Sistem update `last_done_km` dan `last_done_date` untuk item tersebut
- Baru cycle lanjut normal

### 5.5 Warranty (KM < 50.000)
- Task muncul dengan label/flag **"Warranty"**
- Flow: Admin Site → Spv approve → Admin Site koordinasi ke dealer
- Logistik tidak mendapat notifikasi (part dari dealer)
- Selesai di dealer → Admin Site input Complete + KM → cycle reset normal
- Saat KM ≥ 50.000 → flag Warranty hilang otomatis, flow normal

---

## 6. Status & Lifecycle Task

### 6.1 Status Task

| Status | Definisi |
|---|---|
| **On Hold** | Task muncul, belum diaction Admin Site |
| **Blocked** | Unit tidak bisa dipegang customer — due tetap berjalan |
| **Breakdown** | Unit rusak — due di-freeze |
| **Replace** | Diajukan untuk diganti, menunggu Spv approve |
| **Postpone** | Diajukan untuk ditunda, menunggu Spv approve |
| **In Progress** | Approved oleh Spv, sedang dikerjakan Mekanik |
| **Complete** | Selesai dikerjakan, KM penggantian sudah diinput |
| **Overdue** | Melewati due date/KM tanpa action |

### 6.2 Lifecycle Lengkap

```
Task muncul (On Hold)
↓
Admin Site review kondisi fisik unit
↓
Pilih action:

[Replace]
→ Diajukan ke Spv Ops
→ Spv approve → status In Progress → Logistik notif (siapkan part)
→ Mekanik eksekusi
→ Admin Site / Mekanik: Complete + input KM penggantian
→ Sistem update:
    last_done_km   = KM saat complete
    last_done_date = tanggal complete
    next_due_km    = last_done_km + interval_km
    next_due_date  = last_done_date + interval_days
→ Cycle ulang

[Postpone]
→ Admin Site input: alasan + next_due_km baru + next_due_date baru
→ Diajukan ke Spv Ops
→ Spv approve → next_due bergeser sesuai input Admin Site
→ Task tertutup, muncul lagi saat next_due baru tercapai
→ History tercatat: alasan penundaan, due lama, due baru

[Blocked]
→ Admin Site / Mekanik input: alasan
→ Status task berubah ke Blocked
→ Due date tetap berjalan
→ Task bisa overdue
→ Saat unit bisa dipegang → Admin Site update status → flow Replace/Postpone

[Breakdown]
→ Admin Site / Mekanik input: unit Breakdown
→ Due date freeze
→ Input KM baru → sistem deteksi unit aktif kembali → freeze selesai
→ Admin Site / Mekanik input inspeksi: part yang diganti saat breakdown
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
- WO juga bisa dibuat manual oleh Admin Site untuk: breakdown mendadak, temuan mekanik, kondisi darurat
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

**View Part / Logistik:**
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
- **Admin Planner HO** → proyeksi global, bisa drill down per site
- **Admin Site** → proyeksi area sendiri
- **Spv Ops** → semua area
- **Logistik** → view Part saja

### 8.5 Sifat
- Real-time — update otomatis saat ada: task complete, postpone, inspeksi baru, blocked/unblocked
- Bukan snapshot

---

## 9. Notifikasi

Notifikasi bersifat **in-app** — ditampilkan saat halaman di-refresh. Tidak ada push notification atau WhatsApp.

| Event | Notifikasi ke |
|---|---|
| Task auto-generated (On Hold) | Admin Site |
| High Usage terdeteksi | Admin Site |
| Task diajukan (Replace/Postpone/Blocked) | Spv Ops |
| Task approved (Replace) | Logistik |
| Task overdue | Spv Ops + Admin Planner HO |
| Unit Breakdown diinput | Spv Ops |
| High Usage 5 hari kedua (belum ada tindakan) | Admin Site + Spv Ops |

---

## 10. Laporan & History

### 10.1 History per Unit
- Riwayat penggantian seluruh 18 item
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
- Admin Site → hanya area mereka
- Logistik → fokus pada part dan kebutuhan
- Spv Ops, Planner HO, Superadmin → semua area

---

## 11. Validasi & Logika Sistem

### 11.1 Input KM
- KM baru harus > KM terakhir yang tercatat → jika tidak, warning + tolak input
- Jika dua input KM untuk unit yang sama di hari yang sama → ambil nilai terbesar
- Jika jumlah inspeksi < threshold minimum → warning "Data inspeksi masih sedikit"

### 11.2 Breakdown Detection
- Breakdown berakhir otomatis saat ada input KM baru untuk unit yang berstatus Breakdown
- Sistem tambahkan jumlah hari freeze ke `next_due_date` semua item yang freeze
- Admin Site / Mekanik wajib input inspeksi breakdown sebelum task lanjut normal

### 11.3 High Usage
- Dihitung dari histori inspeksi — minimum sesuai threshold master data
- Jika data kurang → High Usage tidak dideteksi, hanya warning
- 5 hari dihitung sejak pertama kali flag High Usage muncul

### 11.4 Concurrency
- Input KM oleh beberapa mekanik untuk unit yang sama → ambil nilai terbesar

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
  id, name, email, password, role, site_id,
  created_at, updated_at

-- Lokasi/site
sites
  id, name, region,
  created_at, updated_at

-- Master unit kendaraan
units
  id, site_id, customer,
  current_plate, type, brand, year,
  current_odo, status (active/breakdown),
  created_at, updated_at

-- History nomor polisi
unit_plate_histories
  id, unit_id, plate_number, active_from, active_until,
  created_at

-- 18 item standar
planning_items
  id, name, interval_km, interval_days,
  created_at, updated_at

-- Threshold sistem (master data)
system_thresholds
  id, key, value, description,
  updated_by, updated_at

-- Start point per unit per item
unit_plannings
  id, unit_id, planning_item_id,
  last_done_km, last_done_date,
  next_due_km, next_due_date,
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
