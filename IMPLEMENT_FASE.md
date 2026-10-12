# IMPLEMENT FASE — Sistem Ticketing POS Loket & Mobile Validator Gate

**Pertunjukan Kecak Uluwatu** · Acuan: `PRD.md` v2.5 · Stack: CodeIgniter 4 + MySQL 8.0.16+ + Flutter (Android)

> Dokumen ini adalah **tracker** pengerjaan. Centang `[x]` saat selesai, perbarui tabel status, dan catat keputusan/kendala di bagian Log.
> Rujukan `BR-xx` dan nomor bagian mengacu ke PRD.md.

---

## Legenda Status

| Simbol | Arti |
|---|---|
| ⬜ | Belum mulai |
| 🟨 | Sedang dikerjakan |
| ✅ | Selesai (Definition of Done terpenuhi) |
| ⛔ | Terblokir |

## Ringkasan Status

| Fase | Nama | Estimasi | Bergantung pada | Status |
|---|---|---|---|---|
| 0 | Persiapan & Setup Lingkungan | 1–2 hari | — | ✅ |
| 1 | Backend & Skema Database | 1 minggu | Fase 0 | ✅ |
| 2 | Web POS (Loket) | 1–2 minggu | Fase 1 | ✅ |
| 3 | Admin: Riwayat, Void, Dashboard, Laporan | 1–2 minggu | Fase 2 | ✅ |
| 4 | REST API Gate | 1 minggu | Fase 1 (+ data tiket dari Fase 2) | 🟨 |
| 5 | Mobile Validator (Flutter) | 1–2 minggu | Fase 4 | ⬜ |
| 6 | Uji Lapangan & Perbaikan | 1 minggu | Fase 3, 5 | ⬜ |
| 7 | Deploy & Go-Live *(tambahan, di luar sprint PRD)* | 2–3 hari | Fase 6 | ⬜ |

> Estimasi hanya perkiraan awal; sesuaikan dengan kapasitasmu. **Fase 3 dan Fase 4 bisa berjalan paralel** setelah Fase 2 selesai.

```
Fase 0 → Fase 1 → Fase 2 → Fase 3 ─┐
                     └──→ Fase 4 → Fase 5 ─┴→ Fase 6 → Fase 7
```

---

## Fase 0 — Persiapan & Setup Lingkungan

**Tujuan:** lingkungan kerja siap dan konsisten (WITA, MySQL 8.0.16+).

- [✅] Install CodeIgniter 4 (via Composer), PHP sesuai syarat CI4
- [✅] Buat database MySQL 8.0.16+ (InnoDB, `utf8mb4`) — pastikan CHECK constraint aktif
- [✅] Konfigurasi `.env`: environment, base URL, kredensial DB (jangan commit `.env`)
- [✅] Set zona waktu aplikasi `Asia/Makassar`; sesi MySQL `+08:00` (PRD 2.2)
- [✅] Inisialisasi Git + `.gitignore` + struktur branch
- [✅] Siapkan Postman collection untuk uji API
- [✅] Install Flutter SDK + emulator/perangkat Android (API 26+)

**Definition of Done:** `php spark serve` jalan, koneksi DB sukses, `SELECT @@session.time_zone` = `+08:00`, waktu aplikasi = WITA.

---

## Fase 1 — Backend & Skema Database *(Sprint 1)*

**Tujuan:** skema final 12 tabel, autentikasi, master data, dan Pengaturan.

### 1.1 Migrasi Skema (PRD bagian 8)
- [✅] `users` (role ENUM `admin`)
- [✅] `gate_users`
- [✅] `customers` (phone/address NULL-able untuk anonimisasi)
- [✅] `ticket_types` (+ `ticket_category`, CHECK price & discount)
- [✅] `checkpoints`
- [✅] `transactions` (+ CHECK subtotal/discount/total, cash, void)
- [✅] `tickets` (+ CHECK total_visitors per kategori, CHECK used)
- [✅] `ticket_details` (+ UNIQUE `ticket_id, ticket_type_id`, CHECK subtotal)
- [✅] `checkin_history` (BIGINT PK, tanpa `success_ticket_id`)
- [✅] `gate_api_tokens` (`revoked_at`, `last_used_at`, indeks `expires_at`)
- [✅] `print_log`
- [✅] `settings`
- [✅] Semua indeks bagian 8.3 (termasuk `tickets (valid_date, status)` dan `ticket_types (ticket_category, is_active)`)
- [✅] `created_at` & `updated_at` di seluruh tabel

