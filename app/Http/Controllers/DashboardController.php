<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoicePeriod;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $periods = InvoicePeriod::orderByDesc('year')->orderByDesc('month')->get();
        $period = $request->period ? $periods->firstWhere('id', (int) $request->period) : $periods->first();
        abort_if($request->period && ! $period, 404);

        $monthlyStats = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->startOfMonth()->subMonthsNoOverflow($i);
            $monthlyStats[] = [
                'month' => InvoicePeriod::MONTH_CODES[$month->month].$month->format('y'),
                'value' => Invoice::whereHas('invoicePeriod', fn ($q) => $q->where('year', $month->year)->where('month', $month->month))->sum('total_amount'),
            ];
        }

        $data = ['title' => 'Dashboard', 'periods' => $periods, 'period' => $period, 'monthlyStats' => $monthlyStats];
        if ($period) {
            $revisions = $period->revisions()->with('outputs')->latest('version')->get();
            $current = $period->calculationIsCurrent();
            $blocking = collect($period->readinessIssues())->filter(fn ($i) => in_array($i['severity'], ['blocking', 'exception']))->count();
            $hasLines = $period->lines()->exists();
            $state = $period->workflowState($revisions->first());
            [$nextStep, $nextLabel] = match (true) {
                $period->isLocked() => [3, 'Lihat & unduh file'],
                $current && ! $blocking => [3, 'Lanjut: Terbitkan'],
                $current => [2, 'Lengkapi '.$blocking.' kekurangan'],
                $hasLines => [1, 'Lanjutkan isi layanan & downtime'],
                default => [1, 'Mulai isi layanan'],
            };
            $data += [
                'state' => $state, 'current' => $current, 'hasLines' => $hasLines, 'blocking' => $blocking,
                'totals' => $current ? $period->calculation['totals'] : null, 'latestReady' => $revisions->firstWhere('status', 'ready'),
                'nextUrl' => route('invoice-periods.step'.$nextStep, $period), 'nextLabel' => $nextLabel,
                'lineCount' => $period->lines()->count(), 'changeCount' => $period->serviceEvents()->distinct()->count('sid'),
                'downtimeCount' => $period->outages()->where('duration_seconds', '>', 0)->distinct()->count('sid'),
            ];
        }

        return view('dashboard', $data);
    }
}
