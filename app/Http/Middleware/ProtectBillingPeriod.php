<?php

namespace App\Http\Middleware;

use App\Models\ImportBatch;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProtectBillingPeriod
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }
        $period = $request->route('invoicePeriod') ?? $request->route('invoice_period');
        $batch = $request->route('batch');
        if (! $period instanceof InvoicePeriod && $batch instanceof ImportBatch) {
            $period = $batch->invoicePeriod;
        }
        if (! $period instanceof InvoicePeriod) {
            return $next($request);
        }
        $action = $request->route()->getActionMethod();
        if (in_array($action, ['finalize', 'revise'])) {
            return $next($request);
        }
        abort_if($period->fresh()->isLocked(), 423, 'Periode terkunci. Buat revisi untuk mengubah data.');
        $line = $request->route('invoiceLine');
        abort_if($line instanceof InvoiceLine && $line->invoice_period_id !== $period->id, 404);
        DB::beginTransaction();
        try {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::update('UPDATE invoice_periods SET input_version = input_version WHERE id = ?', [$period->id]);
            }
            $current = InvoicePeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            abort_if($current->isLocked(), 423, 'Periode terkunci.');
            $response = $next($request);
            $body = method_exists($response, 'getData') ? $response->getData(true) : null;
            if ($response->getStatusCode() >= 400 || (is_array($body) && ($body['success'] ?? true) === false)) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $response;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
