# Perbaikan Logic Step 1 - SSID Import & Sequence Management

**Tanggal:** 2026-07-06  
**Modul:** Invoice Period Step 1 - SSID List Import

---

## Masalah yang Ditemukan

### 1. Line Number Tidak Mengikuti Database Master

**Sebelum:**
Saat import SSID dari dokumen Excel, sistem meng-generate `line_number` secara sequential (1, 2, 3, 4...) tanpa memperhatikan urutan di database master `bank_jatim_links`.

**Contoh masalah:**
| Baris Dokumen | SSID | sequence_in_office_type (DB) | line_number (SALAH) |
|---------------|------|------------------------------|---------------------|
| 1 | BANKJTM-KPU-001 | 10 | 1 |
| 2 | BANKJTM-UTAMA-002 | 250 | 2 |

**Hasil yang diinginkan:**
- Baris 1 (SSID dengan sequence 10) → tampil sebagai nomor 10
- Baris 2 (SSID dengan sequence 250) → tampil sebagai nomor 250

---

## Solusi yang Diterapkan

### A. Fix `saveImportedSsids()` di InvoicePeriodController

**File:** `app/Http/Controllers/InvoicePeriodController.php`

**Logika baru:**
```php
// Line number mengikuti sequence_in_office_type dari bank_jatim_links
$link = BankJatimLink::find($linkId);
$lineNumber = $link->sequence_in_office_type ?? ($maxLineNumber + 1);

// Handle collision jika sequence sudah ada
$existingLine = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
    ->where('line_number', $lineNumber)
    ->first();
if ($existingLine) {
    $lineNumber = $maxLineNumber + 1;
}
```

**Hasil:**
| Baris Dokumen | SSID | sequence_in_office_type | line_number (BENAR) |
|---------------|------|------------------------|---------------------|
| 1 | BANKJTM-KPU-001 | 10 | **10** |
| 2 | BANKJTM-UTAMA-002 | 250 | **250** |

---

### B. Tambah Sorting di Step 1 View

**File:** `app/Http/Controllers/InvoicePeriodController.php` - method `step1()`

```php
$invoiceLines = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
    ->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service'])
    ->orderBy('line_number')  // <-- Tambahan
    ->get();
```

---

## Perbaikan Tambahan: Sequence Auto-Renumber

### Masalah: Nomor Sequence Bolong (Gap)

**Sebelum:**
Jika SSID di `bank_jatim_links` dihapus, nomor sequence bisabolong:
```
[1, 2, 3, 4, 5] → hapus index 3 → [1, 2, 4, 5]  (ADA GAP!)
```

### Solusi: Auto Renumber di Model

**File:** `app/Models/BankJatimLink.php`

Tambah method `renumberSequencesForOfficeType()`:
```php
public static function renumberSequencesForOfficeType(int $officeTypeId): void
{
    $links = self::where('office_type_id', $officeTypeId)
        ->orderBy('sequence_in_office_type')
        ->get();

    $sequence = 1;
    foreach ($links as $link) {
        if ($link->sequence_in_office_type != $sequence) {
            $link->update(['sequence_in_office_type' => $sequence]);
        }
        $sequence++;
    }
}
```

Tambah di `boot()` method:
```php
protected static function boot()
{
    parent::boot();

    static::deleting(function (BankJatimLink $link) {
        $officeTypeId = $link->office_type_id;

        // Hapus semua invoice_lines yang terkait
        $link->invoiceLines()->delete();

        // Renumber sequence (ISI NOMOR YANG BOLONG)
        self::renumberSequencesForOfficeType($officeTypeId);
    });

    static::created(function (BankJatimLink $link) {
        // Auto set sequence jika belum diset
        if (empty($link->sequence_in_office_type)) {
            $nextSeq = self::getNextSequenceForOfficeType($link->office_type_id);
            $link->update(['sequence_in_office_type' => $nextSeq]);
        }
    });
}
```

**Hasil:**
```
[1, 2, 3, 4, 5] → hapus index 3 → [1, 2, 3, 4]  (TIDAK ADA GAP)
```

---

## Ringkasan Perubahan

| File | Perubahan |
|------|-----------|
| `InvoicePeriodController.php` | `saveImportedSsids()` - line_number pakai `sequence_in_office_type` |
| `InvoicePeriodController.php` | `step1()` - tambah `orderBy('line_number')` |
| `BankJatimLink.php` | Tambah `renumberSequencesForOfficeType()` static method |
| `BankJatimLink.php` | Tambah `boot()` dengan auto renumber saat delete |

---

## Testing Checklist

- [ ] Import SSID dari dokumen → cek `line_number` di `invoice_lines` sesuai `sequence_in_office_type`
- [ ] Hapus SSID dari `bank_jatim_links` → cek sequence tidak ada gap
- [ ] Tambah SSID baru → cek sequence auto increment
- [ ] Tampilkan Step 1 → cek urutan berdasarkan `line_number`
