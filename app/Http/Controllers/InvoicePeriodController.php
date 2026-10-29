<?php

namespace App\Http\Controllers;

use App\Models\InvoicePeriod;
use App\Services\Billing\ScheduledChangeApplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoicePeriodController extends Controller
{
    /**
     * Display a listing of invoice periods.
     */
    public function index(Request $request)
    {
        $periods = InvoicePeriod::with(['creator', 'revisions' => fn ($q) => $q->latest('version')])
            ->active()
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->year))
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->paginate(12)
            ->withQueryString();

        return view('invoice-periods.index', [
            'title' => 'Periode Tagihan',
            'periods' => $periods,
            'years' => InvoicePeriod::select('year')->distinct()->orderBy('year', 'desc')->pluck('year'),
        ]);
    }

    /**
     * Show the form for creating a new invoice period.
     */
    public function create()
    {
        $next = now()->startOfMonth();
        while (InvoicePeriod::periodExists($next->month, $next->year)) {
            $next->addMonthNoOverflow();
        }

        return view('invoice-periods.create', [
            'title' => 'Buat Periode Tagihan',
            'defaultMonth' => (int) old('period_month', $next->month),
            'defaultYear' => (int) old('period_year', $next->year),
            'existing' => InvoicePeriod::pluck('period_code')->all(),
        ]);
    }

    /**
     * Store a newly created invoice period and apply changes scheduled for its usage month.
     */
    public function store(Request $request, ScheduledChangeApplier $applier)
    {
        $request->validate([
            'period_month' => 'required|integer|between:1,12',
            'period_year' => 'required|integer|min:2020|max:2100',
            'notes' => 'nullable|string|max:1000',
        ], [
            'period_month.required' => 'Bulan tagihan wajib dipilih',
            'period_year.required' => 'Tahun tagihan wajib diisi',
            'period_year.min' => 'Tahun minimal adalah 2020',
        ]);

        if (InvoicePeriod::periodExists($request->period_month, $request->period_year)) {
            return back()->withInput()->withErrors([
                'period_month' => 'Periode '.InvoicePeriod::MONTH_NAMES[$request->period_month].' '.$request->period_year.' sudah ada.',
            ]);
        }

        $period = DB::transaction(fn () => InvoicePeriod::create([
            'month' => $request->period_month,
            'year' => $request->period_year,
            'status' => InvoicePeriod::STATUS_DRAFT,
            'is_locked' => false,
            'created_by' => Auth::id(),
            'notes' => $request->notes,
        ]));
        $applied = $applier->apply($period);

        return redirect()->route('invoice-periods.step1', $period)
            ->with('success', 'Periode '.$period->period_code.' dibuat.'.($applied['applied'] ? ' '.$applied['applied'].' perubahan terjadwal sudah dimasukkan.' : ''));
    }

    /**
     * Show the form for editing the period notes.
     */
    public function edit(InvoicePeriod $invoicePeriod)
    {
        if (! $invoicePeriod->canEdit()) {
            return redirect()->route('invoice-periods.step1', $invoicePeriod)
                ->with('error', 'Periode yang sudah terkunci tidak dapat diedit.');
        }

        return view('invoice-periods.edit', [
            'title' => 'Edit Periode',
            'period' => $invoicePeriod,
        ]);
    }

    /**
     * Update the period notes. Status is managed by the workflow.
     */
    public function update(Request $request, InvoicePeriod $invoicePeriod)
    {
        if (! $invoicePeriod->canEdit()) {
            return redirect()->route('invoice-periods.step1', $invoicePeriod)
                ->with('error', 'Periode yang sudah terkunci tidak dapat diedit.');
        }

        $request->validate(['notes' => 'nullable|string|max:1000']);
        $invoicePeriod->update(['notes' => $request->notes]);

        return redirect()->route('invoice-periods.index')
            ->with('success', 'Catatan periode '.$invoicePeriod->period_code.' disimpan.');
    }

    /**
     * Remove the specified invoice period from storage.
     */
    public function destroy(InvoicePeriod $invoicePeriod)
    {
        if ($invoicePeriod->status !== InvoicePeriod::STATUS_DRAFT) {
            return redirect()->route('invoice-periods.index')
                ->with('error', 'Hanya periode yang belum diisi (Draft) yang dapat dihapus.');
        }

        $invoicePeriod->delete();

        return redirect()->route('invoice-periods.index')
            ->with('success', 'Periode tagihan dihapus.');
    }
}