### 1.2 Seeder
- [✅] `ticket_types`: Adult, Child (individual, 0%); Adult Rombongan, Child Rombongan (group, 5%)
- [✅] `users`: akun Admin awal
- [✅] `checkpoints`: minimal 1 gate
- [✅] `settings`: `min_group_size=5`, `max_individual_per_tx=4`, `max_group_members=15`, `retention_months=12`, `login_max_attempts=5`, `login_lock_minutes=15`, `api_token_hours=12`, `business_name`, `business_address`, `receipt_footer`

### 1.3 Autentikasi Web (PRD 6.1)
- [✅] Login/logout Admin (password hash aman, sesi aman)
- [✅] Filter/middleware: semua halaman wajib login (BR-17)
- [✅] Pembatasan login gagal (5× / 15 menit, dari `settings`)
- [✅] Proteksi CSRF aktif

### 1.4 Master Data & Pengaturan (PRD 6.3)
- [✅] CRUD User (Admin & Gate): aktif/nonaktif, reset password
- [✅] CRUD Jenis Tiket: edit harga, `discount_percent`, kategori, status aktif
- [✅] Peringatan bila harga pasangan individual vs rombongan berbeda *(REKOMENDASI)*
- [✅] CRUD Checkpoint
- [✅] Halaman Pengaturan + validasi nilai (`min_group_size` ≥ 2, batas = bilangan bulat positif) + catat siapa & kapan (`updated_by`)

**Definition of Done:** `php spark migrate` + `db:seed` sukses dari DB kosong; CHECK constraint menolak data tidak valid (uji manual via SQL); Admin bisa login & mengelola master data.

---

## Fase 2 — Web POS (Loket) *(Sprint 2)*

**Tujuan:** transaksi loket end-to-end sampai tiket tercetak.

### 2.1 Service Layer (satu tempat perhitungan — PRD 8.4)
- [✅] `PricingService`: hitung kotor, diskon per baris (dibulatkan rupiah), `discount_amount`, `total_amount`
- [✅] Ambil harga & diskon **dari DB saat proses**, bukan dari input klien
- [✅] `total_visitors`, `subtotal`, `total` dihitung server, tidak diedit manual
- [✅] Generator `invoice_code` (`INV-YYYYMMDD-XXXXXX`) & `ticket_code` (`TKT-`/`GRP-`, CSPRNG) dengan retry saat tabrakan UNIQUE
- [✅] `valid_date` otomatis = hari ini WITA (BR-22)

### 2.2 Form & Aturan POS (PRD 4.1–4.3, 6.2)
- [✅] Form pengunjung: nama, asal (Domestik/Mancanegara + kota/negara), alamat, nomor HP (BR-12)
- [✅] Tanggal berlaku tampil read-only
- [✅] Pilihan mode Individual / Rombongan; daftar jenis tiket **disaring per kategori** (BR-33)
- [✅] Validasi individual: maks `max_individual_per_tx` (4)
- [✅] Validasi rombongan: 1 tiket group, `min_group_size` ≤ total ≤ `max_group_members` (5–15)
- [✅] Pesan jelas bila < `min_group_size` dicoba sebagai rombongan (BR-28)
- [✅] Tolak campuran individual+rombongan (BR-10) dan jenis lintas kategori
- [✅] Pembayaran Cash: uang diterima ≥ total, hitung kembalian
- [✅] Pembayaran Debit: nomor referensi EDC wajib (BR-13)
- [✅] Penghitung diskon & total tampil live di form

### 2.3 Penyimpanan Atomik (BR-32)
- [✅] Satu DB transaction: `customers` → `transactions` → `tickets` → `ticket_details` → `print_log (initial)`
- [✅] Snapshot `unit_price`, `discount_percent`, `discount_amount`, `subtotal` ke `ticket_details` (BR-11)
- [✅] Status awal transaksi `completed`, tiket `active` (BR-16)

### 2.4 QR & Cetak Thermal (PRD bagian 10)
- [✅] QR Code level M, isi hanya kode tiket (tanpa data pribadi)
- [✅] CSS `@media print` untuk 58 mm dan 80 mm
- [✅] Potongan pertama memuat rincian pembayaran; potongan berikutnya hanya tiket + QR
- [✅] Baris diskon: "Diskon Rombongan" (group) / "Diskon Promo" (individual)
- [✅] Teks "BERLAKU HANYA HARI INI … SAMPAI PUKUL 24.00 WITA / TIDAK DAPAT DI-REFUND"
- [✅] Header & footer struk dari `settings`
- [✅] Cetak ulang (Admin): hanya tiket `active` & berlaku hari ini; catat `print_log (reprint)` (BR-19)

**Definition of Done:** transaksi individual (1–4 tiket) dan rombongan (5–15) tersimpan benar; angka cocok dengan contoh PRD 4.2 (6 orang → total Rp494.000); struk tercetak di thermal; kegagalan di tengah proses tidak meninggalkan data setengah jadi.

---

