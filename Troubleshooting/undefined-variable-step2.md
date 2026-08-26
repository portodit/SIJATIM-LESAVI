# Bug Fix: Undefined Variable $bankJatimLinks di step2.blade.php

## Error
```
ErrorException
Undefined variable $bankJatimLinks
resources\views\invoice-periods\step2.blade.php:374
```

## Penyebab

View `step2.blade.php` menggunakan variabel `$bankJatimLinks` langsung (baris 374):

```blade
@foreach($bankJatimLinks->take(50) as $link)
```

Namun, method `step2()` di `InvoicePeriodController.php` tidak meneruskan variabel `$bankJatimLinks` ke view.

Variabel `$bankJatimLinks` hanya ada di dalam array `$stepData`, bukan sebagai variabel terpisah:

```php
$stepData = [
    'typeOrders' => $typeOrders,
    'bandwidthOptions' => $bandwidthOptions,
    'bankJatimLinks' => $bankJatimLinks,  // <-- di dalam stepData
    // ...
];
```

## Solusi

Menambahkan variabel `$bankJatimLinks`, `$typeOrders`, dan `$bandwidthOptions` ke array view() di `InvoicePeriodController.php` (baris 992-1000):

```php
return view('invoice-periods.step2', [
    'title' => 'Sub Tahap 1.2 - Type Order & Bandwidth',
    'subtitle' => $invoicePeriod->period_code,
    'period' => $invoicePeriod,
    'currentStep' => $currentStep,
    'steps' => $steps,
    'stepData' => $stepData,
    'invoiceLinesCount' => $invoiceLinesCount,
    'typeOrders' => $typeOrders,           // <-- ditambahkan
    'bandwidthOptions' => $bandwidthOptions, // <-- ditambahkan
    'bankJatimLinks' => $bankJatimLinks,     // <-- ditambahkan
]);
```

## Kesimpulan

**Penyebab:** View menggunakan variabel langsung (`$bankJatimLinks`) tapi controller mengirimnya di dalam `$stepData` array.

**Solusi:** Pass variabel secara terpisah ke view, atau gunakan `$stepData['bankJatimLinks']` di view.

## Pencegahan

Saat membuat view baru, pastikan:
1. Semua variabel yang digunakan di view sudah di-pass dari controller
2. Atau gunakan array `$stepData` secara konsisten jika itu pattern yang dipilih
3. Cek dengan menjalankan aplikasi dan navigasi ke halaman tersebut untuk verify
