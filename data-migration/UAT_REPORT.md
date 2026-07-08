# UAT Report — Fleet Maintenance Planner

Tanggal QA: 2026-07-07  
Target aplikasi: `http://127.0.0.1:8001`  
Acuan: `FSD_Fleet_Maintenance_Planner_1.md`, `PROJECT_CONTEXT_1.md`, dan `SEEDER_SCENARIOS.md`.

## Ringkasan

| Area | Status | Ringkasan |
|---|---|---|
| Task 1 — Data skenario | PASS | 10 skenario unit/plat/site ditemukan dan ditulis di `SEEDER_SCENARIOS.md`. |
| A — RBAC role baru | PARTIAL | Proteksi utama berjalan, tetapi Mekanik masih melihat menu `Reports`. |
| B — Flow inti | PARTIAL | Replace→Approve→Complete PASS; Postpone tanggal FAIL; Breakdown/High Usage sebagian FAIL/PARTIAL. |
| C — Kanban 5 kolom | PARTIAL | Preview dan create/approve berjalan, tetapi `Menunggu Approval` tampil di kolom `On Hold`. |
| D — Notifikasi | PARTIAL | Notifikasi overdue muncul; ada 2 notifikasi overdue untuk unit/item sama karena 2 WO berbeda. |
| E — Threshold dinamis | PASS | Invalid `upcoming_days < ancang_ancang_days` ditolak dengan pesan jelas. |
| F — Reports & History | PASS/PARTIAL | Reports dan History tampil; History Postpone menunjukkan bug tanggal yang sama dengan flow Postpone. |

## Blocker

Tidak ada blocker environment. Aplikasi berhasil dibuka dan diuji lewat browser nyata di `http://127.0.0.1:8001`.

## Major

### M-01 — Postpone tidak menyimpan tanggal baru sesuai input

- Severity: major
- Status: FAIL
- Role: Planner Balikpapan → Spv HO
- URL: `http://127.0.0.1:8001/work-orders/11`
- Data: `BPN-002`, Site Balikpapan, item `Service A`, WO `#11`.
- Langkah reproduksi:
  1. Login `planner.balikpapan@example.com`.
  2. Buka WO `#11`.
  3. Klik `SUBMIT POSTPONE`.
  4. Isi New Due KM `85000`, New Due Date `2026-08-15`, alasan `QA postpone exact date browser test`.
  5. Submit.
  6. Login `spv_ho@example.com`, buka WO `#11`, klik `Approve`.
  7. Buka `http://127.0.0.1:8001/units/2/history`.
- Expected behavior: due baru tersimpan persis `85.000 KM · 2026-08-15` dan History menampilkan tanggal baru tersebut.
- Actual behavior: UI setelah submit dan setelah approve menampilkan `Pengajuan due baru: KM 85,000 · 2026-07-31`; History juga menampilkan `Due baru: 85.000 KM · 2026-07-31`.
- Browser error: tidak ada JS error/network error.

### M-02 — High Usage Window 1 tidak memakai label Ya/Tidak sesuai FSD

- Severity: major
- Status: FAIL
- Role: Planner Samarinda
- URL: `http://127.0.0.1:8001/high-usage`
- Data: `SMR-001`, Site Samarinda, item `Ban`, Window 1 hari ke-1.
- Langkah reproduksi:
  1. Login `planner.samarinda@example.com`.
  2. Buka menu `High Usage`.
  3. Lihat aksi pada row `SMR-001`.
- Expected behavior: Window 1 menampilkan tombol `Ya` dan `Tidak` sesuai FSD.
- Actual behavior: tombol yang tampil adalah `BUAT TASK SEKARANG` dan `TUNDA`; tidak ada label `Ya/Tidak`.
- Browser error: tidak ada JS error/network error.

### M-03 — High Usage Window 2 submit tidak menghasilkan perubahan terlihat atau data approval

- Severity: major
- Status: FAIL
- Role: Planner Pekanbaru → Spv HO
- URL: `http://127.0.0.1:8001/high-usage`
- Data: `PKU-001`, Site Pekanbaru, item `Ban`, Window 2 hari ke-7.
- Langkah reproduksi:
  1. Login `planner.pekanbaru@example.com`.
  2. Buka `High Usage`.
  3. Klik `INPUT JADWAL BARU`.
  4. Isi `Kapan Unit Bisa Dipegang=2026-08-10`, `New Due KM=93000`, `New Due Date=2026-08-20`.
  5. Klik `SUBMIT KE SPV`.
  6. Login Spv HO dan buka `High Usage`.
