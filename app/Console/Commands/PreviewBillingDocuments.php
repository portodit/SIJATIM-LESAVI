<?php

namespace App\Console\Commands;

use App\Jobs\GenerateInvoiceDocuments;
use App\Models\InvoicePeriod;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceDocumentGenerator;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Console\Command;

class PreviewBillingDocuments extends Command
{
    protected $signature = 'billing:preview-documents {period} {--directory= : Absolute directory for draft review documents}';

    protected $description = 'Render a clearly marked draft preview without approval, invoices, or database writes';

    public function handle(InvoiceCalculator $calculator, InvoiceDocumentGenerator $documents): int
    {
        GenerateInvoiceDocuments::ensureMemory();
        $p = InvoicePeriod::where('period_code', $this->argument('period'))->orWhere('id', $this->argument('period'))->firstOrFail();
        $s = $calculator->calculate($p, false);
        $s['metadata']['is_preview'] = true;
        $directory = $this->option('directory') ?: storage_path('app/private/billing/previews/'.$p->period_code);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $documents->excel($s, $directory.'/draft.xlsx', 0);
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => resource_path()]));
        $pdf->loadHtml(view('billing.invoice-pdf', ['snapshot' => $s, 'version' => 0])->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $pdf->getCanvas()->page_text(760, 566, 'DRAFT - {PAGE_NUM}', $pdf->getFontMetrics()->getFont('DejaVu Sans', 'bold'), 10, [0.9, 0, 0.18]);
        file_put_contents($directory.'/draft.pdf', $pdf->output());
        $this->line($directory);

        return self::SUCCESS;
    }
}
