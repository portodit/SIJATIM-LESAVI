<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SnapshotController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoicePeriodController;
use App\Http\Controllers\InvoiceStepController;
use App\Http\Controllers\PriceMasterController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Invoice Periods
    Route::resource('invoice-periods', InvoicePeriodController::class);
    // Specific step routes MUST come before generic route
    Route::get('/invoice-periods/{invoicePeriod}/step/1', [InvoicePeriodController::class, 'step1'])->name('invoice-periods.step1');
    Route::get('/invoice-periods/{invoicePeriod}/step/2', [InvoicePeriodController::class, 'step2'])->name('invoice-periods.step2');
    // Generic step route (must be last) — uses step NUMBER, so /step/1 and /step/2 are caught above
    Route::get('/invoice-periods/{invoicePeriod}/step/{step}', [InvoiceStepController::class, 'show'])->where('step', '[3-9]|1[0-9]')->name('invoice-periods.step');
    Route::get('/invoice-periods/{invoicePeriod}/ssid-data', [InvoicePeriodController::class, 'getSsidData'])->name('invoice-periods.ssid-data');
    Route::get('/invoice-periods/{invoicePeriod}/ssid-format', [InvoicePeriodController::class, 'downloadSsidFormat'])->name('invoice-periods.ssid-format');
    Route::post('/invoice-periods/{invoicePeriod}/ssid-check', [InvoicePeriodController::class, 'checkSsidImport'])->name('invoice-periods.ssid-check');
    Route::post('/invoice-periods/{invoicePeriod}/ssid-save', [InvoicePeriodController::class, 'saveImportedSsids'])->name('invoice-periods.ssid-save');
    Route::post('/invoice-periods/{invoicePeriod}/ssid-store', [InvoicePeriodController::class, 'storeNewSsid'])->name('invoice-periods.ssid-store');
    Route::patch('/invoice-periods/{invoicePeriod}/ssid-update/{invoiceLine}', [InvoicePeriodController::class, 'updateSsid'])->name('invoice-periods.ssid-update');
    Route::get('/invoice-periods/{invoicePeriod}/check-lines', [InvoicePeriodController::class, 'checkLines'])->name('invoice-periods.check-lines');
    Route::get('/invoice-periods/{invoicePeriod}/bandwidth-format', [InvoicePeriodController::class, 'downloadBandwidthFormat'])->name('invoice-periods.bandwidth-format');
    Route::post('/invoice-periods/{invoicePeriod}/bandwidth-import', [InvoicePeriodController::class, 'importBandwidthData'])->name('invoice-periods.bandwidth-import');
    Route::get('/invoice-periods/{invoicePeriod}/step-status/{stepCode}', [InvoicePeriodController::class, 'getStepStatus'])->name('invoice-periods.step-status');
    Route::patch('/invoice-periods/{invoicePeriod}/step-status', [InvoiceStepController::class, 'updateStatus'])->name('invoice-periods.step-status.update');

    // SSID Validation for Step 2
    Route::get('/invoice-periods/{invoicePeriod}/ssid-validation', [InvoicePeriodController::class, 'getSsidValidation'])->name('invoice-periods.ssid-validation');
    Route::post('/invoice-periods/{invoicePeriod}/ssid-add-missing', [InvoicePeriodController::class, 'addMissingSsids'])->name('invoice-periods.ssid-add-missing');
    Route::post('/invoice-periods/{invoicePeriod}/ssid-remove-extra', [InvoicePeriodController::class, 'removeExtraSsids'])->name('invoice-periods.ssid-remove-extra');
    Route::post('/invoice-periods/{invoicePeriod}/ssid-add-to-master', [InvoicePeriodController::class, 'addSsidToMaster'])->name('invoice-periods.ssid-add-to-master');
    Route::post('/invoice-periods/{invoicePeriod}/ssid-remove-from-period', [InvoicePeriodController::class, 'removeSsidFromPeriod'])->name('invoice-periods.ssid-remove-from-period');
    Route::post('/invoice-periods/{invoicePeriod}/ssid-clear', [InvoicePeriodController::class, 'clearSsidData'])->name('invoice-periods.ssid-clear');

    // SSID Add Form (with pre-filled SSIDs from import)
    Route::get('/invoice-periods/{invoicePeriod}/ssid-add-form', [InvoicePeriodController::class, 'ssidAddForm'])->name('invoice-periods.ssid-add-form');

    // Step 2: Type Order & Bandwidth
    Route::get('/invoice-periods/{invoicePeriod}/step2-template', [InvoicePeriodController::class, 'downloadStep2Template'])->name('invoice-periods.step2.download-template');
    Route::post('/invoice-periods/{invoicePeriod}/step2-import', [InvoicePeriodController::class, 'importStep2Data'])->name('invoice-periods.step2.import');
    Route::post('/invoice-periods/{invoicePeriod}/step2-import-preview', [InvoicePeriodController::class, 'previewStep2Import'])->name('invoice-periods.step2.import-preview');
    Route::post('/invoice-periods/{invoicePeriod}/step2-import-commit', [InvoicePeriodController::class, 'commitStep2Import'])->name('invoice-periods.step2.import-commit');
    Route::patch('/invoice-periods/{invoicePeriod}/step2-update/{invoiceLine}', [InvoicePeriodController::class, 'updateStep2Data'])->name('invoice-periods.step2.update');
    Route::post('/invoice-periods/{invoicePeriod}/step2-complete', [InvoicePeriodController::class, 'completeStep2'])->name('invoice-periods.step2.complete');

    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])->name('invoices.download');

    // Price Masters
    Route::get('/price-masters', [PriceMasterController::class, 'index'])->name('price-masters.index');
});
