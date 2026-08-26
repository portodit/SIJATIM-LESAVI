# Troubleshooting SIJATIM

## Error: "Could not open input file: artisan"

**Penyebab:**
Menjalankan `php artisan serve` dari direktori yang salah (`C:\Users\USER\`).

**Solusi:**
Selalu jalankan dari direktori project:

```powershell
cd C:\Users\USER\Documents\SIJATIM
php artisan serve
```

---

## Error: "ENOENT: no such file or directory, open 'C:\Users\USER\package.json'"

**Penyebab:**
Menjalankan `npm run dev` dari direktori yang salah (`C:\Users\USER\`). File `package.json` tidak ada di direktori tersebut.

**Solusi:**
Selalu jalankan dari direktori project:

```powershell
cd C:\Users\USER\Documents\SIJATIM
npm run dev
```

---

## Cara Menjalankan Project dengan Benar

### 1. Masuk ke direktori project
```powershell
cd C:\Users\USER\Documents\SIJATIM
```

### 2. Jalankan Laravel Backend
```powershell
php artisan serve --host=127.0.0.1 --port=8000
```
Akses: http://127.0.0.1:8000

### 3. Jalankan Vite Frontend (di terminal baru)
```powershell
npm run dev
```
Akses: http://localhost:5173

---

## Catatan Penting

- **Laravel** membutuhkan file `artisan` yang ada di root project
- **npm/Vite** membutuhkan file `package.json` yang ada di root project
- Kedua command HARUS dijalankan dari dalam folder `C:\Users\USER\Documents\SIJATIM`
- Jika di terminal muncul error seperti di atas, pastikan sudah melakukan `cd` ke direktori project terlebih dahulu

## Struktur Direktori yang Benar

```
C:\Users\USER\Documents\SIJATIM\
├── artisan              ← ada di sini
├── package.json         ← ada di sini
├── app/
├── database/
├── routes/
└── ...
```

Bukan di `C:\Users\USER\` (yang tidak memiliki file `artisan` maupun `package.json`).
