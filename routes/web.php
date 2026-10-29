<?php

use App\Http\Controllers\BillingAdministrationController;
use App\Http\Controllers\BillingWorkflowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoicePeriodController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    'billing.guard',
])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Invoice periods: list/create/edit, then the 3-step operator workflow
    Route::resource('invoice-periods', InvoicePeriodController::class)->except('show');
    Route::get('/invoice-periods/{invoicePeriod}', [BillingWorkflowController::class, 'step1'])->name('invoice-periods.show');
    Route::get('/invoice-periods/{invoicePeriod}/step/1', [BillingWorkflowController::class, 'step1'])->name('invoice-periods.step1');
    Route::get('/invoice-periods/{invoicePeriod}/step/2', [BillingWorkflowController::class, 'step2'])->name('invoice-periods.step2');
    Route::get('/invoice-periods/{invoicePeriod}/step/3', [BillingWorkflowController::class, 'step3'])->name('invoice-periods.step3');
    Route::get('/invoice-periods/{invoicePeriod}/lanjutan', [BillingWorkflowController::class, 'advanced'])->name('invoice-periods.advanced');

    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])->name('invoices.download');

    // Master Harga
    Route::get('/price-masters', [BillingAdministrationController::class, 'index'])->name('price-masters.index');
    Route::post('/billing-masters/ppn', [BillingAdministrationController::class, 'savePpn'])->name('billing-masters.ppn');
    Route::post('/billing-masters/{section}', [BillingAdministrationController::class, 'save'])->name('billing-masters.save');

    // Step 1: services, changes, downtime
    Route::post('/invoice-periods/{invoicePeriod}/metadata', [BillingWorkflowController::class, 'metadata'])->name('billing.metadata');
    Route::post('/invoice-periods/{invoicePeriod}/start-previous', [BillingWorkflowController::class, 'startPrevious'])->name('billing.start-previous');
    Route::post('/invoice-periods/{invoicePeriod}/inventory', [BillingWorkflowController::class, 'selectInventory'])->name('billing.inventory');
    Route::post('/invoice-periods/{invoicePeriod}/inventory-all', [BillingWorkflowController::class, 'inventoryAll'])->name('billing.inventory-all');
    Route::post('/invoice-periods/{invoicePeriod}/new-service', [BillingWorkflowController::class, 'newService'])->name('billing.new-service');
    Route::post('/invoice-periods/{invoicePeriod}/service-events', [BillingWorkflowController::class, 'serviceEvent'])->name('billing.service-events');
    Route::post('/invoice-periods/{invoicePeriod}/service-events/{event}/remove', [BillingWorkflowController::class, 'removeServiceEvent'])->name('billing.service-events.remove');
    Route::post('/invoice-periods/{invoicePeriod}/relocation-address', [BillingWorkflowController::class, 'relocationAddress'])->name('billing.relocation-address');
    Route::post('/invoice-periods/{invoicePeriod}/mark-existing', [BillingWorkflowController::class, 'markExisting'])->name('billing.mark-existing');
    Route::post('/invoice-periods/{invoicePeriod}/scheduled-changes/apply', [BillingWorkflowController::class, 'applyScheduled'])->name('billing.scheduled.apply');
    Route::post('/scheduled-changes/{scheduledChange}/cancel', [BillingWorkflowController::class, 'cancelScheduled'])->name('billing.scheduled.cancel');
    Route::post('/invoice-periods/{invoicePeriod}/downtime', [BillingWorkflowController::class, 'downtime'])->name('billing.downtime');
    Route::post('/invoice-periods/{invoicePeriod}/downtime/choose', [BillingWorkflowController::class, 'chooseDowntime'])->name('billing.downtime.choose');
    Route::post('/invoice-periods/{invoicePeriod}/downtime/confirm-zero', [BillingWorkflowController::class, 'confirmZeroDowntime'])->name('billing.downtime.confirm-zero');

    // File uploads (downtime from step 1, all other kinds from Menu Lanjutan)
    Route::post('/invoice-periods/{invoicePeriod}/imports', [BillingWorkflowController::class, 'stage'])->name('billing.imports');
    Route::get('/imports/{batch}', [BillingWorkflowController::class, 'preview'])->name('imports.show');
    Route::post('/imports/{batch}/rows/{row}/resolve', [BillingWorkflowController::class, 'resolve'])->name('imports.resolve');
    Route::post('/imports/{batch}/conflicts', [BillingWorkflowController::class, 'resolveConflicts'])->name('imports.conflicts');
    Route::post('/imports/{batch}/mapping', [BillingWorkflowController::class, 'historyMapping'])->name('imports.mapping');
    Route::post('/imports/{batch}/revalidate', [BillingWorkflowController::class, 'revalidate'])->name('imports.revalidate');
    Route::post('/imports/{batch}/commit', [BillingWorkflowController::class, 'commit'])->name('imports.commit');
    Route::post('/imports/{batch}/discard', [BillingWorkflowController::class, 'discard'])->name('imports.discard');
    Route::get('/billing-templates/downtime.xlsx', [BillingWorkflowController::class, 'downtimeTemplate'])->name('billing.templates.downtime');
    Route::get('/billing-templates/{kind}', [BillingWorkflowController::class, 'template'])->name('billing.templates');

    // Step 2: check & calculate
    Route::post('/invoice-periods/{invoicePeriod}/calculate', [BillingWorkflowController::class, 'calculate'])->name('billing.calculate');
    Route::post('/invoice-periods/{invoicePeriod}/price-decisions', [BillingWorkflowController::class, 'priceDecisions'])->name('billing.price-decisions');
    Route::get('/invoice-periods/{invoicePeriod}/readiness', [BillingWorkflowController::class, 'readiness'])->name('billing.readiness');

    // Menu Lanjutan (expert tools)
    Route::post('/invoice-periods/{invoicePeriod}/outages/review', [BillingWorkflowController::class, 'reviewOutages'])->name('billing.outages.review');
    Route::post('/invoice-periods/{invoicePeriod}/coverage', [BillingWorkflowController::class, 'coverage'])->name('billing.coverage');
    Route::post('/invoice-periods/{invoicePeriod}/traffic-exception', [BillingWorkflowController::class, 'trafficException'])->name('billing.traffic-exception');
    Route::post('/invoice-periods/{invoicePeriod}/adjustments', [BillingWorkflowController::class, 'adjustment'])->name('billing.adjustments');
    Route::post('/invoice-periods/{invoicePeriod}/adjustments/{adjustment}/remove', [BillingWorkflowController::class, 'removeAdjustment'])->name('billing.adjustments.remove');

    // Step 3: publish
    Route::post('/invoice-periods/{invoicePeriod}/finalize', [BillingWorkflowController::class, 'finalize'])->name('billing.finalize');
    Route::post('/invoice-periods/{invoicePeriod}/revisions', [BillingWorkflowController::class, 'revise'])->name('billing.revise');
    Route::post('/invoice-revisions/{revision}/retry-generation', [BillingWorkflowController::class, 'retry'])->name('billing.retry');
    Route::post('/invoice-revisions/{revision}/summary-image', [BillingWorkflowController::class, 'summaryImage'])->name('billing.summary-image');
    Route::get('/invoice-outputs/{output}/download', [BillingWorkflowController::class, 'download'])->name('billing.download');
});