- Expected behavior: request jadwal baru masuk approval Spv HO dan setelah approve `next_due` ter-update.
- Actual behavior: setelah submit row tetap menampilkan `INPUT JADWAL BARU`; database `high_usage_flags` tetap `action_taken=deferred`, tidak ada WO/request baru untuk `PKU-001`; Spv HO tidak melihat tombol approval untuk row tersebut.
- Browser error: tidak ada JS error/network error.

### M-04 — Kanban `Buat Task Sekarang` menampilkan card di kolom On Hold saat masih Menunggu Approval

- Severity: major
- Status: FAIL/PARTIAL
- Role: Planner Balikpapan → Spv HO
- URL: `http://127.0.0.1:8001/work-orders`
- Data: `BPN-002`, Site Balikpapan, item `Service A`.
- Langkah reproduksi:
  1. Login `planner.balikpapan@example.com`.
  2. Buka `Work Orders`.
  3. Pada preview Upcoming `BPN-002`, klik `BUAT TASK SEKARANG`.
  4. Amati perpindahan card.
- Expected behavior: status menjadi `Menunggu Approval` dan tidak langsung masuk kolom `On Hold`.
- Actual behavior: card hilang dari `Upcoming`, lalu muncul di kolom `On Hold` dengan chip `Menunggu Approval`; database item status memang `pending_create` sampai Spv approve.
- Browser error: tidak ada JS error/network error.

### M-05 — Mekanik masih melihat menu Reports

- Severity: major
- Status: PARTIAL
- Role: Mekanik Balikpapan
- URL: `http://127.0.0.1:8001/dashboard`
- Langkah reproduksi:
  1. Login `mekanik.balikpapan@example.com`.
  2. Buka navigasi.
- Expected behavior: Mekanik hanya bisa Input KM dan melihat WO area sendiri; tidak ada akses menu non-operasional yang tidak disebutkan.
- Actual behavior: menu terlihat `Dashboard`, `Input KM`, `Riwayat Inspeksi`, `Work Orders`, dan `Reports`. Master data `Sites` dan `Units` benar ditolak `403`.
- Browser error: tidak ada JS error/network error.

## Minor

### m-01 — Breakdown input KM tidak berhasil diverifikasi karena field odometer tidak terdeteksi sebagai input number

- Severity: minor
- Status: PARTIAL
- Role: Mekanik Jakarta
- URL: `http://127.0.0.1:8001/inspections/create`, lalu `http://127.0.0.1:8001/work-orders/3`
- Data: `JKT-001`, Site Jakarta, WO `#3`.
- Langkah reproduksi:
  1. Login `mekanik.jakarta@example.com`.
  2. Buka `Input KM`.
  3. Pilih/cek unit `JKT-001`.
  4. Coba submit KM baru `111000`.
- Expected behavior: input KM baru tersimpan, unit auto-unfreeze, form inspeksi breakdown muncul/hilang setelah submit, badge Breakdown hilang.
- Actual behavior: submit menghasilkan validasi `Odometer baru harus lebih besar dari odometer unit saat ini.` karena field odometer tidak berhasil terisi oleh aksi browser; WO `#3` tetap `breakdown` dan badge Breakdown tetap tampil.
- Browser error: tidak ada JS error/network error.

### m-02 — Notifikasi overdue terlihat dua kali untuk unit/item yang sama, tetapi berasal dari dua WO berbeda

- Severity: minor
- Status: PARTIAL
- Role: Spv HO
- URL: dropdown `Notifications`
- Data: `MDN-001 - Wiper Blade`, WO `#6` dan WO `#10`.
- Langkah reproduksi:
  1. Jalankan `php artisan maintenance:check-overdue`.
  2. Login `spv_ho@example.com`.
  3. Klik `Notifications`.
- Expected behavior: tidak ada notifikasi dobel untuk event yang sama.
- Actual behavior: dropdown menampilkan dua notifikasi `MDN-001 - Wiper Blade di Site Medan sudah overdue.`. Query database menunjukkan `duplicate_count=1` per data payload karena masing-masing mengarah ke WO berbeda (`#6` dan `#10`), tetapi secara tampilan user terlihat dobel untuk unit/item yang sama.
- Browser error: tidak ada JS error/network error.

## Pass

### P-01 — RBAC Planner Area

- Status: PASS
- Role: Planner Balikpapan
- Bukti browser: menu menampilkan `Riwayat Inspeksi`, `Work Orders`, `High Usage`, `Projections`, `Reports`; `Input KM` tidak tampil. Akses `http://127.0.0.1:8001/inspections/create` ditolak `403 THIS ACTION IS UNAUTHORIZED.`. Filter site lain tidak menampilkan unit site lain.

