# Logic Active/Inactive SSID di Bank Jatim Link

**Tanggal:** 2026-07-06  
**Modul:** Master Data Bank Jatim Link - SSID Management

---

## Konsep

SSID/Bank Jatim Link memiliki status `link_status` yang menentukan apakah SSID tersebut:
- **Active** → Bisa diimport ke periode invoice
- **Inactive** → TIDAK bisa diimport ke periode invoice (history tetap ada)

---

## Status SSID

| Status | Deskripsi | Bisa di-Import? |
|--------|----------|----------------|
| `active` | SSID masih aktif/bekerja | ✅ Ya |
| `inactive` | SSID sudah dimatikan (pemutusan, dll) | ❌ Tidak |

---

## Alur Kerja

### 1. SSID Baru / Aktif (Active)
```
CSV Import → BankJatimLink (link_status = 'active')
                                     ↓
                           Bisa dipanggil di Step 1
                           untuk import ke invoice
```

### 2. SSID Dimatikan (Inactive)
```
SSID dihentikan (pemutusan, dll)
             ↓
BankJatimLink: link_status = 'inactive'
             ↓
SSID TETAP ADA di database (history)
SSID TIDAK MUNCUL di pilihan import Step 1
SSID TIDAK BISA diimport ke invoice manapun
```

---

## Implementasi Code

### Model - Scope Active

**File:** `app/Models/BankJatimLink.php`

```php
/**
 * Scope: Hanya link aktif
 */
public function scopeActive($query)
{
    return $query->where('link_status', self::STATUS_ACTIVE);
}
```

### Controller - Filter Saat Ambil Data

**File:** `app/Http/Controllers/InvoicePeriodController.php`

```php
// Di method step1() dan checkSsidImport()
$bankJatimLinks = BankJatimLink::with([...])
    ->active()  // <-- Hanya yang active
    ->orderBy('office_type_id')
    ->orderBy('sequence_in_office_type')
    ->get();
```

### Query Contoh

```php
// Ambil semua SSID aktif untuk dropdown
$activeLinks = BankJatimLink::active()
    ->orderBy('sequence_in_office_type')
    ->get();

// Cek apakah SSID aktif
$link = BankJatimLink::where('ssid', '4703036-16662')->first();
if ($link->isActive()) {
    // Bisa diimport
}
```

---

## Konstanta Status

**File:** `app/Models/BankJatimLink.php`

```php
// Link status
public const STATUS_ACTIVE = 'active';
public const STATUS_INACTIVE = 'inactive';
public const STATUS_TERMINATED = 'terminated';
```

---

## Contoh Penggunaan

### 1. Menandai SSID Sebagai Inactive

```php
use App\Models\BankJatimLink;

// Update satu SSID
BankJatimLink::where('ssid', '4703036-16662')
    ->update(['link_status' => 'inactive']);

// Update massal (misal semua SSID yang sudah putus)
BankJatimLink::where('kategori', 'SAMSAT ONLINE')
    ->where('tanggal_putus', '<', '2026-01-01')
    ->update(['link_status' => 'inactive']);
```

### 2. Filter SSID Inactive Saat Import

```php
// Di checkSsidImport()
$existingLinks = BankJatimLink::whereIn('ssid', $inputSsids)
    ->active()  // Exclude inactive
    ->with(['officeType', 'locationCategory', 'service'])
    ->get();
```

---

## Keuntungan

1. **History Tersimpan** - Data SSID lama tetap ada untuk audit/laporan
2. **Tidak Hilang** - Tidak perlu delete record, cukup ubah status
3. **Filter Otomatis** - SSID inactive tidak akan muncul di pilihan import
4. **Reversible** - Bisa diaktifkan kembali jika diperlukan (misal PSB baru)

---

## Testing Checklist

- [ ] SSID active muncul di dropdown/pilihan import Step 1
- [ ] SSID inactive TIDAK muncul di dropdown/pilihan import Step 1
- [ ] SSID inactive tetap ada di database (tidak dihapus)
- [ ] SSID inactive tidak bisa diimport ke invoice manapun
