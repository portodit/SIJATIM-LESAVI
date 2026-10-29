<?php

namespace App\Jobs;

use App\Models\InvoiceRevision;
use App\Services\Billing\InvoiceDocumentGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GenerateInvoiceDocuments implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [15, 60];

    public function __construct(public int $revisionId) {}

    /**
     * Excel + PDF for ~775 services need more than PHP's default 128 MB; raise the limit here
     * so it does not depend on how the worker was started.
     */
    public static function ensureMemory(): void
    {
        $wanted = max(256, (int) config('billing.worker_memory_mb', 1024)) * 1048576;
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };
        if ($bytes < $wanted) {
            ini_set('memory_limit', (string) intdiv($wanted, 1048576).'M');
        }
    }

    public function handle(InvoiceDocumentGenerator $generator): void
    {
        self::ensureMemory();
        $claim = DB::transaction(function () {
            $r = InvoiceRevision::whereKey($this->revisionId)->lockForUpdate()->firstOrFail();
            if ($r->status === 'ready') {
                return false;
            }
            if ($r->status === 'generating' && $r->generation_started_at?->gt(now()->subMinutes(6))) {
                return false;
            }
            $r->update(['status' => 'generating', 'generation_started_at' => now(), 'generation_error' => null]);

            return true;
        });
        if (! $claim) {
            return;
        }
        try {
            $r = InvoiceRevision::findOrFail($this->revisionId);
            if (hash('sha256', json_encode($r->snapshot, JSON_THROW_ON_ERROR)) !== $r->snapshot_hash) {
                throw new \RuntimeException('Hash snapshot invoice tidak cocok.');
            }
            $generator->generate($r);
        } catch (\Throwable $error) {
            InvoiceRevision::findOrFail($this->revisionId)->update(['status' => 'failed', 'generation_error' => $error->getMessage()]);
            throw $error;
        }
    }
}
