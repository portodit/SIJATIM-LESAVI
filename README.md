# SIJATIM

## Tentang

SIJATIM adalah aplikasi invoice layanan Telkom untuk Bank Jatim. Aplikasi ini menghitung tagihan bulanan per SSID, lalu membuat file invoice (Excel, PDF, dan gambar ringkasan PNG).

Operator bekerja dalam 3 langkah per periode:

1. **Layanan & Downtime**: isi data SSID, perubahan layanan (upgrade, downgrade, PSB, relokasi, cabut), dan downtime.
2. **Periksa & Hitung**: cek daftar masalah, lalu klik **Hitung Invoice**.
3. **Terbitkan**: **Finalisasi & buat file** mengunci periode dan membuat file Excel, PDF, dan gambar ringkasan.

Stack: Laravel 13, Blade + Tailwind (Vite), Livewire/Jetstream untuk login, SQLite, antrean (queue) di database.

## Kebutuhan

- **PHP 8.4 atau lebih baru**, dengan ekstensi: `pdo_sqlite`, `sqlite3`, `gd` (dengan FreeType), `zip`, `mbstring`, `fileinfo`, `openssl`, `xml`, `dom`.
- Pengaturan `php.ini`:
  - `upload_max_filesize = 32M`
  - `post_max_size = 32M`
  - `memory_limit = 1024M` (pembuatan Excel/PDF satu bulan butuh sekitar 630 MB)
- **Composer 2**
- **Node.js** `^20.19` atau `>= 22.12`, dan npm.

Di Windows, ekstensi di atas harus diaktifkan di `php.ini`: hapus tanda `;` di depan baris `extension=pdo_sqlite`, `extension=sqlite3`, `extension=gd`, `extension=zip`, `extension=mbstring`, `extension=fileinfo`, `extension=openssl`. Sebaiknya aktifkan juga `extension=curl` supaya Composer lebih cepat. Lokasi file: `php --ini`.

Cek cepat:

```
php -v
php -m
php -r "var_dump(gd_info()['FreeType Support']);"
```

Hasil terakhir harus `bool(true)`. Tanpa FreeType, gambar ringkasan PNG gagal dibuat.

## Instalasi

```
git clone <url-repo> SIJATIM-LESAVI
cd SIJATIM-LESAVI
git switch feature/billing-workflow
composer setup
```

`composer setup` menjalankan, berurutan:

1. `composer install`
2. salin `.env.example` ke `.env` (jika `.env` belum ada)
3. `php artisan key:generate`
4. `php artisan migrate --force`: membuat `database/database.sqlite` dan semua tabel
5. `php artisan db:seed --force`: mengisi data master dan **data invoice yang sudah ada** (lihat bagian "Data invoice yang sudah ada")
6. `npm install --ignore-scripts`
7. `npm run build`

Setelah selesai, lanjut ke bagian "Menjalankan".

Kalau lebih suka manual, langkahnya sama:

```
composer install
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
npm install --ignore-scripts
npm run build
```

Catatan: `.env` dan `database/database.sqlite` tidak ikut git. Semua opsi di `.env.example` sudah punya nilai bawaan yang cocok untuk komputer lokal.

## Menjalankan

Cara paling mudah:

```
composer dev
```

Perintah ini menjalankan tiga proses sekaligus: server (`php artisan serve`, alamat `http://127.0.0.1:8000`), worker antrean, dan Vite.

Atau jalankan sendiri-sendiri, di tiga terminal:

```
php artisan serve
```

Worker antrean:

- Windows: klik dua kali `start-queue-worker.bat`, dan biarkan jendelanya terbuka.
- macOS / Linux:

  ```
  php -d memory_limit=1024M artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=300 --memory=1024
  ```

Vite (hanya saat mengubah tampilan):

```
npm run dev
```

**Kenapa perlu worker?** Setelah **Finalisasi & buat file**, file Excel, PDF, dan gambar ringkasan dibuat oleh job antrean (`GenerateInvoiceDocuments`). Tanpa worker, periode terkunci tetapi file tidak pernah jadi. Secara bawaan aplikasi juga mencoba menjalankan worker sekali-jalan sendiri (`BILLING_AUTO_WORKER=true`), jadi di komputer lokal biasanya cukup `php artisan serve`. Kalau file tidak muncul, jalankan worker di atas.

Opsi `.env` lain (`BILLING_AUTO_WORKER`, `BILLING_WORKER_MEMORY_MB`, `DB_BUSY_TIMEOUT`, `DB_QUEUE_RETRY_AFTER`) dijelaskan di blok SIJATIM pada `.env.example`.

## Login

Registrasi dinonaktifkan. Setelah seeding, masuk dengan:

- Email: `test@example.com`
- Password: `password`

## Data invoice yang sudah ada

Folder `database/seed-data/` berisi paket data yang ikut di git. Paket ini dimuat oleh `php artisan db:seed` (dan `migrate --seed`). Isinya:

- semua tabel aplikasi (master SSID, tarif, periode, layanan per periode, perubahan, downtime, hasil hitung, dan lain-lain);
- periode **JAN26**, **AGU26**, **SEP26** (sudah difinalisasi) dan **OKT26** (belum difinalisasi);
- file invoice yang sudah dibuat dan file sumber yang pernah diunggah;
- user untuk login (`test@example.com`, password `password`).

Kembali ke data dari repo (semua perubahan lokal hilang):

```
php artisan migrate:fresh --seed
```

Memperbarui paket (**hanya pemilik data**): kerjakan di database lokal, lalu

```
php artisan sijatim:export-seed-data
git add database/seed-data
git commit
```

Developer lain cukup `git pull`, lalu `php artisan migrate:fresh --seed` untuk mendapat data terbaru.

**Peringatan: repo ini publik.** Semua isi `database/seed-data/` bisa dibaca siapa saja. Jangan jalankan `sijatim:export-seed-data` lalu commit kalau Anda bukan pemilik data, dan periksa isinya dulu sebelum push.

Tanpa paket data (misalnya folder `database/seed-data` dihapus), `db:seed` hanya mengisi data master. Daftar link Bank Jatim lalu bisa diimpor dari CSV: isi `SIJATIM_LINKS_CSV=` di `.env` dengan path file CSV. Kalau kosong atau file tidak ada, langkah ini dilewati tanpa error.

## Tes

```
php artisan test
```

Tes memakai database SQLite di memori, jadi tidak menyentuh `database/database.sqlite`.

## Dokumentasi lanjutan

Alur operator, aturan hitung (prorata 30 hari, SLA, restitusi, PPN), isi file output, dan cara menjalankan worker di server: [docs/AUTOMATED-INVOICING.md](docs/AUTOMATED-INVOICING.md).
