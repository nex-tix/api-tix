# PRODUCT REQUIREMENTS DOCUMENT

**Sistem Ticketing POS Loket Web & Mobile Validator Gate — Pertunjukan Kecak Uluwatu**

| Item | Keterangan |
|---|---|
| Versi | 2.5 (revisi dari v2.4) |
| Status | Final. Poin bertanda **[REKOMENDASI]** adalah usulan teknis yang tersisa |
| Platform | Web internal (role Admin) dan Mobile Android (role Gate) |
| Web & Backend/API | PHP, CodeIgniter 4 (REST API) |
| Mobile | Flutter / Dart (Android 8.0+ / API 26) |
| Database | MySQL 8.0.16+ (CHECK constraint ditegakkan), InnoDB, utf8mb4 |
| Zona waktu | WITA (aplikasi: `Asia/Makassar`; MySQL: `+08:00`) |
| Pembayaran | Manual di loket: **Cash** dan **Debit (EDC)**. Tanpa payment gateway |

---

## Daftar Isi

1. [Ringkasan Perubahan](#1-ringkasan-perubahan)
2. [Deskripsi Sistem](#2-deskripsi-sistem)
3. [Role Pengguna](#3-role-pengguna)
4. [Alur Bisnis](#4-alur-bisnis)
5. [Aturan Bisnis](#5-aturan-bisnis)
6. [Modul Web (Admin)](#6-modul-web-admin)
7. [Modul Mobile Validator](#7-modul-mobile-validator-android-flutter)
8. [Skema Database](#8-skema-database-mysql)
9. [REST API](#9-rest-api-mobile-validator)
10. [Tata Letak Cetak Tiket](#10-tata-letak-cetak-tiket-thermal-5880-mm)
11. [Keamanan](#11-keamanan)
12. [Teknologi & Non-Fungsional](#12-teknologi-dan-kebutuhan-non-fungsional)
13. [Keputusan & Konfigurasi](#13-keputusan-dan-konfigurasi)
14. [Rencana Implementasi & Pengujian](#14-rencana-implementasi-dan-pengujian)

---

## 1. Ringkasan Perubahan

### 1.1 Ralat dari versi sebelumnya
- Tidak ada payment gateway. Hanya Cash dan Debit, diverifikasi langsung di loket.
- Tidak ada ganti jadwal dan tidak ada refund.
- Tidak ada pengiriman tiket/QR lewat email. Tiket dicetak fisik di loket.

### 1.2 Perubahan sistem
- Pembelian **hanya offline di loket**. Pemesanan online, website publik, alur pending/approval, anti-spam, dan cron kedaluwarsa dihapus. Web hanya diakses pengguna internal yang login.
- Alur: tamu datang ke loket → loket mengumpulkan data pengunjung (nama, asal, alamat, nomor HP, tipe tiket, jumlah tamu) → tamu membayar → tamu menerima tiket → tamu menuju gate → petugas gate memindai QR. Bila valid, status tiket menjadi `used`; bila tidak, aplikasi menampilkan error sesuai kondisi.
- Sesi kunjungan dan kuota dihapus. Validasi gate memeriksa status tiket dan tanggal berlaku.
- Mobile Validator: login username/password, riwayat validasi, jumlah tamu periode Hari Ini dan Bulan Ini, pemindaian cepat, dan mode cek tanpa memakai tiket.

### 1.3 Hasil konfirmasi keputusan (v2.2 s.d. v2.5)
- Hanya satu role web: **Admin**. Penjaga loket login memakai akun Admin.
- Void administratif dan cetak ulang hanya oleh Admin.
- Mode cek tanpa memakai tiket (`/api/ticket/check`).
- Tanpa kuota kapasitas harian.
- [v2.4] Master Guide dihapus. Rombongan dibeli atas nama penanggung jawab (tersimpan di tabel `customers`).
- [v2.4] Rombongan minimal 5 dan maksimal 15 orang per tiket (1 tiket group per transaksi). Di bawah 5 orang wajib tiket individual.
- [v2.5] Diskon hanya bersumber dari `ticket_types.discount_percent`. Tidak ada pengaturan diskon di tabel `settings`.
- Retensi data pribadi default (anonimisasi 12 bulan).
- Parameter konfigurasi dapat diubah Admin lewat panel Pengaturan (tabel `settings`).

### 1.4 Ralat v2.3 (tetap berlaku)
Karena pengunjung datang langsung, membeli, dan memindai tiket secara offline pada hari yang sama, pembelian jauh hari (booking) tidak diperlukan.

- **Dihapus:** pemilihan tanggal kunjungan, pembelian jauh hari/hari-H terjadwal, aturan "tanggal bebas dari hari ini dan seterusnya".
- **Ditambah:** sistem pembelian 1 hari. Tiket otomatis berlaku hanya pada tanggal pembelian (WITA), dari saat dicetak sampai pukul 24.00 WITA (23:59:59).
- Kolom `visit_date` diganti `valid_date`: diisi otomatis sistem dari tanggal transaksi, tidak dapat diinput/diubah petugas.
- Hasil scan `DATE_MISMATCH` diganti `EXPIRED`.
- Tiket tidak terpakai sampai 24.00 otomatis tidak berlaku (tanpa refund, tanpa ganti hari).
- Struk menampilkan "BERLAKU HARI INI SAMPAI 24.00 WITA".

### 1.5 Ralat v2.4
- **Dihapus:** konsep Guide (tabel `guides`, master Guide, jenis tiket Guide, `transactions.guide_id`, riwayat kunjungan Guide, BR-24). Rombongan = satu tiket kategori `group` atas nama penanggung jawab, isinya Adult dan/atau Child.
- **Syarat rombongan:** total pengunjung minimal `min_group_size` (default 5), disimpan di `settings`, dapat diubah Admin. Di bawah batas itu wajib tiket individual.
- **Snapshot diskon:** `ticket_details` menyimpan `unit_price`, `discount_percent`, `discount_amount`, `subtotal` (bersih). `transactions.subtotal` = jumlah kotor sebelum diskon; yang dibayar = `total_amount`.
- **Token gate:** `gate_api_tokens` mendapat `revoked_at`, `last_used_at`, dan indeks `expires_at`. Logout mencabut token (mengisi `revoked_at`), bukan menghapus baris.
- **Skema final:** CHECK constraint (bagian 8.4), indeks gabungan `tickets (valid_date, status)`, `created_at`/`updated_at` di semua tabel, kolom `success_ticket_id` di `checkin_history` dihapus (pengaman diganti UPDATE bersyarat, bagian 9.1).
- **Batas individual:** default `max_individual_per_tx` = 4 (`min_group_size` − 1) **[REKOMENDASI]**.
- **Konteks:** header struk, contoh, dan istilah disesuaikan untuk Pertunjukan Kecak Uluwatu.

### 1.6 Ralat v2.5 (perubahan versi ini)
- **Diskon satu sumber:** diskon rombongan dan individual digabung ke satu kolom `ticket_types.discount_percent`. Parameter `group_discount_percent` dihapus dari `settings`.
- Aturan penumpukan ("ambil persentase terbesar", BR-29 v2.4) tidak diperlukan lagi.
- **Kolom baru** `ticket_types.ticket_category` (`individual` / `group`). Jenis tiket: Adult & Child (individual), Adult Rombongan & Child Rombongan (group), masing-masing punya harga dan diskon sendiri. Diskon rombongan default 5%.
- POS menyaring jenis tiket sesuai mode: Individual → jenis individual; Rombongan → jenis group.
- Parameter tetap di `settings`: `min_group_size`, `max_group_members`, `max_individual_per_tx` (menentukan *kapan* rombongan boleh dijual, bukan besar diskon).
- **Dampak:** harga dasar Adult/Child tercatat di dua baris. Admin yang mengubah harga perlu mengubah kedua baris; sistem menampilkan peringatan bila harga berbeda **[REKOMENDASI]**.
- Laporan diskon dipisah individual vs rombongan memakai `tickets.ticket_category`.

---

## 2. Deskripsi Sistem

Sistem mengelola penjualan tiket tunai/debit di loket, pencetakan tiket fisik ber-QR Code, dan validasi tiket di gate masuk pada hari yang sama. Web dan API memakai database yang sama sehingga tiket yang baru dicetak dapat langsung divalidasi di gate.

### 2.1 Komponen

| Komponen | Tanggung jawab |
|---|---|
| Web POS & Admin (CodeIgniter 4) | Transaksi loket, cetak thermal, master data (user, jenis tiket, checkpoint), pengaturan, riwayat, laporan, ekspor |
| Mobile Validator (Flutter, Android) | Login petugas gate, scan QR cepat, mode cek, hasil validasi, dashboard jumlah tamu, riwayat scan |
| REST API (CodeIgniter 4) | Autentikasi, cek tiket, validasi & check-in atomik, dashboard, riwayat untuk mobile |
| MySQL | Penyimpanan seluruh data |

```
Web POS          → CodeIgniter 4 → MySQL
Flutter Android  → REST API → CodeIgniter 4 → MySQL
```
Mobile tidak mengakses database langsung.

### 2.2 Zona Waktu dan Pergantian Hari
Seluruh waktu (transaksi, tanggal berlaku tiket, waktu scan) memakai **WITA (UTC+8)**. Pergantian hari terjadi pukul 00:00 WITA; batas hari/bulan pada dashboard juga mengikuti WITA. Tiket dengan `valid_date` kemarin atau sebelumnya dianggap kedaluwarsa.

---

## 3. Role Pengguna

| Role | Platform | Hak akses |
|---|---|---|
| **Admin** | Web | Seluruh fungsi web: transaksi loket (POS) & cetak tiket; cetak ulang; void administratif; riwayat penjualan; master data (user, harga & diskon jenis tiket, checkpoint); Pengaturan; dashboard, laporan, audit scan, ekspor. Penjaga loket login memakai akun Admin |
| **Gate** | Mobile | Login, scan QR (mode Check-in & Cek), dashboard jumlah tamu (Hari Ini & Bulan Ini), riwayat scan. Tidak punya akses ke web |

> **[REKOMENDASI]** Satu akun Admin per orang (tidak berbagi akun) dengan password kuat, agar transaksi, void, dan cetak ulang dapat ditelusuri.

---

## 4. Alur Bisnis

```
Tamu datang → Loket (POS Web) → Bayar (Cash/Debit) → Cetak tiket ber-QR (berlaku hari ini)
→ Gate (Mobile) → Scan QR + validasi atomik → Tamu masuk & menonton
```

### 4.1 Transaksi Loket
- Petugas (login Admin) membuka POS dan mengisi data wajib pengunjung: **nama lengkap** (rombongan: nama penanggung jawab), **asal** (Domestik/Mancanegara → kota/kabupaten atau negara), **alamat singkat**, **nomor HP**. Disimpan di `customers`.
- Tidak ada pemilihan tanggal. Tanggal berlaku = tanggal transaksi (hari ini, WITA) sampai 24.00 WITA. Form menampilkan tanggal berlaku sebagai informasi read-only.
- Petugas memilih jenis penjualan:
  - **Individual:** 1 orang = 1 tiket = 1 QR; maks `max_individual_per_tx` per transaksi (default 4).
  - **Rombongan:** 1 tiket group per transaksi; min `min_group_size` (default 5), maks `max_group_members` (default 15); 1 QR untuk seluruh rombongan.
  - Pembelian kurang dari `min_group_size` wajib individual. Satu transaksi tidak mencampur individual dan rombongan.
- POS menyaring jenis tiket sesuai mode: Individual → Adult, Child; Rombongan → Adult Rombongan, Child Rombongan. Petugas mengisi jumlah tiap jenis.
- Sistem menghitung subtotal, diskon, total otomatis (bagian 4.2). Petugas tidak memilih/mengetik diskon.
- Pembayaran sebesar `total_amount`:
  - **Cash:** input uang diterima, sistem hitung kembalian (tidak boleh kurang dari total).
  - **Debit:** input nomor referensi/approval EDC (wajib).
- Saat simpan: transaksi `completed`, tiket `active` dengan `valid_date` = hari ini, dialog cetak thermal terbuka. Pembuatan transaksi, tiket, dan rincian tiket dalam **satu transaksi database**.
- Tiket fisik diserahkan ke pengunjung.

### 4.2 Harga dan Diskon
- **Jenis tiket per kategori:** `ticket_types` punya `ticket_category` (`individual`/`group`). Jenis awal: Adult, Child (individual); Adult Rombongan, Child Rombongan (group). Tiap jenis punya `price` dan `discount_percent` sendiri.
- **Harga dinamis:** Admin dapat mengubah harga sewaktu-waktu lewat Master Data Jenis Tiket; berlaku untuk transaksi berikutnya.
- **Diskon satu sumber:** `ticket_types.discount_percent` (default 0; 0–100). Diskon rombongan = `discount_percent` jenis group (default 5%). Promo individual dipasang pada jenis individual. Tidak ada diskon di `settings`.
- **Syarat rombongan:** tiket group hanya dijual bila total pengunjung ≥ `min_group_size` dan ≤ `max_group_members`. Tidak ada aturan penumpukan diskon.
- **Integritas riwayat:** harga, persentase, dan nilai diskon disalin ke `ticket_details` (`unit_price`, `discount_percent`, `discount_amount`, `subtotal`). Perubahan di kemudian hari tidak mengubah transaksi lama.
- **Pembulatan:** diskon tiap baris dibulatkan ke rupiah penuh. `discount_amount` transaksi = jumlah diskon seluruh baris.
- **Makna angka:**
  - `transactions.subtotal` = jumlah kotor sebelum diskon (Σ qty × unit_price)
  - `transactions.discount_amount` = jumlah diskon seluruh baris
  - `transactions.total_amount` = subtotal − discount_amount (**yang dibayar**)
  - `ticket_details.subtotal` = bersih per baris (qty × unit_price − discount_amount)
- **Promo:** Admin mengubah `discount_percent` manual saat event mulai/berakhir (belum ada rentang tanggal otomatis).
- **Dua baris harga dasar [REKOMENDASI]:** form edit harga memperingatkan bila harga individual dan rombongan untuk jenis yang sama berbeda.

**Contoh `ticket_types`** (harga fiktif):

| name | ticket_category | price | discount_percent |
|---|---|---|---|
| Adult | individual | Rp100.000 | 0 |
| Child | individual | Rp60.000 | 0 |
| Adult Rombongan | group | Rp100.000 | 5 |
| Child Rombongan | group | Rp60.000 | 5 |

**Contoh rombongan 6 orang (diskon 5%):**

| Jenis tiket | Qty | Harga satuan | Kotor | Diskon 5% | Bersih |
|---|---|---|---|---|---|
| Adult Rombongan | 4 | Rp100.000 | Rp400.000 | −Rp20.000 | Rp380.000 |
| Child Rombongan | 2 | Rp60.000 | Rp120.000 | −Rp6.000 | Rp114.000 |
| **Total (6 orang)** | | | **Rp520.000** | **−Rp26.000** | **Rp494.000** |

Transaksi: `subtotal` 520.000, `discount_amount` 26.000, `total_amount` 494.000.

### 4.3 Batas Input
- **Individual:** maks `max_individual_per_tx` (default 4 = `min_group_size` − 1). Kelompok ≥ 5 dijual sebagai rombongan.
- **Rombongan:** tepat 1 tiket group per transaksi; min `min_group_size` (5), maks `max_group_members` (15). Sistem menolak input di luar batas.
- Rombongan > 15 orang dibuat sebagai rombongan terpisah (tiap satu tiket group dan transaksi sendiri).
- Semua angka batas dapat diubah Admin via Pengaturan. Jenis tiket harus sesuai kategori tiket (individual hanya jenis individual; group hanya jenis group); aplikasi menolak kombinasi lain.

### 4.4 Pemindaian di Gate: Dua Mode
- **Mode Check-in (default, tercepat):** satu request ke `/api/ticket/validate-and-checkin`. Dalam satu transaksi DB server mengunci baris tiket, memeriksa status `active` dan `valid_date` = hari ini (WITA); bila valid ubah status ke `used` dan simpan riwayat.
- **Mode Cek (tanpa memakai tiket):** `/api/ticket/check` hanya membaca. Menampilkan status, tanggal berlaku, jenis, rincian pengunjung tanpa mengubah status. Bila valid, tersedia tombol "Check-in sekarang". **Tidak dicatat ke riwayat dan tidak memengaruhi metrik dashboard.**
- Pindah mode dengan satu sakelar di layar pemindai; default kembali ke Check-in setiap aplikasi dibuka.
- Hasil valid: hijau, berisi jenis tiket, jumlah pengunjung (rincian bila rombongan), nama pembawa, dengan suara/getar. Hasil tidak valid: warna sesuai kondisi + keterangan error eksplisit (bagian 7.3).
- Tiket rombongan: satu kali scan untuk seluruh anggota; rombongan selalu masuk sekaligus.

### 4.5 Cetak Ulang Tiket
- Admin dapat mencetak ulang tiket `active` yang masih berlaku hari ini dari riwayat penjualan (mis. printer macet).
- Cetak ulang tidak mengubah kode, status, atau tanggal berlaku; hanya mencatat siapa & kapan di `print_log` (`print_type = reprint`).
- Tiket `used`, `cancelled`, atau kedaluwarsa tidak dapat dicetak ulang.

### 4.6 Void Administratif
- Hanya Admin, hanya untuk kesalahan administratif (mis. salah jumlah), dan hanya selama tidak ada tiket pada transaksi yang berstatus `used`.
- Wajib mengisi alasan. Transaksi → `cancelled`, seluruh tiket → `cancelled` dalam satu transaksi DB; scan tiket tsb menghasilkan `CANCELLED`. Tercatat `voided_by`, `voided_at`, `void_reason`.
- Void **bukan refund**. Penanganan uang tunai mengikuti prosedur internal loket di luar sistem.
- Setelah void, petugas membuat transaksi baru dengan data benar.

### 4.7 Anonimisasi Data Pengunjung
- Setelah `retention_months` (default 12) dari tanggal transaksi, data `customers` dianonimkan oleh proses terjadwal: `name` → placeholder (mis. `ANONYMIZED`), `phone` & `address` → NULL, `anonymized_at` diisi.
- `origin_type` dan `origin` dipertahankan untuk rekap demografi **[REKOMENDASI]**.
- Data transaksi, tiket, dan check-in tidak dihapus sehingga laporan keuangan dan statistik tetap utuh.

---

## 5. Aturan Bisnis

| Kode | Aturan |
|---|---|
| BR-01 | Tiket hanya berlaku pada hari pembelian (tanggal transaksi, WITA) sampai 24.00 WITA; tidak dapat dipakai hari lain. |
| BR-02 | Tidak ada ganti jadwal dan tidak ada refund. Pengecualian terbatas: void administratif oleh Admin (BR-18). |
| BR-03 | Pembayaran hanya Cash atau Debit, diverifikasi di loket. Tanpa payment gateway. |
| BR-04 | Tiket individual: 1 tiket, 1 orang, 1 QR Code. |
| BR-05 | Tiket rombongan: 1 tiket group, 1 QR, atas nama penanggung jawab, mewakili seluruh grup; check-in mencatat seluruh anggota. |
| BR-06 | *(Diubah v2.5)* Diskon rombongan = `ticket_types.discount_percent` pada jenis kategori group (default 5%). Tiket group hanya dijual bila total pengunjung ≥ `min_group_size` (default 5). |
| BR-07 | Satu tiket hanya dapat berhasil di-scan (check-in) satu kali; setelah `used` tidak dapat dipakai lagi. |
| BR-08 | Gate dan loket full online. Tanpa koneksi, penjualan dan validasi tidak diproses; tidak ada antrean offline. |
| BR-09 | Seluruh waktu memakai WITA. |
| BR-10 | Satu transaksi hanya berisi penjualan individual **atau** rombongan, tidak dicampur. |
| BR-11 | Harga dinamis & dapat diedit Admin. Harga satuan, persentase diskon, dan nilai diskon saat transaksi disimpan di `ticket_details` (point-in-time pricing). |
| BR-12 | Data pengunjung (penanggung jawab untuk rombongan) tidak wajib lengkap di aplikasi: nama, asal, alamat(boleh null), nomor HP(boleh null). Di DB `phone` & `address` NULL-able agar anonimisasi bisa dilakukan. |
| BR-13 | Debit wajib mencatat nomor referensi EDC; Cash wajib uang diterima ≥ `total_amount`. |
| BR-14 | Setiap scan Check-in di gate dicatat, termasuk yang ditolak. |
| BR-15 | Jumlah pengunjung masuk dihitung dari scan berhasil, bukan dari tiket yang dicetak. |
| BR-16 | Tiket langsung `active` saat transaksi disimpan. |
| BR-17 | Web hanya dapat diakses pengguna internal (role Admin) yang login; tidak ada halaman publik. |
| BR-18 | Void hanya oleh Admin, alasan wajib, hanya bila belum ada tiket `used` pada transaksi. |
| BR-19 | Cetak ulang hanya oleh Admin, hanya untuk tiket `active` yang masih berlaku hari ini, dan selalu tercatat. |
| BR-20 | Individual: maks `max_individual_per_tx` (default 4) per transaksi. Rombongan: 1 tiket group per transaksi, min `min_group_size` (5), maks `max_group_members` (15). |
| BR-21 | Data pribadi pada `customers` dianonimkan 12 bulan setelah tanggal transaksi; data agregat tetap untuk laporan. |
| BR-22 | Tidak ada pembelian jauh hari. `valid_date` ditetapkan otomatis sistem, tidak dapat dipilih/diubah petugas. |
| BR-23 | Tidak ada batas kapasitas pengunjung harian. |
| BR-24 | *(Dihapus v2.4)* Konsep Guide tidak digunakan. |
| BR-25 | Mode Cek tidak mengubah status tiket dan tidak dicatat ke riwayat. |
| BR-26 | Parameter operasional (bagian 13.2) dapat diubah Admin lewat Pengaturan; tidak mengubah transaksi yang sudah ada. |
| BR-27 | Tiket belum dipakai sampai 24.00 WITA otomatis kedaluwarsa; scan setelahnya → `EXPIRED`. Tanpa perpanjangan, ganti hari, atau refund. |
| BR-28 | Pembelian kurang dari `min_group_size` orang wajib tiket individual. |
| BR-29 | *(Dihapus v2.5)* Aturan penumpukan/diskon terbesar tidak diperlukan. |
| BR-30 | *(Diubah v2.5)* Diskon promo/event diatur lewat `ticket_types.discount_percent` pada jenis individual, rombongan, atau keduanya, diubah manual Admin. |
| BR-31 | Pengunjung membayar `total_amount` (setelah diskon). `transactions.subtotal` = kotor; `ticket_details.subtotal` = bersih per baris. |
| BR-32 | Pembuatan transaksi + tiket + rincian, serta void, dilakukan dalam satu transaksi database (atomik). |
| BR-33 | *(Baru v2.5)* Setiap jenis tiket punya `ticket_category`. Tiket individual hanya memuat jenis individual; tiket group hanya jenis group. POS hanya menampilkan jenis sesuai mode, dan aplikasi memvalidasi kesesuaian. |
| BR-34 | *(Baru v2.5)* Perubahan harga/`discount_percent` hanya berlaku untuk transaksi berikutnya. Form edit menampilkan peringatan bila harga pasangan individual & rombongan berbeda **[REKOMENDASI]**. |

---

## 6. Modul Web (Admin)

### 6.1 Autentikasi
- Login username & password; sesi aman; seluruh halaman memerlukan login.
- Pembatasan login gagal (default 5 kali per 15 menit, diatur di Pengaturan).

### 6.2 POS Loket
- Form pengunjung, pilihan jenis penjualan, daftar jenis tiket sesuai kategori mode, penghitung diskon & total, kalkulator kembalian Cash, input referensi Debit. Tanggal berlaku tampil otomatis tanpa input.
- Form menolak jumlah di luar batas (4.3) dan menampilkan pesan bila pembelian di bawah `min_group_size` dicoba sebagai rombongan.
- Cetak thermal 58/80 mm lewat dialog cetak browser dengan CSS `@media print` (bagian 10). Mode kiosk-printing dapat dipakai.
- QR Code error correction level **M**, berisi **hanya kode tiket** tanpa data pribadi.
- Riwayat penjualan: daftar transaksi dengan filter tanggal, akun pembuat, metode bayar; aksi cetak ulang dan void.

### 6.3 Master Data dan Pengaturan
- **User:** akun Admin dan Gate (aktif/nonaktif, reset password).
- **Jenis tiket:** kelola per kategori. Admin mengedit harga, persentase diskon (satu-satunya tempat pengaturan diskon), kategori, status aktif. Peringatan bila harga pasangan individual/rombongan berbeda. POS selalu mengambil harga & diskon aktif terbaru dari DB.
- **Checkpoint/gate:** nama dan status aktif.
- **Pengaturan:** parameter bagian 13.2; nilai divalidasi saat disimpan; tiap perubahan mencatat siapa & kapan.

### 6.4 Dashboard dan Laporan
- **Dashboard:** penjualan hari ini, jumlah tiket terbit, pengunjung sudah masuk, tiket `active` belum dipakai (hanya `valid_date` hari ini), komposisi Cash/Debit.
- Laporan penjualan per hari, per akun Admin, per metode bayar (untuk mencocokkan uang tunai & struk EDC).
- Rekap diskon per hari, dipisah individual vs rombongan (dari `discount_amount` dan `tickets.ticket_category`).
- Rekap demografi: asal pengunjung (Domestik/Mancanegara, kota/negara).
- Rekap pengunjung masuk per hari, bulan, gate; audit scan (termasuk yang ditolak).
- Rekap tiket terjual vs terpakai per hari (selisih = tiket kedaluwarsa tidak terpakai).
- Semua laporan dapat diekspor (PDF dan Excel).

---

## 7. Modul Mobile Validator (Android, Flutter)

### 7.1 Login dan Sesi
- Login username/password pada `gate_users`; hanya akun aktif.
- Token API acak (opaque), disimpan hanya sebagai hash SHA-256, berlaku 12 jam (`api_token_hours`). Valid bila `revoked_at` kosong dan `expires_at` belum lewat. Logout mengisi `revoked_at`. Setiap panggilan API memperbarui `last_used_at`.
- Setelah login, petugas memilih checkpoint aktif (dari `/api/checkpoints`), disimpan untuk sesi.

### 7.2 Pemindaian Cepat
- Pemindai QR native (mis. `mobile_scanner`), pembacaan kontinu tanpa memuat ulang controller tiap scan.
- Debounce: setelah satu QR terbaca, input dikunci ±1 detik.
- Umpan balik: beep tinggi untuk sukses, dua bunyi untuk penolakan, plus getar.
- Satu request atomik per scan pada Mode Check-in; target respons API < 1 detik.
- Sakelar Mode Cek di layar pemindai.
- Input kode tiket manual sebagai cadangan bila QR rusak/basah.
- Setelah hasil tampil, layar kembali otomatis ke pemindai (mis. 2 detik atau sentuhan).

### 7.3 Hasil Scan

| Hasil | Tampilan | Keterangan |
|---|---|---|
| `VALID` | Hijau besar | Tiket active & berlaku hari ini. Check-in: status → used. Cek: status tidak berubah |
| `ALREADY_USED` | Merah | Sudah dipakai. Tampilkan waktu, petugas, gate saat dipakai |
| `EXPIRED` | Kuning | Melewati hari berlaku. Tampilkan tanggal berlaku |
| `NOT_FOUND` | Abu-abu | Kode tidak terdaftar (dicatat dengan `ticket_id` kosong, kode mentah di `scanned_code`) |
| `CANCELLED` | Merah | Dibatalkan melalui void administratif |

### 7.4 Dashboard Jumlah Tamu
Selektor periode **Hari Ini** dan **Bulan Ini** (tgl 1 s.d. hari ini, WITA). Metrik dari seluruh gate, hanya dari scan Mode Check-in:
- Total pengunjung masuk = Σ `visitor_count` pada scan berhasil.
- Total tiket valid = jumlah scan berhasil.
- Total ditolak = jumlah scan selain `VALID`.

### 7.5 Riwayat Validasi
Daftar scan Mode Check-in milik petugas login, terbaru di atas: kode tiket, waktu scan (HH:MM:SS WITA), hasil, checkpoint, jumlah orang. Scan ditolak ikut tercatat.

### 7.6 Koneksi Terputus
- Tampilkan pesan tidak ada koneksi; scan tidak diproses; tidak ada antrean offline.
- Bila koneksi putus tepat saat scan, scan ulang. `ALREADY_USED` dengan waktu barusan oleh petugas yang sama berarti scan sebelumnya berhasil. Mode Cek dapat dipakai untuk memastikan status.

---

## 8. Skema Database (MySQL)

12 tabel, InnoDB, utf8mb4. Semua tabel punya `created_at` (`NOT NULL DEFAULT CURRENT_TIMESTAMP`) dan `updated_at` (`ON UPDATE CURRENT_TIMESTAMP`).

### 8.1 Daftar Tabel

#### 1) `users` — Web Admin
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| name | VARCHAR(100), NOT NULL |
| username | VARCHAR(50), UNIQUE, NOT NULL |
| password_hash | VARCHAR(255), NOT NULL |
| role | ENUM('admin'), NOT NULL |
| is_active | TINYINT(1), NOT NULL, DEFAULT 1 |
| created_at, updated_at | DATETIME |

#### 2) `gate_users` — Akun Mobile Validator
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| name | VARCHAR(100), NOT NULL |
| username | VARCHAR(50), UNIQUE, NOT NULL |
| password_hash | VARCHAR(255), NOT NULL |
| phone | VARCHAR(25), NULL |
| is_active | TINYINT(1), NOT NULL, DEFAULT 1 |
| created_at, updated_at | DATETIME |

#### 3) `customers` — Data Pengunjung
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| name | VARCHAR(100), NOT NULL (placeholder saat anonimisasi) |
| phone | VARCHAR(25), NULL, INDEX (dikosongkan saat anonimisasi) |
| origin_type | ENUM('domestic','international'), NOT NULL |
| origin | VARCHAR(100), NOT NULL (kota/kabupaten atau negara) |
| address | VARCHAR(255), NULL (dikosongkan saat anonimisasi) |
| anonymized_at | DATETIME, NULL |
| created_at, updated_at | DATETIME |

#### 4) `ticket_types` *(diubah v2.5)* — satu-satunya sumber harga & diskon
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| name | VARCHAR(50), UNIQUE, NOT NULL: Adult, Child, Adult Rombongan, Child Rombongan |
| ticket_category | ENUM('individual','group'), NOT NULL *(baru v2.5)* |
| price | DECIMAL(12,2), NOT NULL. CHECK price ≥ 0 |
| discount_percent | DECIMAL(5,2), NOT NULL, DEFAULT 0. CHECK 0–100 |
| is_active | TINYINT(1), NOT NULL, DEFAULT 1 |
| created_at, updated_at | DATETIME |

Data awal: Adult/individual/100000/0; Child/individual/60000/0; Adult Rombongan/group/100000/5; Child Rombongan/group/60000/5.

#### 5) `checkpoints`
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| name | VARCHAR(50), NOT NULL (mis. Gate 1) |
| is_active | TINYINT(1), NOT NULL, DEFAULT 1 |
| created_at, updated_at | DATETIME |

#### 6) `transactions`
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| invoice_code | VARCHAR(30), UNIQUE, NOT NULL: `INV-YYYYMMDD-XXXXXX` |
| customer_id | INT, NOT NULL, FK customers.id |
| created_by | INT, NOT NULL, FK users.id |
| payment_method | ENUM('cash','debit'), NOT NULL |
| payment_reference | VARCHAR(100), NULL (wajib oleh aplikasi jika debit) |
| subtotal | DECIMAL(12,2), NOT NULL (kotor sebelum diskon) |
| discount_amount | DECIMAL(12,2), NOT NULL, DEFAULT 0 |
| total_amount | DECIMAL(12,2), NOT NULL (subtotal − discount_amount) |
| cash_paid | DECIMAL(12,2), NULL (wajib untuk cash) |
| cash_change | DECIMAL(12,2), NULL (wajib untuk cash) |
| status | ENUM('completed','cancelled'), NOT NULL, DEFAULT 'completed' |
| voided_by | INT, NULL, FK users.id |
| voided_at | DATETIME, NULL |
| void_reason | VARCHAR(255), NULL |
| created_at, updated_at | DATETIME (WITA) |

#### 7) `tickets`
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| transaction_id | INT, NOT NULL, FK transactions.id |
| ticket_code | CHAR(23), UNIQUE, NOT NULL: `TKT-...` atau `GRP-...` |
| ticket_category | ENUM('individual','group'), NOT NULL (harus sama dengan kategori jenis tiket di ticket_details; dijaga aplikasi) |
| valid_date | DATE, NOT NULL, INDEX (diisi server) |
| total_visitors | INT, NOT NULL, DEFAULT 1 (Σ qty di ticket_details) |
| status | ENUM('active','used','cancelled'), NOT NULL, DEFAULT 'active' |
| used_at | DATETIME, NULL |
| used_by_gate_user_id | INT, NULL, FK gate_users.id |
| used_checkpoint_id | INT, NULL, FK checkpoints.id |
| created_at, updated_at | DATETIME |

#### 8) `ticket_details`
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| ticket_id | INT, NOT NULL, FK tickets.id |
| ticket_type_id | INT, NOT NULL, FK ticket_types.id |
| qty | INT, NOT NULL |
| unit_price | DECIMAL(12,2), NOT NULL (snapshot) |
| discount_percent | DECIMAL(5,2), NOT NULL, DEFAULT 0 (snapshot) |
| discount_amount | DECIMAL(12,2), NOT NULL, DEFAULT 0 (dibulatkan) |
| subtotal | DECIMAL(12,2), NOT NULL (qty × unit_price − discount_amount) |
| created_at, updated_at | DATETIME |

Constraint: `UNIQUE (ticket_id, ticket_type_id)`.

#### 9) `checkin_history` — log append-only
| Kolom | Tipe & Ketentuan |
|---|---|
| id | BIGINT, PK, AI |
| ticket_id | INT, NULL, FK tickets.id (NULL bila kode tidak ditemukan) |
| scanned_code | VARCHAR(50), NOT NULL (string mentah) |
| checkpoint_id | INT, NOT NULL, FK checkpoints.id |
| gate_user_id | INT, NOT NULL, FK gate_users.id |
| visitor_count | INT, NOT NULL, DEFAULT 0 (0 bila gagal) |
| result | ENUM('VALID','ALREADY_USED','EXPIRED','NOT_FOUND','CANCELLED'), NOT NULL |
| scanned_at | DATETIME, NOT NULL, INDEX (WITA) |
| created_at, updated_at | DATETIME |

#### 10) `gate_api_tokens`
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| gate_user_id | INT, NOT NULL, FK gate_users.id |
| token_hash | CHAR(64), UNIQUE, NOT NULL (SHA-256) |
| expires_at | DATETIME, NOT NULL, INDEX |
| revoked_at | DATETIME, NULL |
| last_used_at | DATETIME, NULL |
| created_at, updated_at | DATETIME |

#### 11) `print_log`
| Kolom | Tipe & Ketentuan |
|---|---|
| id | INT, PK, AI |
| ticket_id | INT, NOT NULL, FK tickets.id |
| printed_by | INT, NOT NULL, FK users.id |
| print_type | ENUM('initial','reprint'), NOT NULL |
| printed_at | DATETIME, NOT NULL |
| created_at, updated_at | DATETIME |

#### 12) `settings` *(diubah v2.5; tanpa parameter diskon)*
| Kolom | Tipe & Ketentuan |
|---|---|
| setting_key | VARCHAR(50), PK |
| setting_value | VARCHAR(255), NOT NULL |
| updated_by | INT, NULL, FK users.id |
| created_at, updated_at | DATETIME |

Data awal: `min_group_size = 5` (+ parameter bagian 13.2).

### 8.2 Relasi Entitas Utama
- `customers` 1:N `transactions`.
- `users` membuat transaksi, melakukan void, dan mencetak (`print_log`).
- `gate_users` independen dari panel web; 1:N `gate_api_tokens` dan 1:N `checkin_history`.
- `transactions` 1:N `tickets` (individual: 1 tiket per orang; rombongan: 1 tiket group). Komposisi & snapshot harga/diskon di `ticket_details`.
- `ticket_types` 1:N `ticket_details`; kategori jenis tiket harus sesuai kategori tiket induk.
- `tickets` 1:N `checkin_history` (banyak percobaan, hanya satu `VALID`).

### 8.3 Indeks Penting
- `customers`: INDEX (phone), INDEX (name)
- `transactions`: UNIQUE (invoice_code), INDEX (customer_id), INDEX (created_at), INDEX (created_by, created_at)
- `tickets`: UNIQUE (ticket_code), INDEX (valid_date), INDEX (transaction_id), INDEX (status), INDEX (valid_date, status)
- `ticket_types`: UNIQUE (name); disarankan INDEX (ticket_category, is_active)
- `ticket_details`: UNIQUE (ticket_id, ticket_type_id)
- `checkin_history`: INDEX (scanned_at), INDEX (gate_user_id, scanned_at), INDEX (result, scanned_at)
- `gate_api_tokens`: UNIQUE (token_hash), INDEX (expires_at)
- `gate_users`, `users`: UNIQUE (username)

### 8.4 CHECK Constraint & Aturan Sisi Aplikasi

**CHECK constraint (MySQL 8.0.16+):**

| Tabel | Constraint |
|---|---|
| ticket_types | `price ≥ 0`; `discount_percent` 0–100 |
| transactions | `subtotal ≥ 0`; `discount_amount ≥ 0 AND ≤ subtotal`; `total_amount = subtotal − discount_amount` |
| transactions (cash) | Bila `cash`: `cash_paid` & `cash_change` terisi, `cash_paid ≥ total_amount`, `cash_change = cash_paid − total_amount` |
| transactions (void) | Bila `cancelled`: `voided_by`, `voided_at`, `void_reason` terisi |
| tickets | individual → `total_visitors = 1`; group → `total_visitors ≥ 2` |
| tickets (used) | Bila `used`: `used_at`, `used_by_gate_user_id`, `used_checkpoint_id` terisi |
| ticket_details | `qty > 0`; `unit_price ≥ 0`; `discount_percent` 0–100; `discount_amount ≥ 0`; `subtotal = qty × unit_price − discount_amount` |
| checkin_history | `visitor_count ≥ 0` |

**Dijaga aplikasi (service layer):**
- Kesesuaian kategori: `ticket_types.ticket_category` pada tiap `ticket_details` harus sama dengan `tickets.ticket_category` (lintas tabel).
- Batas minimal rombongan sesuai `min_group_size` (DB hanya menjamin group ≥ 2).
- Batas maksimal individual dan rombongan; pembulatan diskon ke rupiah penuh.
- `payment_reference` wajib untuk debit; `valid_date` diisi server; `total_visitors`, `subtotal`, `total` dihitung di satu tempat dan tidak diedit manual.
- Validasi `settings`: `min_group_size` bilangan bulat ≥ 2; batas jumlah bilangan bulat positif.
- Void tidak boleh bila ada tiket `used`; transaksi & void dalam satu transaksi DB.
- `invoice_code` dan `ticket_code` acak; siapkan retry bila tabrakan UNIQUE.

---

## 9. REST API (Mobile Validator)

Prefix `/api`, HTTPS, JSON UTF-8, waktu ISO 8601 dengan offset WITA. Fitur web dijalankan controller web CodeIgniter, bukan REST API mobile.

| Method | Endpoint | Fungsi |
|---|---|---|
| POST | `/api/login` | Login petugas gate; mengembalikan token |
| POST | `/api/logout` | Logout & cabut token (isi `revoked_at`) |
| GET | `/api/me` | Informasi akun |
| GET | `/api/checkpoints` | Daftar checkpoint aktif |
| POST | `/api/ticket/check` | Mode Cek: baca status tanpa mengubah |
| POST | `/api/ticket/validate-and-checkin` | Mode Check-in: validasi & check-in atomik |
| GET | `/api/dashboard/summary` | Ringkasan pengunjung (`period=today` / `month`) |
| GET | `/api/ticket/history` | Riwayat scan milik petugas |

**Autentikasi tiap request:** `token_hash` cocok, `revoked_at` kosong, `expires_at` belum lewat, akun `gate_users` aktif; `last_used_at` diperbarui.

### 9.1 Check-in Atomik
1. Dalam satu transaksi DB: `SELECT ... FOR UPDATE` pada tiket; periksa kondisi.
2. Bila valid: `UPDATE tickets SET status='used', used_at, used_by_gate_user_id, used_checkpoint_id WHERE id = ? AND status = 'active'`; pastikan `affected rows = 1`.
3. Simpan `checkin_history`; `COMMIT`.

- **Urutan pemeriksaan:** `NOT_FOUND` → `CANCELLED` → `ALREADY_USED` → `EXPIRED` (`valid_date` < hari ini WITA) → `VALID`.
- Scan bersamaan oleh dua petugas: satu berhasil, satu `ALREADY_USED`.
- Scan ditolak tetap dicatat (`NOT_FOUND`: `ticket_id` kosong, `scanned_code` mentah).
- Pengaman check-in ganda = penguncian baris + UPDATE bersyarat; kolom `success_ticket_id` tidak digunakan.

### 9.2 Cek Tanpa Memakai Tiket
- `/api/ticket/check` hanya membaca (tanpa lock tulis) dan tidak menulis ke `checkin_history`.
- Hasil dapat berubah antara Cek dan Check-in (mis. dipakai di gate lain); keputusan akhir tetap pada `/validate-and-checkin`.

---

## 10. Tata Letak Cetak Tiket (Thermal 58/80 mm)

Setiap QR dicetak pada potongan terpisah. Potongan pertama memuat rincian pembayaran; potongan berikutnya (tiket individual tambahan) hanya memuat data tiket dan QR. Contoh potongan pertama tiket rombongan:

```
================================
PERTUNJUKAN KECAK ULUWATU
Pecatu, Kuta Selatan, Badung
================================
Tanggal : 05/10/2026 15:20 WITA
Nota    : INV-20261005-K7M3QX
Petugas : Admin Loket 1
Nama    : Hendra Gunawan
Asal    : Jakarta (Domestik)
Bayar   : CASH
--------------------------------
ROMBONGAN (GROUP)
4x Adult Rombongan      400.000
   @100.000
2x Child Rombongan      120.000
   @60.000
Subtotal                520.000
Diskon Rombongan 5%     -26.000
--------------------------------
TOTAL BAYAR         Rp 494.000
TUNAI               Rp 500.000
KEMBALIAN           Rp   6.000
--------------------------------
TOTAL PENGUNJUNG: 6 ORANG

        [ QR CODE ]
  GRP-7K3M-9QXD-4TFH-W2PB

BERLAKU HANYA HARI INI:
05 OKTOBER 2026
SAMPAI PUKUL 24.00 WITA
TIDAK DAPAT DI-REFUND
================================
```

- Nama tempat, alamat, dan teks penutup diatur lewat Pengaturan.
- Tanggal berlaku pada struk selalu = tanggal transaksi.
- Baris diskon menampilkan persentase dari jenis tiket: "Diskon Rombongan" untuk tiket group, "Diskon Promo" untuk tiket individual.
- Nilai pada struk adalah contoh fiktif.

---

## 11. Keamanan

- Mobile tidak mengakses database; seluruh komunikasi lewat HTTPS dan REST API.
- Password di-hash dengan algoritma aman; kredensial & kunci tidak ditanam di source code.
- Seluruh halaman web memerlukan login Admin; proteksi CSRF pada form; pembatasan login gagal.
- Akun Admin sangat sensitif: satu akun per orang & password kuat **[REKOMENDASI]**. Perubahan harga/diskon hanya untuk Admin dan dicatat.
- Token API acak, hanya hash (SHA-256) disimpan, berlaku 12 jam, dapat dicabut saat logout, penggunaan tercatat (`last_used_at`). Token kedaluwarsa dibersihkan berkala memakai indeks `expires_at`.
- Kode tiket acak (CSPRNG), tidak berurutan; QR tanpa data pribadi.
- Tanggal berlaku, harga, dan diskon ditentukan server dari database, bukan input klien.
- Check-in memakai transaksi DB dengan row lock dan UPDATE bersyarat (`status = 'active'`).
- Aktivitas sensitif tercatat: scan gate, void, cetak ulang, perubahan Pengaturan.
- Data pribadi pengunjung dibatasi aksesnya ke Admin dan dianonimkan sesuai BR-21. Papan pemberitahuan pengumpulan data dipasang di loket **[REKOMENDASI]**.

---

## 12. Teknologi dan Kebutuhan Non-Fungsional

| Komponen | Keterangan |
|---|---|
| Web | PHP, CodeIgniter 4, MySQL 8.0.16+; CSS `@media print` untuk thermal 58/80 mm |
| Mobile | Flutter, Dart, Android 8.0+ (API 26) **[REKOMENDASI]**; izin kamera |
| Backend | CodeIgniter 4 REST API, token-based auth, HTTPS |
| Printer | Thermal 58/80 mm lewat dialog cetak browser (opsional kiosk-printing) |
| Kinerja | Respons validasi gate < 1 detik pada jaringan normal; query tiket memakai unique index `ticket_code` |
| Keandalan | Backup DB harian, retensi minimal 14 hari, uji pemulihan berkala **[REKOMENDASI]** |
| Konektivitas | Loket dan gate butuh internet. Bila terputus, penjualan & validasi ditunda (BR-08) |

---

## 13. Keputusan dan Konfigurasi

### 13.1 Keputusan yang Dikonfirmasi

| Topik | Keputusan | Status |
|---|---|---|
| Role | Satu role web: Admin. Penjaga loket memakai akun Admin. Role gate hanya mobile (`gate_users`) | Dikonfirmasi |
| Masa berlaku tiket | 1 hari, hari pembelian sampai 24.00 WITA. Tanpa pembelian jauh hari/pilih tanggal | Dikonfirmasi |
| Satu kali scan | Setelah scan: `used`. Rombongan masuk sekaligus | Dikonfirmasi |
| Void administratif | Hanya Admin | Dikonfirmasi |
| Cetak ulang | Hanya Admin | Dikonfirmasi |
| Mode cek | Ada (`/api/ticket/check`) | Dikonfirmasi |
| Kuota kapasitas | Tidak ada | Dikonfirmasi |
| Guide (v2.4) | Tidak ada. Rombongan atas nama penanggung jawab (`customers`) | Dikonfirmasi |
| Diskon satu sumber (v2.5) | Hanya dari `ticket_types.discount_percent`; rombongan default 5% | Dikonfirmasi |
| Jenis tiket per kategori (v2.5) | `ticket_category` individual/group; Adult & Child dipisah | Dikonfirmasi |
| Syarat rombongan | Min `min_group_size` (5), maks 15 orang per tiket group | Dikonfirmasi |
| Makna subtotal & total | `subtotal` kotor; pengunjung bayar `total_amount` | Dikonfirmasi |
| Batas individual per transaksi | Default 4 (`min_group_size` − 1) | [REKOMENDASI] |
| Peringatan harga berbeda | Form edit memperingatkan bila harga pasangan berbeda | [REKOMENDASI] |
| Retensi data pribadi | Anonimisasi 12 bulan; `origin` tetap disimpan | Dikonfirmasi (origin: [REKOMENDASI]) |
| Konfigurasi | Dapat diubah Admin lewat Pengaturan | Dikonfirmasi |
| Satu akun per orang | Untuk jejak audit | [REKOMENDASI] |
| Mode cek tidak dicatat | Tidak masuk riwayat/metrik | [REKOMENDASI] |
| Cara hitung 15 orang | Total pengunjung pada tiket rombongan | [REKOMENDASI] |
| Pergantian hari | Kedaluwarsa tepat 00:00 WITA | [REKOMENDASI] |

### 13.2 Parameter Pengaturan (tabel `settings`)

| Kunci | Nilai awal | Fungsi |
|---|---|---|
| `business_name`, `business_address` | Pertunjukan Kecak Uluwatu; alamat tempat | Header struk |
| `receipt_footer` | Teks penutup (mis. "Tidak dapat di-refund") | Footer struk |
| `min_group_size` | 5 | Minimal total pengunjung tiket rombongan; bilangan bulat ≥ 2 |
| `max_individual_per_tx` | 4 | Maks tiket individual per transaksi (disarankan = `min_group_size` − 1) |
| `max_group_members` | 15 | Maks total orang per tiket rombongan |
| `retention_months` | 12 | Masa sebelum data pribadi dianonimkan |
| `login_max_attempts`, `login_lock_minutes` | 5 dan 15 | Pembatasan login gagal |
| `api_token_hours` | 12 | Masa berlaku token mobile (berlaku pada login berikutnya) |

> Harga tiket dan seluruh diskon dikelola Admin pada menu Master Jenis Tiket (`ticket_types`). Tabel `settings` tidak memuat diskon. Perubahan tidak memengaruhi transaksi yang sudah tersimpan.

---

## 14. Rencana Implementasi dan Pengujian

| Sprint | Fokus |
|---|---|
| **1 — Backend & skema** | Migrasi skema final (12 tabel, CHECK, indeks, `ticket_category`), autentikasi, master data (user, jenis tiket per kategori, checkpoint), Pengaturan dengan validasi nilai |
| **2 — Web POS** | Form pengunjung, mode penjualan + penyaringan jenis tiket per kategori, batas individual & rombongan (5–15), perhitungan diskon & pembulatan, pembayaran Cash/Debit, kode & QR, `valid_date` otomatis, snapshot ke `ticket_details`, CSS print thermal, cetak ulang |
| **3 — Admin** | Riwayat penjualan, void administratif, dashboard, laporan (termasuk rekap diskon individual vs rombongan), ekspor |
| **4 — REST API gate** | login, logout (cabut token), me, checkpoints, check, validate-and-checkin atomik, dashboard, riwayat; optimasi indeks; uji Postman termasuk scan bersamaan & skenario `EXPIRED` |
| **5 — Mobile Validator (Flutter)** | Pemindai cepat, sakelar Mode Cek, layar hasil + suara/getar, dashboard Hari Ini & Bulan Ini, riwayat, input manual |
| **6 — Uji lapangan** | Kecepatan cetak loket, latensi scan (< 1 detik), uji printer 58/80 mm, uji antrean, koneksi terputus, pergantian hari (23:59 vs 00:00 WITA) |

### Skenario Uji Tambahan (v2.5)
- [ ] Mode Individual hanya menampilkan jenis individual; mode Rombongan hanya jenis group. Kombinasi lintas kategori pada satu tiket ditolak.
- [ ] Rombongan 4 orang ditolak; 5 orang berhasil dengan `discount_percent` jenis group (5%); 16 orang ditolak.
- [ ] Ubah `discount_percent` jenis group atau `min_group_size`: transaksi baru mengikuti nilai baru, transaksi lama tidak berubah (snapshot).
- [ ] Promo 10% pada jenis individual tidak memengaruhi harga rombongan, dan sebaliknya.
- [ ] Total = subtotal kotor − diskon; kembalian Cash dihitung dari `total_amount`; CHECK constraint menolak data tidak konsisten.
- [ ] Form edit harga menampilkan peringatan bila harga pasangan individual & rombongan berbeda.
- [ ] Token yang sudah logout (`revoked_at` terisi) ditolak; `last_used_at` terisi pada pemakaian normal.
- [ ] Void transaksi dengan tiket `used` ditolak; void normal membatalkan transaksi dan seluruh tiket sekaligus.
