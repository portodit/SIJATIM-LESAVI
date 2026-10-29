<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    public function index()
    {
        $invoices = Invoice::with(['snapshot', 'invoicePeriod', 'revisions.outputs'])
            ->latest()
            ->paginate(15);

        return view('invoices.index', [
            'title' => 'Riwayat Invoice',
            'subtitle' => 'Daftar invoice yang sudah di-generate',
            'invoices' => $invoices,
        ]);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['snapshot', 'generator', 'invoicePeriod', 'revisions.outputs']);

        return view('invoices.show', [
            'title' => 'Detail Invoice',
            'subtitle' => 'Invoice: ' . $invoice->invoice_number,
            'invoice' => $invoice,
        ]);
    }

    public function download(Invoice $invoice)
    {
        $revision = $invoice->revisions()->where('status', 'ready')->latest('version')->first();
        if($revision) { $output = $revision->outputs()->where('output_type', 'excel_invoice')->firstOrFail(); return app(BillingWorkflowController::class)->download($output); }
        if (!$invoice->file_path || !Storage::exists($invoice->file_path)) {
            return back()->with('error', 'File invoice tidak ditemukan!');
        }

        return Storage::download(
            $invoice->file_path,
            'Invoice_' . $invoice->invoice_number . '.xlsx'
        );
    }
}