## Fase 3 — Admin: Riwayat, Void, Dashboard, Laporan *(Sprint 3)*

### 3.1 Riwayat Penjualan
- [✅] Daftar transaksi + filter tanggal, akun pembuat, metode bayar
- [✅] Detail transaksi (customer, tiket, rincian, status)
- [✅] Aksi cetak ulang

### 3.2 Void Administratif (PRD 4.6, BR-18)
-[✅] Hanya Admin; alasan wajib
-[✅] Ditolak bila ada tiket `used`
-[✅] Atomik: transaksi `cancelled` + semua tiket `cancelled`; isi `voided_by`, `voided_at`, `void_reason`

### 3.3 Dashboard (PRD 6.4)
- [✅] Penjualan hari ini, jumlah tiket terbit, pengunjung sudah masuk
- [✅] Tiket `active` belum dipakai (hanya `valid_date` hari ini)
- [✅] Komposisi Cash/Debit

  ### 3.4 Laporan & Ekspor
  - [✅] Penjualan per hari / per akun Admin / per metode bayar
  - [✅] Rekap diskon per hari, dipisah individual vs group (`tickets.ticket_category`)
  - [✅] Rekap demografi (Domestik/Internasional, kota/negara)
  - [✅] Rekap pengunjung masuk per hari/bulan/gate + audit scan (termasuk yang ditolak)
  - [✅] Rekap tiket terjual vs terpakai per hari
  - [✅] Ekspor PDF dan Excel

### 3.5 Anonimisasi (PRD 4.7, BR-21 **KERJAKAN BELAKANGAN**)
- [ ] Command terjadwal (`php spark`) + cron: anonimkan `customers` > `retention_months`
- [ ] `name` → placeholder, `phone`/`address` → NULL, isi `anonymized_at`; `origin_type`/`origin` tetap
- [ ] Command pembersihan token kedaluwarsa berkala

**Definition of Done:** void membatalkan transaksi & tiket sekaligus; laporan cocok dengan data transaksi; ekspor terbuka benar; anonimisasi tidak menghapus data transaksi/tiket.

---

## Fase 4 — REST API Gate *(Sprint 4)*

**Tujuan:** API siap dipakai Flutter. *(Bisa paralel dengan Fase 3.)*

### 4.1 Autentikasi Token (PRD 7.1, 9)
- [ ] `POST /api/login` → token acak (opaque), simpan hanya SHA-256, `expires_at` = `api_token_hours`
- [ ] `POST /api/logout` → isi `revoked_at` (bukan hapus baris)
- [ ] `GET /api/me`
- [ ] Filter API: cek `token_hash`, `revoked_at` kosong, `expires_at` belum lewat, `gate_users` aktif; update `last_used_at`
- [ ] `GET /api/checkpoints` (hanya yang aktif)

### 4.2 Validasi Tiket
- [ ] `POST /api/ticket/check` — baca saja, tanpa lock tulis, **tidak** menulis `checkin_history` (BR-25)
- [ ] `POST /api/ticket/validate-and-checkin` — atomik (PRD 9.1):
  - [ ] `SELECT ... FOR UPDATE`
  - [ ] Urutan cek: `NOT_FOUND` → `CANCELLED` → `ALREADY_USED` → `EXPIRED` → `VALID`
  - [ ] `UPDATE ... WHERE id=? AND status='active'`, pastikan affected rows = 1
  - [ ] Simpan `checkin_history` untuk semua hasil (termasuk `NOT_FOUND` dengan `ticket_id` NULL)
  - [ ] Respons: jenis tiket, jumlah pengunjung, rincian (rombongan), nama pembawa

### 4.3 Dashboard & Riwayat
- [ ] `GET /api/dashboard/summary?period=today|month` (hanya scan Check-in, batas WITA)
- [ ] `GET /api/ticket/history` (milik petugas login, terbaru di atas)

### 4.4 Kualitas API
- [ ] Format JSON konsisten, waktu ISO 8601 offset WITA, kode HTTP tepat
- [ ] Indeks dipakai (`ticket_code` unique) — respons < 1 detik
- [ ] Gunakan enkripsi seperti saas dan atau sejenisnya

**Definition of Done:** koleksi Postman lulus semua endpoint; uji scan bersamaan 2 petugas → satu `VALID`, satu `ALREADY_USED`; tiket kemarin → `EXPIRED`; token logout/kedaluwarsa ditolak.

---

## Fase 5 — Mobile Validator (Flutter) *(Sprint 5)*

