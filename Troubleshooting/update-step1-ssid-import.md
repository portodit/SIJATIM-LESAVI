# Update Step 1 - SSID Import & Manage SSID

**Tanggal:** 2026-07-06  
**Modul:** Invoice Period Step 1 - SSID List Import

---

## Perubahan yang Dilakukan

### 1. Line Number Mengikuti Sequence Database Master

**File:** `app/Http/Controllers/InvoicePeriodController.php` - method `saveImportedSsids()`

**Sebelum:**
```php
$lineNumber++;
// line_number jadi 1, 2, 3, 4... (sequential)
```

**Sesudah:**
```php
// Line number mengikuti sequence_in_office_type dari bank_jatim_links
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
| Baris Dokumen | SSID | sequence_in_office_type | line_number |
|---------------|------|------------------------|-------------|
| 1 | BANKJTM-KPU-001 | 10 | **10** |
| 2 | BANKJTM-UTAMA-002 | 250 | **250** |

---

### 2. Sorting di Tampilan Step 1

**File:** `app/Http/Controllers/InvoicePeriodController.php` - method `step1()`

```php
$invoiceLines = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
    ->with(['bankJatimLink.officeType', ...])
    ->orderBy('line_number')  // <-- Tambahan
    ->get();
```

---

### 3. Auto Renumber Sequence di BankJatimLink

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
        $link->invoiceLines()->delete();
        self::renumberSequencesForOfficeType($officeTypeId);
    });

    static::created(function (BankJatimLink $link) {
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

### 4. Fix Format Tanggal di Modal Edit SSID

**File:** `app/Http/Controllers/InvoicePeriodController.php` - method `step1()`

**Masalah:** Tanggal `service_start_date` tidak muncul di form edit karena format Carbon tidak cocok dengan `<input type="date">`

**Solusi:**
```php
$startDate = null;
if ($line->bankJatimLink->service_start_date) {
    $startDate = $line->bankJatimLink->service_start_date instanceof \Carbon\Carbon
        ? $line->bankJatimLink->service_start_date->format('Y-m-d')
        : $line->bankJatimLink->service_start_date;
}
```

**File:** `resources/views/invoice-periods/partials/ssid-list.blade.php` - function `openEditModal()`

```javascript
// Format tanggal untuk HTML date input (YYYY-MM-DD)
let startDate = item.service_start_date || '';
if (startDate && startDate.includes('T')) {
    startDate = startDate.split('T')[0];
}
document.getElementById('edit-start-date').value = startDate;
```

---

### 5. Tombol "Ulang Import" untuk Reset Data SSID + Reset Step

**File:** `resources/views/invoice-periods/partials/ssid-list.blade.php`

Tambah tombol di banner "Step Selesai":
```html
<button onclick="confirmReimport()" class="btn bg-orange-500 text-white hover:bg-orange-600">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
    </svg>
    Ulang Import
</button>
```

Popup konfirmasi yang detail:
```javascript
const message = `PERHATIAN: Reset data SSID akan mempengaruhi:

1. Data SSID periode ini akan dihapus
2. Status "SSID List" dikembalikan ke "Sedang Berlangsung"
3. Semua step setelahnya akan dikunci dan dikembalikan ke "Belum Dimulai"

Step yang akan di-reset:
• SSID List (Step 1)
• Type Order & Bandwidth (Step 2)
• Benchmark Bandwidth (Step 3)
• Data Trafik Jaringan (Step 4)
• Harga Layanan (Step 5)
• Data Gangguan & SLA (Step 6)
• Nilai Restitusi (Step 7)
• Benchmark Harga Addendum (Step 8)
• Finalisasi Invoice (Step 9)
```

**File:** `routes/web.php`

```php
Route::post('/invoice-periods/{invoicePeriod}/ssid-clear', ...)
    ->name('invoice-periods.ssid-clear');
```

**File:** `app/Http/Controllers/InvoicePeriodController.php`

Method `clearSsidData()` yang lengkap:

```php
public function clearSsidData(InvoicePeriod $invoicePeriod)
{
    DB::beginTransaction();
    try {
        // 1. Hapus semua invoice_lines
        $deletedCount = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->delete();

        // 2. Hapus data step lainnya (bandwidth, traffic, price, sla, restitution, dll)
        $this->clearRelatedStepData($invoicePeriod->id);

        // 3. Reset step ssid_list jadi in_progress
        $ssidListStep = InvoiceStep::where('step_code', 'ssid_list')->first();
        if ($ssidListStep) {
            $stepStatus = InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
                ->where('invoice_step_id', $ssidListStep->id)
                ->first();
            if ($stepStatus) {
                $stepStatus->update([
                    'status' => InvoiceStepStatus::STATUS_IN_PROGRESS,
                    'completed_at' => null,
                    'completed_by' => null,
                ]);
            }
        }

        // 4. Reset semua step setelah ssid_list ke not_started
        $this->resetSubsequentSteps($invoicePeriod);

        // 5. Update current_step_code ke ssid_list
        $invoicePeriod->update(['current_step_code' => 'ssid_list']);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => "Data berhasil direset. SSID list dan semua step setelahnya dikembalikan ke status belum selesai.",
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Gagal mereset data: ' . $e->getMessage(),
        ]);
    }
}
```

Method `clearRelatedStepData()`:
```php
private function clearRelatedStepData(int $periodId)
{
    // Hapus: Invoice Bandwidths, Traffic Usages, Prices, SLAs, Restitutions
    // Reset type_order_id di invoice_lines
    // Hapus Invoice Summaries & Outputs
}
```

Method `resetSubsequentSteps()`:
```php
private function resetSubsequentSteps(InvoicePeriod $invoicePeriod)
{
    // Reset semua step setelah ssid_list ke STATUS_NOT_STARTED
    // Reset: started_at, completed_at, completed_by, error_count, warning_count
}
```

---

## Ringkasan Perubahan

| File | Perubahan |
|------|-----------|
| `InvoicePeriodController.php` | `saveImportedSsids()` - line_number pakai `sequence_in_office_type` |
| `InvoicePeriodController.php` | `step1()` - tambah `orderBy('line_number')` + format tanggal |
| `InvoicePeriodController.php` | Tambah `clearSsidData()` method |
| `BankJatimLink.php` | Tambah `renumberSequencesForOfficeType()` + `boot()` |
| `ssid-list.blade.php` | Fix format tanggal di edit + tambah tombol "Ulang Import" |
| `web.php` | Tambah route `ssid-clear` |

---

## Testing Checklist

- [ ] Import SSID dari dokumen → cek `line_number` sesuai `sequence_in_office_type`
- [ ] Tampilan table urut berdasarkan `line_number`
- [ ] Hapus SSID dari `bank_jatim_links` → cek sequence tidak ada gap
- [ ] Tambah SSID baru → cek sequence auto increment
- [ ] Edit SSID → cek tanggal mulai muncul dengan benar
- [ ] Klik "Ulang Import" → cek semua SSID dihapus
