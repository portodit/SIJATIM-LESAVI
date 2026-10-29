<?php

namespace App\Console\Commands;

use App\Models\InvoicePeriod;
use App\Services\Billing\SourceImportService;
use App\Services\Billing\WorkbookSourceReader;
use Illuminate\Console\Command;

class ReconcileBillingSource extends Command
{
    protected $signature = 'billing:reconcile {period : AGU26 or period ID} {file} {--output= : JSON report path}';

    protected $description = 'Inspect a reference workbook against period/master without committing or issuing invoices';

    public function handle(WorkbookSourceReader $reader, SourceImportService $imports): int
    {
        $p = InvoicePeriod::where('period_code', $this->argument('period'))->orWhere('id', $this->argument('period'))->firstOrFail();
        $records = $reader->read($this->argument('file'), 'workbook', $p);
        $planned = array_map(fn ($r) => (string) ($r['normalized']['sid'] ?? ''), array_filter($records, fn ($r) => $r['kind'] === 'inventory'));
        $counts = [];
        $errors = [];
        foreach ($records as $record) {
            $counts[$record['kind']] = ($counts[$record['kind']] ?? 0) + 1;
            [$d,$issues] = $imports->normalize($record['kind'], $record['normalized'], $p, $planned);
            if ($issues) {
                $errors[] = ['sheet' => $record['sheet'], 'row' => $record['row_number'], 'sid' => $d['sid'] ?? null, 'kind' => $record['kind'], 'issues' => $issues];
            }
        }
        $report = ['period' => $p->period_code, 'usage_start' => $p->start_date->toDateString(), 'usage_end' => $p->end_date->toDateString(), 'hash' => hash_file('sha256', $this->argument('file')), 'record_counts' => $counts, 'error_count' => count($errors), 'issues' => $errors];
        if ($this->option('output')) {
            file_put_contents($this->option('output'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $this->line(json_encode(array_diff_key($report, ['issues' => true]), JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