### P-02 — RBAC Spv HO

- Status: PASS
- Role: Spv HO
- Bukti browser: bisa akses `Sites`, `Units`, `Planning Items`, `System Thresholds`, `Projections`, `Reports`, dan approve WO. `Users` ditolak `403`, sesuai perbedaan dengan Superadmin.

### P-03 — RBAC Superadmin

- Status: PASS
- Role: Superadmin
- Bukti browser: menu lengkap menampilkan `Input KM`, `Work Orders`, `High Usage`, `Projections`, `Reports`, master data, dan `User Management`; halaman admin terbuka.

### P-04 — Replace → Spv approve → Complete → next_due reset

- Status: PASS
- Role: Planner Balikpapan → Spv HO → Mekanik Balikpapan
- Data: `BPN-001`, WO `#1`, item `PM Check / Reguler Services`.
- Bukti browser: Planner submit `SUBMIT REPLACE` dengan alasan; Spv HO melihat `Menunggu Approve Replace` dan klik `Approve`; Mekanik isi KM `48600` lalu klik `COMPLETE`; WO menjadi `complete`, item `complete`, selesai pada `2026-07-07` di KM `48,600`; due berubah ke `53,600 KM / 2026-10-05`.

### P-05 — Warranty badge tampil dan approval tidak memicu error part

- Status: PASS
- Data: `BPN-001`, KM `< 50.000`.
- Bukti browser: detail WO `#1` menampilkan badge `Warranty`; approval Replace dan Complete selesai tanpa JS/network error.

### P-06 — Blocked bisa dilanjutkan via Replace/Postpone

- Status: PASS
- Role: Planner Makassar
- Data: `MKS-001`, WO `#4`, item `Accu`.
- Bukti browser: detail WO menampilkan status `blocked`, chip `Blocked`, teks `Resolve dengan memilih Submit Replace atau Submit Postpone.`, dan tombol `SUBMIT REPLACE` serta `SUBMIT POSTPONE`.

### P-07 — Kanban preview Upcoming/Ancang-ancang terisi dan tidak dobel pada site yang diuji

- Status: PASS/PARTIAL
- Bukti browser: Planner Balikpapan melihat `Upcoming` berisi `BPN-002` dan `On Hold` berisi `BPN-001`, tidak ada duplikasi dalam site Balikpapan. Data Ancang-ancang tersedia untuk `BJM-002` dari database; belum diklik ulang di browser karena sesi UAT fokus pada Balikpapan setelah create task.

### P-08 — Create Task → Spv approve membentuk WO On Hold

- Status: PASS
- Data: `BPN-002`, WO `#11`.
- Bukti browser: Planner klik `BUAT TASK SEKARANG`; Spv HO buka WO `#11`, tombol `APPROVE` dan `REJECT` tampil; klik `Approve` mengubah item menjadi `on_hold`.

### P-09 — Threshold dinamis ditolak dengan pesan jelas

- Status: PASS
- Role: Spv HO
- URL: `http://127.0.0.1:8001/system-thresholds/6/edit`
- Bukti browser: edit `upcoming_days` dari `28` menjadi `10` saat `ancang_ancang_days=14`, klik `SAVE`; halaman menampilkan error `Urutan threshold days harus: upcoming > ancang-ancang > warning.`

### P-10 — Reports tampil

- Status: PASS
- Role: Spv HO
- URL: `http://127.0.0.1:8001/reports`
- Bukti browser: laporan menampilkan Total WO `11`, Total Item `12`, Complete `3`, Overdue `2`, filter bulan/tahun/site, tab `Rekap WO`, `Per Item`, `Per Unit`, `Overdue`, dan rekap per site.

### P-11 — History unit tampil untuk Blocked dan Postpone

- Status: PASS/PARTIAL
- Bukti browser: `MKS-001` history menampilkan riwayat blocked WO `#8` dan `#4` beserta alasan; `BPN-002` history menampilkan riwayat Postpone beserta alasan, due lama, due baru. Catatan: tanggal due baru di Postpone salah seperti M-01.

## Catatan Efek UAT

- UAT membuat WO manual `#11` untuk `BPN-002` lewat tombol `BUAT TASK SEKARANG`.
- UAT menyelesaikan WO `#1` untuk `BPN-001` sebagai complete di KM `48.600`.
- UAT menjalankan `php artisan maintenance:check-overdue`; output command: `0 work order item overdue diproses`, lalu notifikasi overdue tetap muncul untuk item yang sudah berstatus overdue.