- [ ] Setup project Flutter, struktur state management & HTTP client (base URL dari konfigurasi, HTTPS)
- [ ] Penyimpanan token aman (mis. `flutter_secure_storage`)
- [ ] Layar login + pilih checkpoint aktif
- [ ] Pemindai QR kontinu (`mobile_scanner`), izin kamera
- [ ] Debounce ±1 detik setelah QR terbaca
- [ ] Mode Check-in (default) & sakelar Mode Cek; mode kembali ke Check-in tiap app dibuka
- [ ] Mode Cek: tampilkan status + tombol "Check-in sekarang" bila valid
- [ ] Layar hasil: VALID hijau, ALREADY_USED merah, EXPIRED kuning, NOT_FOUND abu-abu, CANCELLED merah (PRD 7.3)
- [ ] Umpan balik: beep sukses, dua bunyi untuk tolak, getar
- [ ] Auto-kembali ke pemindai (±2 detik / sentuhan)
- [ ] Input kode tiket manual (cadangan QR rusak/basah)
- [ ] Dashboard jumlah tamu: Hari Ini & Bulan Ini
- [ ] Riwayat validasi petugas (HH:MM:SS WITA)
- [ ] Penanganan koneksi putus: pesan jelas, tanpa antrean offline (BR-08)
- [ ] Logout (cabut token) & penanganan token kedaluwarsa (kembali ke login)

**Definition of Done:** alur login → scan → hasil → riwayat berjalan di perangkat Android nyata dengan tiket cetak dari POS.

---

## Fase 6 — Uji Lapangan & Perbaikan *(Sprint 6)*

### 6.1 Skenario Uji v2.5 (PRD bagian 14)
- [ ] Mode Individual hanya jenis individual; mode Rombongan hanya jenis group; lintas kategori ditolak
- [ ] Rombongan 4 orang ditolak; 5 berhasil (diskon 5%); 16 ditolak
- [ ] Ubah `discount_percent` group / `min_group_size` → transaksi baru ikut nilai baru, transaksi lama tetap (snapshot)
- [ ] Promo 10% di jenis individual tidak memengaruhi rombongan, dan sebaliknya
- [ ] Total = kotor − diskon; kembalian dari `total_amount`; CHECK menolak data tidak konsisten
- [ ] Peringatan harga pasangan berbeda muncul di form edit
- [ ] Token logout (`revoked_at`) ditolak; `last_used_at` terisi
- [ ] Void ditolak bila ada tiket `used`; void normal membatalkan semuanya

### 6.2 Uji Operasional
- [ ] Kecepatan cetak di loket
- [ ] Latensi scan rata-rata < 1 detik
- [ ] Printer 58 mm dan 80 mm (+ opsional kiosk-printing)
- [ ] Uji antrean (banyak tamu bergantian)
- [ ] Skenario koneksi terputus (loket & gate)
- [ ] Pergantian hari: tiket 23:59 vs 00:00 WITA → `EXPIRED`
- [ ] Cek mode Cek tidak memengaruhi metrik dashboard

### 6.3 Perbaikan
- [ ] Catat semua bug di Log → perbaiki → uji ulang
- [ ] Review keamanan (bagian 11): HTTPS, CSRF, hash password, tidak ada kredensial di source

**Definition of Done:** seluruh skenario lulus; tidak ada bug blocker terbuka.

---

## Fase 7 — Deploy & Go-Live *(tambahan)*

- [ ] Server produksi: PHP, MySQL 8.0.16+, HTTPS aktif, zona waktu WITA
- [ ] `.env` produksi (`CI_ENVIRONMENT=production`), debug mati
- [ ] Jalankan migrasi + seeder minimal (jenis tiket, checkpoint, settings, akun Admin)
- [ ] **Satu akun Admin per orang**, password kuat *(REKOMENDASI)*
- [ ] Cron: anonimisasi & pembersihan token
- [ ] Backup DB harian (retensi ≥ 14 hari) + uji pemulihan *(REKOMENDASI)*
- [ ] Build APK Flutter rilis & distribusi ke petugas gate
- [ ] Papan pemberitahuan pengumpulan data di loket *(REKOMENDASI)*
- [ ] Pelatihan singkat petugas loket & gate

**Definition of Done:** satu hari operasional percobaan berjalan tanpa kendala besar.

---

## Log Keputusan & Kendala

| Tanggal | Fase | Catatan |
|---|---|---|
| 2026-10-05 | — | Dokumen dibuat dari PRD v2.5 |
| 2026-10-09 | 3.2 | Void: tombol di `transaction-history/index`, modal Bootstrap + SweetAlert2, route POST `transaction-history/void/{id}`; perbaiki field model `voided_reason` → `void_reason`; cek tiket `used` dengan lock `FOR UPDATE` dalam satu transaksi DB |

## Catatan Perubahan Dokumen

| Versi | Tanggal | Perubahan |
|---|---|---|
| 1.0 | 2026-10-05 | Versi awal berdasarkan PRD.md v2.5 |
