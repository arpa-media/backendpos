# Iterasi 08 — Regression Hardening & Rollout Checklist

Dokumen ini dipakai **setelah** patch Iterasi 01–07 + hotfix diterapkan.
Tujuannya bukan mengubah business flow, tetapi memberi prosedur validasi yang repeatable sebelum rollout penuh.

## 1. Pre-flight backend

Jalankan:

```bash
php artisan optimize:clear
php artisan pos:smoke-check
```

Mode ketat:

```bash
php artisan pos:smoke-check --strict
```

Output yang diharapkan:
- status `OK` atau `WARN`
- tidak ada `FAIL`
- route kritikal, permission route, dan tabel utama terbaca

## 2. Pre-flight build frontend

### Frontend normal

```bash
cd frontend
npm install
npm run build
```

### Frontend bluetooth

```bash
cd "frontend - Bluetooth"
npm install
npm run build
```

Jika build lolos, lanjut ke smoke manual.

## 3. Smoke manual POS normal

Uji minimum:
1. Login POS
2. Buka halaman kasir
3. Tambah item ke cart
4. Tambah discount
5. Checkout cash
6. Checkout non-cash
7. Print flow tetap muncul
8. Simpan transaksi offline
9. Jalankan sync transaksi
10. Pastikan transaksi masuk ke backend

## 4. Smoke manual POS bluetooth

Uji minimum:
1. Login POS bluetooth
2. Buka halaman kasir
3. Tambah item ke cart
4. Tambah discount
5. Checkout
6. Jalankan print bluetooth
7. Simpan transaksi offline
8. Jalankan sync transaksi
9. Pastikan transaksi masuk ke backend

## 5. Smoke manual backoffice

### Scope & dashboard
- Dashboard tampil
- outlet scope lokal tampil pada halaman yang membutuhkan
- topbar tidak lagi menjadi source of truth filter

### Finance
- Sales Collected terbuka
- filter date dan outlet scope berjalan
- klik nomor sale membuka modal detail
- CSV Sales Collected tetap unduh semua data terfilter
- Sales Summary, Category Summary, Report, Overview Finance dapat download CSV all-filter
- Item Summary tampil, filter berjalan, CSV jalan

### Sales
- Sales list tampil
- buka Sale Detail dari menu Sales
- tombol back kembali ke filter sebelumnya

### User Management
- User Management tampil
- checklist permission berubah aktual
- edit profile user bisa menyimpan username, NISJ, assignment, outlet
- menu baru (Finance Overview, Item Summary) muncul/hilang sesuai permission

## 6. Audit discount/tax

```bash
php artisan pos:audit-discount-tax --only-mismatch --limit=200
```

Jika perlu dry-run repair:

```bash
php artisan pos:repair-discount-tax --dry-run --limit=200
```

## 7. Rollout order yang aman

1. Backup database
2. Deploy backend
3. Jalankan migration bila ada
4. Jalankan `php artisan optimize:clear`
5. Jalankan `php artisan pos:smoke-check`
6. Deploy frontend web/admin
7. Build frontend normal + bluetooth bila ada update bundle
8. Jalankan smoke manual minimum
9. Baru buka rollout penuh ke user operasional

## 8. Rollback hint

Rollback dipertimbangkan jika terjadi salah satu kondisi ini:
- login POS gagal massal
- checkout gagal
- sync transaksi offline gagal
- sale detail / finance report gagal total
- user management lockout akses admin

Langkah minimum:
1. hentikan rollout
2. restore code ke patch stabil sebelumnya
3. `php artisan optimize:clear`
4. pastikan endpoint kritikal kembali normal
5. cek lagi dengan `php artisan pos:smoke-check`

## 9. Catatan penting

- Jangan ubah print layout / print method saat hardening.
- Jika ada hotfix baru, jalankan lagi smoke check ini dari awal.
- Untuk issue finance/performance, prioritaskan cek `Sales Collected`, `Overview Finance`, `Item Summary`, dan `User Management`.
