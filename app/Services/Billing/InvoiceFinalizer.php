<?php

namespace App\Services\Billing;

use App\Jobs\GenerateInvoiceDocuments;
use App\Models\Invoice;
use App\Models\InvoicePeriod;
use App\Models\InvoiceRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceFinalizer
{
    public function finalize(InvoicePeriod $period, int $version, ?int $user): InvoiceRevision
    {
        $revision = DB::transaction(function () use ($period, $version, $user) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                // Acquire SQLite's writer reservation before reading the approval state.
                DB::update('UPDATE invoice_periods SET input_version = input_version WHERE id = ?', [$period->id]);
            }
            $p = InvoicePeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            abort_if($p->input_version !== $version, 409, 'Input berubah setelah review. Hitung ulang dan tinjau hasil terbaru.');
            $existing = InvoiceRevision::where('invoice_period_id', $p->id)->where('input_version', $version)->first();
            if ($existing) {
                return $existing;
            }
            abort_if($p->isLocked(), 423, 'Periode terkunci.');
            if (! $p->calculationIsCurrent()) {
                throw ValidationException::withMessages(['invoice' => 'Perhitungan belum tersedia atau sudah kedaluwarsa.']);
            }
            $c = $p->calculation;
            if ($p->importBatches()->whereNull('committed_at')->where('status', '!=', 'discarded')->whereHas('rows', fn ($q) => $q->where('status', '!=', 'ignored'))->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Import sumber belum diselesaikan. Tinjau atau tarik sumber sebelum finalisasi.']);
            }
            if (count(array_filter($p->readinessIssues(), fn ($i) => in_array($i['severity'], ['blocking', 'exception'])))) {
                throw ValidationException::withMessages(['invoice' => 'Selesaikan masalah yang menghalangi finalisasi.']);
            }
            if ($c['totals']['grand_total'] === null || ! $c['totals']['service_count']) {
                throw ValidationException::withMessages(['invoice' => 'Tagihan belum lengkap.']);
            }
            $number = trim($c['metadata']['invoice_number'] ?? '');
            if ($number === '') {
                throw ValidationException::withMessages(['invoice_number' => 'Nomor invoice wajib diisi.']);
            }
            $invoice = Invoice::firstOrCreate(['invoice_period_id' => $p->id], ['invoice_number' => $number, 'total_amount' => 0, 'total_locations' => 0]);
            if ($invoice->invoice_number !== $number) {
                throw ValidationException::withMessages(['invoice_number' => 'Revisi mempertahankan nomor invoice yang sama.']);
            }
            $snapshot = $c;
            $snapshot['approved_by'] = $user;
            $snapshot['approved_at'] = now()->toIso8601String();
            $revision = InvoiceRevision::create(['invoice_id' => $invoice->id, 'invoice_period_id' => $p->id, 'version' => ($invoice->revisions()->max('version') ?? 0) + 1, 'input_version' => $version, 'snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'status' => 'approved', 'approved_by' => $user, 'approved_at' => now(), 'reason' => $p->invoice_metadata['revision_reason'] ?? null]);
            $p->update(['is_locked' => true]);
            DB::table('billing_audit_events')->insert(['invoice_period_id' => $p->id, 'action' => 'invoice_approved', 'details' => json_encode(['revision_id' => $revision->id]), 'user_id' => $user, 'created_at' => now(), 'updated_at' => now()]);
            GenerateInvoiceDocuments::dispatch($revision->id)->onConnection('database')->afterCommit();

            return $revision;
        }, 5);

        return $revision;
    }

    public function revise(InvoicePeriod $period, string $reason): void
    {
        DB::transaction(function () use ($period, $reason) {
            $p = InvoicePeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            $latest = $p->revisions()->orderByDesc('version')->first();
            abort_unless($latest && in_array($latest->status, ['ready', 'failed']), 409, 'Tunggu pembuatan dokumen selesai sebelum membuat revisi.');
            abort_unless($p->isLocked(), 409, 'Sudah ada revisi yang sedang dikerjakan.');
            $metadata = $p->invoice_metadata;
            $metadata['revision_reason'] = $reason;
            $p->update(['is_locked' => false, 'status' => 'revision', 'invoice_metadata' => $metadata]);
            $p->markInputsChanged('revision_started', ['reason' => $reason, 'from_revision' => $latest->id]);
        });
    }
}
