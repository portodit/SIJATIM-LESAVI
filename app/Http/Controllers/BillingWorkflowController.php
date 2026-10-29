<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateInvoiceDocuments;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\BillingProfile;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\InvoiceOutput;
use App\Models\InvoicePeriod;
use App\Models\InvoiceRevision;
use App\Models\InvoiceTrafficUsage;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\OutageEvent;
use App\Models\ScheduledServiceChange;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Models\TypeOrder;
use App\Services\Billing\ActiveWindow;
use App\Services\Billing\DowntimeConflicts;
use App\Services\Billing\HistoryWorkbookReader;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceDocumentGenerator;
use App\Services\Billing\InvoiceFinalizer;
use App\Services\Billing\PeriodInventory;
use App\Services\Billing\QueueWorker;
use App\Services\Billing\ScheduledChangeApplier;
use App\Services\Billing\SourceImportService;
use App\Services\Billing\SourceValue;
use App\Services\Billing\WorkbookSourceReader;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BillingWorkflowController extends Controller
{
    public const EVENT_LABELS = ['upgrade' => 'Upgrade', 'downgrade' => 'Downgrade', 'relokasi' => 'Relokasi', 'pemutusan' => 'Cabut', 'pasang_baru' => 'PSB'];

    private const STEP1_FILTERS = ['changed', 'downtime', 'unconfirmed', 'scheduled', 'conflict', 'draft_order', 'relocation'];

    // Sortable columns => direction of the first click (must match the dir of each sort-th in the views).
    private const STEP1_SORTS = ['number' => 'asc', 'sid' => 'asc', 'category' => 'asc', 'branch' => 'asc', 'bandwidth' => 'desc', 'status' => 'asc', 'downtime' => 'desc', 'sla' => 'desc'];

    private const STEP2_SORTS = ['sid' => 'asc', 'category' => 'asc', 'order' => 'asc', 'gross' => 'desc', 'downtime_seconds' => 'desc', 'sla' => 'desc', 'sla_credit' => 'desc', 'net' => 'desc'];

    private const NUMERIC_SORTS = ['number', 'bandwidth', 'downtime', 'downtime_seconds', 'sla', 'gross', 'sla_credit', 'net'];

    /**
     * Step 1: services of the period, their changes and downtime.
     */
    public function step1(InvoicePeriod $invoicePeriod, Request $request)
    {
        $period = $invoicePeriod;
        $search = trim((string) $request->query('search', ''));
        $filter = in_array($request->query('filter'), self::STEP1_FILTERS, true) ? $request->query('filter') : 'all';

        $events = $period->serviceEvents()->orderBy('effective_date')->orderBy('id')->get()->groupBy('sid');
        $outages = DowntimeConflicts::query($period)->get()->groupBy('sid');
        $batchNames = ImportBatch::where('invoice_period_id', $period->id)->pluck('original_filename', 'id')->all();
        $relocationFlagged = $period->relocationFlaggedSids()->all();
        $stale = $this->staleDowntimeSids($period, $events, $outages);
        $scheduled = ScheduledServiceChange::where('status', ScheduledServiceChange::STATUS_SCHEDULED)
            ->whereDate('effective_date', '>', $period->end_date->toDateString())
            ->orderBy('effective_date')->get()->groupBy('sid');
        $sets = [
            'changed' => $events->keys()->all(),
            'downtime' => $outages->filter(fn ($rows) => $rows->contains(fn ($o) => $o->kind === 'interval' || (int) $o->duration_seconds > 0))->keys()->all(),
            'unconfirmed' => $outages->keys()->diff($stale)->values()->all(),
            'scheduled' => $scheduled->keys()->all(),
            'conflict' => $outages->filter(fn ($rows) => $rows->count() > 1)->keys()->all(),
            'draft_order' => $this->draftOrderSids($period, $events),
            'relocation' => $period->pendingRelocationAddresses()->keys()->all(),
        ];
        $base = fn () => $period->lines();
        $counts = ['all' => $base()->count()];
        foreach (self::STEP1_FILTERS as $key) {
            $counts[$key] = $this->filterLines($base(), $key, $sets[$key])->count();
        }

        $query = $period->lines()->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service', 'existingBandwidth', 'typeOrder'])->orderBy('line_number');
        $query->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('ssid_temp', 'like', '%'.$search.'%')->orWhere('address_snapshot', 'like', '%'.$search.'%')->orWhereHas('bankJatimLink', fn ($q) => $q->where('ssid', 'like', '%'.$search.'%')->orWhere('address', 'like', '%'.$search.'%'))));
        if ($filter !== 'all') {
            $this->filterLines($query, $filter, $sets[$filter]);
        }
        $bandwidths = BandwidthOption::orderBy('value_kbps')->get();
        $toRow = fn ($line) => $this->row($line, $period, $events, $outages, $scheduled, $bandwidths, $batchNames, $relocationFlagged);
        $sort = $this->requestedSort($request, self::STEP1_SORTS);
        if ($sort === null || $sort === ['number', 'asc']) {
            $lines = $query->paginate(50)->withQueryString();
            $rows = $lines->getCollection()->map($toRow)->values();
        } else {
            // The sort covers every filtered line, so all rows are built before paging.
            [$key, $dir] = $sort;
            $sorted = $this->sortRows($query->get()->map($toRow), $key, $dir, match ($key) {
                'bandwidth' => fn ($r) => $bandwidths->firstWhere('id', $r['bandwidth_id'])?->value_kbps,
                'status' => fn ($r) => $this->statusLabel($r['status']),
                default => null,
            });
            $page = max(1, (int) $request->input('page', 1));
            $lines = new LengthAwarePaginator($sorted->forPage($page, 50)->values(), $sorted->count(), 50, $page, ['path' => $request->url(), 'query' => $request->query()]);
            $rows = $lines->getCollection();
        }
        $waiting = ScheduledServiceChange::where('status', ScheduledServiceChange::STATUS_SCHEDULED)
            ->whereDate('effective_date', '>=', $period->start_date->toDateString())
            ->whereDate('effective_date', '<=', $period->end_date->toDateString())->get();

        return view('billing.step1', [
            'title' => 'Layanan & Downtime', 'period' => $period, 'step' => 1, 'rows' => $rows, 'lines' => $lines,
            'previousName' => $relocationFlagged ? $period->previousPeriod()?->period_name : null,
            'filter' => $filter, 'search' => $search, 'counts' => $counts, 'waiting' => $waiting,
            'previous' => $this->previous($period), 'masterCount' => BankJatimLink::where('link_status', 'active')->count(),
            'zeroConfirmCount' => $this->zeroDowntimeCandidates($period)->count(),
            'profileId' => $period->billing_profile_id ?? BillingProfile::orderByDesc('version')->value('id'),
            'metadata' => array_merge($period->billingProfile?->settings ?? [], $period->invoice_metadata ?? []),
            'bandwidths' => $bandwidths, 'offices' => OfficeType::orderBy('office_type_name')->get(),
            'categories' => LocationCategory::orderBy('location_category_name')->get(), 'services' => Service::orderBy('service_name')->get(),
        ]);
    }

    /**
     * Step 2: plain-language checklist, calculation and totals.
     */
    public function step2(InvoicePeriod $invoicePeriod, Request $request)
    {
        $period = $invoicePeriod;
        $current = $period->calculationIsCurrent();
        $issues = collect($period->readinessIssues())->reject(fn ($i) => $i['code'] === 'stale_calculation')->map(function ($i) {
            if ($i['code'] === 'sla_coverage' && str_starts_with((string) $i['message'], 'Pilih satu aggregate')) {
                $i['code'] = 'downtime_conflict';
            }

            return $i;
        });
        $calculated = collect($period->calculation['lines'] ?? [])->keyBy('sid');
        $groups = $issues->groupBy('code')->map(function ($items, $code) use ($period, $calculated) {
            $severity = $items->contains(fn ($i) => in_array($i['severity'], ['blocking', 'exception'])) ? 'blocking' : 'note';
            // Issues that explain themselves (price differences, missing tariffs) are shown as a per-SSID table.
            $details = $items->filter(fn ($i) => ! empty($i['detail']))
                ->unique(fn ($i) => $i['sid'].'|'.($i['detail']['explanation'] ?? $i['message']))
                ->map(fn ($i) => [
                    'sid' => $i['sid'], 'line_id' => $i['line_id'] ?? null, 'message' => $i['message'], 'detail' => $i['detail'],
                    'branch' => $calculated->get($i['sid'])['branch'] ?? null, 'address' => $calculated->get($i['sid'])['address'] ?? null,
                ])->values();

            return array_merge(self::issueGuide($code, $period), [
                'code' => $code, 'count' => $items->count(), 'severity' => $severity, 'details' => $details,
                'sids' => $items->pluck('sid')->filter()->unique()->values(), 'messages' => $items->pluck('message')->unique()->take(3)->values(),
            ]);
        })->values();
        $search = trim((string) $request->query('search', ''));
        $rows = collect($current ? $period->calculation['lines'] ?? [] : [])
            ->when($search !== '', fn ($rows) => $rows->filter(fn ($l) => str_contains(mb_strtolower($l['sid'].' '.$l['address'].' '.$l['branch'].' '.$l['category']), mb_strtolower($search))))
            ->values();
        if ($sort = $this->requestedSort($request, self::STEP2_SORTS)) {
            $rows = $this->sortRows($rows, $sort[0], $sort[1], $sort[0] === 'order' ? fn ($l) => $this->statusLabel($l['order']) : null);
        }
        $page = max(1, (int) $request->input('page', 1));
        $lines = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('billing.step2', [
            'title' => 'Periksa & Hitung', 'period' => $period, 'step' => 2, 'current' => $current,
            'totals' => $current ? $period->calculation['totals'] : null, 'blocking' => $groups->where('severity', 'blocking')->values(),
            'notes' => $groups->where('severity', 'note')->values(), 'lines' => $lines, 'search' => $search,
            'hasLines' => $period->lines()->exists(), 'canDecide' => $current && ! $period->isLocked(),
        ]);
    }

    /**
     * Step 3: finalize, follow document generation and download files.
     */
    public function step3(InvoicePeriod $invoicePeriod, QueueWorker $worker)
    {
        $period = $invoicePeriod;
        $this->wakeWorkerFor($period, $worker);
        $current = $period->calculationIsCurrent();
        $blocking = collect($period->readinessIssues())->filter(fn ($i) => in_array($i['severity'], ['blocking', 'exception']));

        return view('billing.step3', [
            'title' => 'Terbitkan Invoice', 'period' => $period, 'step' => 3, 'current' => $current,
            'ready' => $current && $blocking->isEmpty(), 'blockingCount' => $blocking->count(),
            'totals' => $current ? $period->calculation['totals'] : null,
            'metadata' => array_merge($period->billingProfile?->settings ?? [], $period->invoice_metadata ?? []),
            'revisions' => $period->revisions()->with('outputs')->latest('version')->get(),
        ]);
    }

    /**
     * Menu Lanjutan: expert tools for special cases (all import kinds, outage review, coverage, adjustments).
     */
    public function advanced(InvoicePeriod $invoicePeriod, Request $request)
    {
        $period = $invoicePeriod;
        $outages = $period->outages()->when($request->sid, fn ($q) => $q->where('sid', $request->sid))->orderByRaw("CASE WHEN review_status = 'pending' THEN 0 ELSE 1 END")->paginate(40, ['*'], 'outage_page')->withQueryString();
        $result = $period->calculation;

        return view('billing.advanced', [
            'title' => 'Menu Lanjutan', 'period' => $period, 'step' => null,
            'batches' => $period->importBatches()->latest()->get(), 'outages' => $outages,
            'events' => $period->serviceEvents()->orderBy('effective_date')->get(), 'adjustments' => $period->adjustments()->get(),
            'profiles' => BillingProfile::orderByDesc('version')->get(),
            'metadata' => array_merge($period->billingProfile?->settings ?? [], $period->invoice_metadata ?? []),
            'result' => $result, 'issues' => collect($period->readinessIssues()),
            'priceProblems' => $this->priceProblems($period), 'calculationCurrent' => $period->calculationIsCurrent(),
            'activities' => DB::table('billing_audit_events')->where('invoice_period_id', $period->id)->latest('id')->limit(15)->get(),
        ]);
    }

    /**
     * SSIDs with a price problem in the stored calculation (Telkom price differs from the system tariff, or no tariff
     * exists), one row per SSID and problem. Reads the stored result only; nothing is recalculated. Calculations made
     * before the price detail existed carry only a message: the two prices are read from its text and the row is
     * marked stale (no cause known).
     *
     * @return list<array<string, mixed>>
     */
    private function priceProblems(InvoicePeriod $period): array
    {
        $calculation = $period->calculation;
        if (! $calculation) {
            return [];
        }
        $lines = collect($calculation['lines'] ?? [])->keyBy('sid');

        return collect($calculation['issues'] ?? [])
            ->filter(fn ($i) => in_array($i['code'] ?? null, ['tariff_discrepancy', 'tariff'], true) && ! empty($i['sid']))
            ->unique(fn ($i) => $i['code'].'|'.$i['sid'])
            ->map(function ($i) use ($lines) {
                $detail = $i['detail'] ?? null;
                $stale = empty($detail);
                $line = $lines->get($i['sid'], []);
                $reference = $system = $monthly = $difference = null;
                if ($i['code'] === 'tariff_discrepancy' && ! $stale) {
                    [$reference, $system, $monthly, $difference] = [$detail['reference'] ?? null, $detail['system'] ?? null, $detail['monthly'] ?? $detail['system'] ?? null, $detail['difference'] ?? null];
                } elseif ($i['code'] === 'tariff_discrepancy' && preg_match('/Tarif sumber\s+(-?\d+(?:\.\d+)?)\s+berbeda dengan register\s+(-?\d+(?:\.\d+)?)/', (string) ($i['message'] ?? ''), $m)) {
                    $reference = BigDecimal::of($m[1])->toScale(2)->__toString();
                    $system = $monthly = BigDecimal::of($m[2])->toScale(2)->__toString();
                    $difference = BigDecimal::of($m[1])->minus($m[2])->toScale(2)->__toString();
                }
                $source = $detail['source'] ?? [];
                $where = implode(' · ', array_filter([$source['file'] ?? null, $source['sheet'] ?? null, isset($source['row']) ? 'baris '.$source['row'] : null]));

                return [
                    'code' => $i['code'], 'sid' => $i['sid'], 'branch' => $line['branch'] ?? null, 'address' => $line['address'] ?? null,
                    'bandwidth' => $detail['bandwidth'] ?? null, 'category' => ($detail['category'] ?? null) ?: ($line['category'] ?? null),
                    'router_label' => isset($detail['router']) ? InvoiceCalculator::routerLabel($detail['router']) : null,
                    'reference' => $reference, 'system' => $system, 'monthly' => $monthly, 'difference' => $difference,
                    'explanation' => $detail['explanation'] ?? ($i['code'] === 'tariff' ? ($i['message'] ?? null) : null),
                    'source_text' => $where !== '' ? $where : null, 'stale' => $stale,
                ];
            })
            ->values()->all();
    }

    /**
     * Files still waiting for generation (e.g. queued while no worker was running): start a worker.
     */
    private function wakeWorkerFor(InvoicePeriod $period, QueueWorker $worker): void
    {
        if ($period->isLocked() && $period->revisions()->where('status', 'approved')->exists()) {
            $worker->ensureRunning();
        }
    }

    public function metadata(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate([
            'billing_profile_id' => 'required|exists:billing_profiles,id', 'metadata.invoice_number' => 'required|string|max:100',
            'metadata.issue_date' => 'required|date_format:Y-m-d', 'metadata.due_date' => 'required|date_format:Y-m-d|after_or_equal:metadata.issue_date',
            'metadata.customer_name' => 'nullable|string|max:500', 'metadata.supplier_name' => 'nullable|string|max:500',
            'metadata.customer_address' => 'nullable|string|max:2000', 'metadata.supplier_address' => 'nullable|string|max:2000',
            'metadata.npwp' => 'nullable|string|max:100', 'metadata.customer_id' => 'nullable|string|max:100',
            'metadata.account_number' => 'nullable|string|max:100', 'metadata.reconciliation_notes' => 'nullable|string|max:5000',
        ]);
        // Empty fields fall back to the billing profile (Master Harga > Data Pelanggan & Invoice).
        $metadata = array_merge($invoicePeriod->invoice_metadata ?? [], $d['metadata']);
        $metadata = array_filter($metadata, fn ($v) => $v !== null && $v !== '');
        $profile = BillingProfile::find($d['billing_profile_id']);
        $merged = array_merge($profile->settings ?? [], $metadata);
        foreach (['customer_name' => 'Nama pelanggan', 'supplier_name' => 'Nama penyedia', 'account_number' => 'Nomor akun'] as $key => $label) {
            if (empty($merged[$key])) {
                return back()->withErrors(['metadata.'.$key => $label.' belum diisi. Lengkapi di Master Harga › Data Pelanggan & Invoice.']);
            }
        }
        $invoicePeriod->update(['billing_profile_id' => $d['billing_profile_id'], 'invoice_metadata' => $metadata]);
        $invoicePeriod->markInputsChanged('metadata_updated');

        return back()->with('success', 'Info invoice disimpan.');
    }

    private function previous(InvoicePeriod $p): ?InvoiceRevision
    {
        return $p->previousApprovedRevision();
    }

    public function startPrevious(InvoicePeriod $invoicePeriod, ScheduledChangeApplier $applier)
    {
        abort_if($invoicePeriod->lines()->exists(), 422, 'Periode sudah memiliki layanan. Data draft tidak ditimpa.');
        $previous = $this->previous($invoicePeriod);
        abort_unless($previous, 422, 'Belum ada periode sebelumnya yang telah diterbitkan. Ambil layanan dari Master.');
        foreach ($previous->snapshot['lines'] as $row) {
            $s = $row['resolved_service_snapshot'] ?? $row['service_snapshot'];
            if (($s['billing_end_date'] ?? null) && $s['billing_end_date'] < $invoicePeriod->start_date->toDateString()) {
                continue;
            }
            if (collect($row['events'])->contains('event_type', 'pemutusan')) {
                continue;
            }
            $lastRate = collect($row['rates'])->last();
            $s['bandwidth_option_id'] = $lastRate['bandwidth_option_id'] ?? $s['bandwidth_option_id'];
            unset($s['reference_price'], $s['reference_downtime_seconds']);
            $master = BankJatimLink::withTrashed()->where('ssid', $row['sid'])->first();
            InvoiceLine::create(['invoice_period_id' => $invoicePeriod->id, 'bank_jatim_link_id' => $master?->id, 'ssid_temp' => $master ? null : $row['sid'], 'line_number' => InvoiceLine::generateLineNumber($invoicePeriod->id), 'type_order_id' => TypeOrder::where('type_order_code', 'existing')->value('id'), 'existing_bandwidth_option_id' => $s['bandwidth_option_id'], 'address_snapshot' => $s['address'], 'service_snapshot' => $s]);
        }
        InvoiceLine::regroup($invoicePeriod);
        $invoicePeriod->markInputsChanged('previous_period_copied', ['revision_id' => $previous->id]);
        $applied = $applier->apply($invoicePeriod->fresh());

        return back()->with('success', 'Layanan bulan lalu disalin.'.$this->appliedMessage($applied));
    }

    public function selectInventory(Request $request, InvoicePeriod $invoicePeriod, ScheduledChangeApplier $applier)
    {
        $d = $request->validate(['service_ids' => 'required|array|min:1', 'service_ids.*' => 'integer|exists:bank_jatim_links,id']);
        foreach (BankJatimLink::whereIn('id', $d['service_ids'])->get() as $service) {
            PeriodInventory::add($invoicePeriod, $service);
        }
        InvoiceLine::regroup($invoicePeriod);
        $invoicePeriod->markInputsChanged('inventory_selected');
        $applied = $applier->apply($invoicePeriod->fresh());

        return back()->with('success', 'Inventory periode disimpan.'.$this->appliedMessage($applied));
    }

    /**
     * Adds every active master service that is not yet in the period.
     */
    public function inventoryAll(InvoicePeriod $invoicePeriod, ScheduledChangeApplier $applier)
    {
        $before = $invoicePeriod->lines()->count();
        BankJatimLink::where('link_status', 'active')->orderBy('office_type_id')->orderBy('sequence_in_office_type')
            ->each(fn ($service) => PeriodInventory::add($invoicePeriod, $service));
        $added = $invoicePeriod->lines()->count() - $before;
        if ($added) {
            InvoiceLine::regroup($invoicePeriod);
            $invoicePeriod->markInputsChanged('inventory_all_selected', ['added' => $added]);
        }
        $applied = $applier->apply($invoicePeriod->fresh());

        return back()->with('success', $added.' layanan aktif dari Master ditambahkan.'.$this->appliedMessage($applied));
    }

    public function serviceEvent(Request $request, InvoicePeriod $invoicePeriod, ScheduledChangeApplier $applier)
    {
        $latest = $invoicePeriod->start_date->copy()->addMonthsNoOverflow(12)->endOfMonth()->toDateString();
        $relocation = $request->input('event_type') === 'relokasi';
        $d = $request->validate([
            'sid' => 'required|string|max:100',
            'event_type' => ['required', Rule::in(array_keys(self::EVENT_LABELS))],
            'effective_date' => [Rule::requiredIf(! $relocation), 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$invoicePeriod->start_date->toDateString(), 'before_or_equal:'.$latest],
            'bandwidth_option_id' => 'nullable|required_if:event_type,upgrade,downgrade,pasang_baru|exists:bandwidth_options,id',
            'new_address' => 'nullable|string|max:2000',
        ], [
            'effective_date.required' => 'Isi tanggal berlaku.',
            'effective_date.after_or_equal' => 'Tanggal berlaku tidak boleh sebelum bulan pemakaian ini.',
            'effective_date.before_or_equal' => 'Perubahan hanya dapat dijadwalkan sampai 12 bulan ke depan.',
            'bandwidth_option_id.required_if' => 'Pilih bandwidth baru.',
        ]);
        abort_unless(PeriodInventory::findLine($invoicePeriod, $d['sid']), 404, 'Layanan tidak ditemukan pada periode ini.');
        if ($relocation) {
            // Relocation has no effective date: it flags this invoice month (billed as a full month)
            // and the new address must be entered in the next month's invoice.
            abort_if($invoicePeriod->serviceEvents()->where('sid', $d['sid'])->where('event_type', 'relokasi')->exists(), 422, 'SSID ini sudah ditandai relokasi pada tagihan ini.');
            $d['effective_date'] = $invoicePeriod->start_date->toDateString();
        }
        $attributes = $this->eventAttributes($d);
        if ($d['effective_date'] > $invoicePeriod->end_date->toDateString()) {
            return $this->schedule($invoicePeriod, $d['sid'], $d['event_type'], $d['effective_date'], $attributes, $applier);
        }
        ServiceEvent::create(['invoice_period_id' => $invoicePeriod->id, 'sid' => $d['sid'], 'event_type' => $d['event_type'], 'effective_date' => $d['effective_date'], 'attributes' => $attributes, 'source' => ['origin' => 'operator', 'user_id' => auth()->id(), 'recorded_at' => now()->toIso8601String()]]);
        $invoicePeriod->markInputsChanged('service_event_added', ['sid' => $d['sid'], 'event_type' => $d['event_type'], 'effective_date' => $d['effective_date']]);

        if ($relocation) {
            return back()->with('success', $d['sid'].' ditandai Relokasi. Tagihan bulan ini tetap penuh; alamat baru wajib diisi di tagihan '.$invoicePeriod->next_period_name.'.');
        }

        return back()->with('success', self::EVENT_LABELS[$d['event_type']].' untuk '.$d['sid'].' disimpan.');
    }

    /**
     * New address of an SSID that was flagged "Relokasi" in the previous month. Updates this period and the master.
     */
    public function relocationAddress(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate(['sid' => 'required|string|max:100', 'new_address' => 'required|string|min:5|max:2000'], ['new_address.required' => 'Isi alamat baru.', 'new_address.min' => 'Alamat baru terlalu pendek.']);
        abort_unless($invoicePeriod->relocationFlaggedSids()->contains($d['sid']), 422, 'SSID ini tidak ditandai relokasi di tagihan bulan lalu.');
        $line = PeriodInventory::findLine($invoicePeriod, $d['sid']);
        abort_unless($line, 404, 'Layanan tidak ditemukan pada periode ini.');
        $snapshot = $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line);
        $old = $line->old_address_snapshot ?? $snapshot['address'] ?? $line->address_snapshot;
        $snapshot['address'] = $d['new_address'];
        $line->update(['old_address_snapshot' => $old, 'new_address' => $d['new_address'], 'address_snapshot' => $d['new_address'], 'service_snapshot' => $snapshot]);
        $line->bankJatimLink?->update(['address' => $d['new_address']]);
        $invoicePeriod->markInputsChanged('relocation_address_saved', ['sid' => $d['sid'], 'old_address' => $old, 'new_address' => $d['new_address']]);

        return back()->with('success', 'Alamat baru '.$d['sid'].' disimpan, juga di Master.');
    }

    /**
     * PSB: registers a new SSID in the master when needed, adds it to the period and records the installation.
     */
    public function newService(Request $request, InvoicePeriod $invoicePeriod, ScheduledChangeApplier $applier)
    {
        $latest = $invoicePeriod->start_date->copy()->addMonthsNoOverflow(12)->endOfMonth()->toDateString();
        $request->merge(['ssid' => trim((string) $request->input('ssid'))]);
        $master = BankJatimLink::withTrashed()->where('ssid', $request->input('ssid'))->first();
        $d = $request->validate([
            'ssid' => 'required|string|max:100',
            'effective_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$invoicePeriod->start_date->toDateString(), 'before_or_equal:'.$latest],
            'bandwidth_option_id' => 'required|exists:bandwidth_options,id',
            'office_type_id' => [Rule::requiredIf(! $master), 'nullable', 'exists:office_types,id'],
            'location_category_id' => [Rule::requiredIf(! $master), 'nullable', 'exists:location_categories,id'],
            'service_id' => [Rule::requiredIf(! $master), 'nullable', 'exists:services,id'],
            'address' => [Rule::requiredIf(! $master), 'nullable', 'string', 'max:2000'],
            'billable_router_status' => ['nullable', Rule::in(['none', 'managed'])],
        ], ['effective_date.after_or_equal' => 'Tanggal aktif tidak boleh sebelum bulan pemakaian ini.']);
        abort_if($master?->trashed(), 422, 'SSID ini pernah dihapus dari Master. Pulihkan melalui Master Harga.');
        if (! $master) {
            $master = BankJatimLink::create([
                'ssid' => $d['ssid'], 'office_type_id' => $d['office_type_id'], 'location_category_id' => $d['location_category_id'],
                'sequence_in_office_type' => (BankJatimLink::where('office_type_id', $d['office_type_id'])->max('sequence_in_office_type') ?? 0) + 1,
                'service_id' => $d['service_id'], 'current_bandwidth_option_id' => $d['bandwidth_option_id'], 'address' => $d['address'],
                'service_start_date' => $d['effective_date'], 'billing_start_date' => $d['effective_date'], 'link_status' => 'active',
                'physical_router_status' => 'unknown', 'billable_router_status' => $d['billable_router_status'] ?? 'none', 'created_by' => auth()->id(),
            ]);
            $this->audit($invoicePeriod, 'master_service_created', ['id' => $master->id, 'sid' => $master->ssid, 'origin' => 'psb_form']);
        }
        $attributes = ['bandwidth_option_id' => (int) $d['bandwidth_option_id']];
        if ($d['effective_date'] > $invoicePeriod->end_date->toDateString()) {
            return $this->schedule($invoicePeriod, $master->ssid, 'pasang_baru', $d['effective_date'], $attributes, $applier);
        }
        $line = PeriodInventory::findLine($invoicePeriod, $master->ssid) ?? PeriodInventory::add($invoicePeriod, $master);
        abort_unless($line, 422, 'Tanggal billing SSID di Master tidak berada di bulan pemakaian ini. Periksa Master Harga › Daftar SSID.');
        abort_if($invoicePeriod->serviceEvents()->where('sid', $master->ssid)->where('event_type', 'pasang_baru')->exists(), 422, 'PSB untuk SSID ini sudah dicatat pada periode ini.');
        ServiceEvent::create(['invoice_period_id' => $invoicePeriod->id, 'sid' => $master->ssid, 'event_type' => 'pasang_baru', 'effective_date' => $d['effective_date'], 'attributes' => $attributes, 'source' => ['origin' => 'operator', 'user_id' => auth()->id(), 'recorded_at' => now()->toIso8601String()]]);
        InvoiceLine::regroup($invoicePeriod);
        $invoicePeriod->markInputsChanged('service_event_added', ['sid' => $master->ssid, 'event_type' => 'pasang_baru', 'effective_date' => $d['effective_date']]);

        return redirect()->route('invoice-periods.step1', [$invoicePeriod, 'search' => $master->ssid])->with('success', 'PSB '.$master->ssid.' disimpan.');
    }

    private function eventAttributes(array $d): array
    {
        $attributes = [];
        if (in_array($d['event_type'], ['upgrade', 'downgrade', 'relokasi', 'pasang_baru']) && ! empty($d['bandwidth_option_id'])) {
            $attributes['bandwidth_option_id'] = (int) $d['bandwidth_option_id'];
        }
        if ($d['event_type'] === 'relokasi' && ! empty($d['new_address'])) {
            $attributes['new_address'] = $d['new_address'];
        }

        return $attributes;
    }

    /**
     * Records a change dated after this usage month; it is applied when its own month is prepared.
     */
    private function schedule(InvoicePeriod $from, string $sid, string $type, string $date, array $attributes, ScheduledChangeApplier $applier)
    {
        $target = ScheduledServiceChange::invoiceMonthFor($date);
        $targetPeriod = InvoicePeriod::where('month', $target['month'])->where('year', $target['year'])->first();
        abort_if($targetPeriod?->isLocked(), 422, 'Tagihan '.$targetPeriod?->period_name.' sudah terkunci. Buat revisi pada periode tersebut.');
        $change = ScheduledServiceChange::create(['sid' => $sid, 'event_type' => $type, 'effective_date' => $date, 'attributes' => $attributes, 'created_by' => auth()->id()]);
        $this->audit($from, 'service_change_scheduled', ['scheduled_change_id' => $change->id, 'sid' => $sid, 'event_type' => $type, 'effective_date' => $date]);
        if ($targetPeriod) {
            $applier->apply($targetPeriod);
        }

        return back()->with('success', self::EVENT_LABELS[$type].' '.$sid.' dijadwalkan mulai '.Carbon::parse($date)->format('d/m/Y').' dan otomatis masuk ke tagihan '.$change->targetLabel().'.');
    }

    public function cancelScheduled(Request $request, ScheduledServiceChange $scheduledChange)
    {
        $d = $request->validate(['reason' => 'required|string|min:3|max:2000']);
        abort_unless($scheduledChange->status === ScheduledServiceChange::STATUS_SCHEDULED, 422, 'Perubahan ini sudah diterapkan atau dibatalkan.');
        $scheduledChange->update(['status' => ScheduledServiceChange::STATUS_CANCELLED, 'cancel_reason' => $d['reason']]);
        $this->audit(null, 'scheduled_change_cancelled', ['scheduled_change_id' => $scheduledChange->id, 'sid' => $scheduledChange->sid, 'reason' => $d['reason']]);

        return back()->with('success', 'Perubahan terjadwal untuk '.$scheduledChange->sid.' dibatalkan.');
    }

    /**
     * Clears a type order left over from the old draft screens when the service did not actually change.
     */
    public function markExisting(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate(['sid' => 'required|string|max:100']);
        $line = PeriodInventory::findLine($invoicePeriod, $d['sid']);
        abort_unless($line, 404, 'Layanan tidak ditemukan pada periode ini.');
        abort_if($invoicePeriod->serviceEvents()->where('sid', $d['sid'])->exists(), 422, 'SSID ini sudah punya catatan perubahan. Batalkan perubahan itu dulu bila memang tidak ada perubahan.');
        $previous = $line->typeOrder?->type_order_code;
        $line->update(['type_order_id' => TypeOrder::where('type_order_code', 'existing')->value('id')]);
        $invoicePeriod->markInputsChanged('type_order_marked_existing', ['sid' => $d['sid'], 'previous' => $previous]);

        return back()->with('success', $d['sid'].' ditandai Existing.');
    }

    /**
     * Type order code from the old draft screens that still asks for a dated change, or null.
     */
    private function draftOrder(InvoiceLine $line, Collection $lineEvents): ?string
    {
        $code = $line->typeOrder?->type_order_code;

        return $code && ! in_array($code, ['existing', 'multiple_changes'], true) && $lineEvents->isEmpty() ? $code : null;
    }

    private function draftOrderSids(InvoicePeriod $period, Collection $events): array
    {
        return $period->lines()->with(['bankJatimLink', 'typeOrder'])->whereNotNull('type_order_id')
            ->whereHas('typeOrder', fn ($q) => $q->whereNotIn('type_order_code', ['existing', 'multiple_changes']))->get()
            ->map(fn ($line) => $line->billingSid())->reject(fn ($sid) => $events->has($sid))->values()->all();
    }

    public function applyScheduled(InvoicePeriod $invoicePeriod, ScheduledChangeApplier $applier)
    {
        $applied = $applier->apply($invoicePeriod);

        return back()->with('success', trim('Perubahan terjadwal diperiksa.'.$this->appliedMessage($applied)));
    }

    private function appliedMessage(array $applied): string
    {
        $message = $applied['applied'] ? ' '.$applied['applied'].' perubahan terjadwal diterapkan.' : '';
        if ($applied['waiting']) {
            $message .= ' Menunggu SSID: '.implode(', ', $applied['waiting']).'.';
        }

        return $message;
    }

    public function removeServiceEvent(Request $request, InvoicePeriod $invoicePeriod, ServiceEvent $event)
    {
        abort_if($event->invoice_period_id !== $invoicePeriod->id, 404);
        $d = $request->validate(['reason' => 'required|string|min:3|max:2000']);
        $original = $event->toArray();
        if ($scheduledId = $event->source['scheduled_change_id'] ?? null) {
            ScheduledServiceChange::whereKey($scheduledId)->update(['status' => ScheduledServiceChange::STATUS_CANCELLED, 'cancel_reason' => $d['reason']]);
        }
        $event->delete();
        $invoicePeriod->markInputsChanged('service_event_removed', ['event' => $original, 'reason' => $d['reason']]);

        return back()->with('success', 'Perubahan dibatalkan dan dicatat pada riwayat.');
    }

    public function downtime(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate(['sid' => 'required|string|max:100', 'downtime_seconds' => 'required|integer|min:0|max:31622400', 'reason' => 'nullable|string|max:2000', 'replace_existing' => 'nullable|boolean']);
        $line = PeriodInventory::findLine($invoicePeriod, $d['sid']);
        abort_unless($line, 404, 'Layanan tidak ditemukan pada periode ini.');
        $duration = (int) $d['downtime_seconds'];
        $rows = DowntimeConflicts::active($invoicePeriod, $d['sid']);
        $this->abortUnlessChoosable($rows);
        // Re-saving the operator's own single entry just updates it; anything else is a conflict to confirm.
        $reuse = $rows->count() === 1 && $rows->first()->import_batch_id === null ? $rows->first() : null;
        if ($rows->isNotEmpty() && ! $reuse && ! $request->boolean('replace_existing')) {
            $names = ImportBatch::where('invoice_period_id', $invoicePeriod->id)->pluck('original_filename', 'id')->all();
            $current = $rows->map(fn ($o) => DowntimeConflicts::describe($o, $names))->map(fn ($o) => $o['value'].' ('.$o['source'].')')->implode('; ');

            return back()->withInput()->withErrors(['downtime_seconds' => DowntimeConflicts::ERROR_PREFIX.' '.$d['sid'].' sudah punya downtime: '.$current.'. Centang "Ganti" untuk memakai angka baru, atau tutup kotak ini untuk mempertahankan yang lama.']);
        }
        $window = $this->downtimeWindow($invoicePeriod, $line, $d['sid'], $duration);
        if (! $reuse) {
            DowntimeConflicts::supersede($rows, 'Diganti dengan angka manual oleh operator.', ['origin' => 'operator']);
        }
        $this->saveManualDowntime($invoicePeriod, $d['sid'], $duration, $window, $d['reason'] ?? null, $reuse);

        return back()->with('success', 'Downtime '.$d['sid'].' disimpan: '.number_format($duration, 0, ',', '.').' detik.');
    }

    /**
     * Operator picks which of several competing downtime records is correct (or types a new number).
     */
    public function chooseDowntime(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate(['sid' => 'required|string|max:100', 'choice' => 'required|string|max:20', 'downtime_seconds' => 'nullable|required_if:choice,new|integer|min:0|max:31622400'], ['choice.required' => 'Pilih salah satu nilai downtime.', 'downtime_seconds.required_if' => 'Isi angka downtime (detik).']);
        $line = PeriodInventory::findLine($invoicePeriod, $d['sid']);
        abort_unless($line, 404, 'Layanan tidak ditemukan pada periode ini.');
        $rows = DowntimeConflicts::active($invoicePeriod, $d['sid']);
        $this->abortUnlessChoosable($rows);
        if ($d['choice'] === 'new') {
            $duration = (int) $d['downtime_seconds'];
            $window = $this->downtimeWindow($invoicePeriod, $line, $d['sid'], $duration);
            DowntimeConflicts::supersede($rows, 'Diganti dengan angka manual oleh operator.', ['origin' => 'operator']);
            $this->saveManualDowntime($invoicePeriod, $d['sid'], $duration, $window, null, null);
            $message = 'Downtime '.$d['sid'].' memakai angka baru: '.number_format($duration, 0, ',', '.').' detik.';
        } else {
            $keep = $rows->firstWhere('id', (int) $d['choice']);
            abort_unless($keep, 422, 'Pilihan tidak valid atau data sudah berubah. Muat ulang halaman.');
            DowntimeConflicts::supersede($rows->reject(fn ($o) => $o->id === $keep->id), 'Tidak dipakai: operator memilih catatan downtime lain.', ['origin' => 'operator', 'kept_outage_id' => $keep->id]);
            $invoicePeriod->markInputsChanged('downtime_conflict_resolved', ['sid' => $d['sid'], 'kept_outage_id' => $keep->id]);
            $message = 'Downtime '.$d['sid'].' memakai '.number_format((int) $keep->duration_seconds, 0, ',', '.').' detik. Catatan lain tidak dihitung.';
        }

        return back()->with('success', $message);
    }

    private function abortUnlessChoosable(Collection $rows): void
    {
        abort_if($rows->contains(fn ($o) => $o->review_status === 'pending' || $o->kind !== 'aggregate'), 422, 'SSID ini punya downtime yang belum direview atau berupa interval. Selesaikan melalui Menu Lanjutan › Review downtime.');
    }

    /**
     * @return array{start: string, end: string, seconds: int}
     */
    private function downtimeWindow(InvoicePeriod $period, InvoiceLine $line, string $sid, int $duration): array
    {
        $window = ActiveWindow::for($period, $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line), $period->serviceEvents()->where('sid', $sid)->orderBy('effective_date')->get());
        abort_unless($window, 422, 'Layanan tidak memiliki hari aktif yang dapat diberi downtime.');
        abort_if($duration > $window['seconds'], 422, 'Downtime melebihi masa aktif layanan pada bulan ini ('.number_format($window['seconds'], 0, ',', '.').' detik).');

        return $window;
    }

    private function saveManualDowntime(InvoicePeriod $period, string $sid, int $duration, array $window, ?string $reason, ?OutageEvent $reuse): void
    {
        $reason = $reason ?: 'Downtime dimasukkan manual oleh operator.';
        $values = ['kind' => 'aggregate', 'duration_seconds' => $duration, 'coverage_start' => $window['start'].' 00:00:00', 'coverage_end' => Carbon::parse($window['end'])->addDay()->startOfDay(), 'review_status' => 'eligible', 'reason' => $reason, 'reviewed_by' => auth()->id(), 'source' => ['origin' => 'operator_manual', 'user_id' => auth()->id(), 'recorded_at' => now()->toIso8601String()]];
        $reuse ? $reuse->update($values) : $period->outages()->create(array_merge(['sid' => $sid], $values));
        $period->markInputsChanged('downtime_saved', ['sid' => $sid, 'duration_seconds' => $duration, 'reason' => $reason]);
    }

    public function confirmZeroDowntime(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate(['expected_count' => 'required|integer|min:0']);
        $eligible = $this->zeroDowntimeCandidates($invoicePeriod)->all();
        abort_if((int) $d['expected_count'] !== count($eligible), 409, 'Jumlah layanan berubah. Muat ulang halaman dan periksa daftar sebelum konfirmasi.');
        foreach ($eligible as $row) {
            $invoicePeriod->outages()->create(['sid' => $row['sid'], 'kind' => 'aggregate', 'duration_seconds' => 0, 'coverage_start' => $row['start'].' 00:00:00', 'coverage_end' => Carbon::parse($row['end'])->addDay()->startOfDay(), 'review_status' => 'eligible', 'reason' => 'Operator mengonfirmasi tidak ada downtime untuk jendela billable.', 'reviewed_by' => auth()->id(), 'source' => ['origin' => 'operator_bulk_zero', 'user_id' => auth()->id(), 'recorded_at' => now()->toIso8601String()]]);
        }
        if ($eligible) {
            $invoicePeriod->markInputsChanged('downtime_zero_confirmed', ['sids' => array_column($eligible, 'sid')]);
        }

        return back()->with('success', count($eligible).' SSID ditandai tanpa downtime. Downtime yang sudah diisi tidak diubah.');
    }

    private function zeroDowntimeCandidates(InvoicePeriod $period): Collection
    {
        $events = $period->serviceEvents()->orderBy('effective_date')->get()->groupBy('sid');
        $existing = DowntimeConflicts::query($period)->pluck('sid')->flip();

        return $period->lines()->with('bankJatimLink')->get()->map(function ($line) use ($period, $events, $existing) {
            $sid = $line->billingSid();
            if (! $sid || $existing->has($sid)) {
                return null;
            }
            $window = ActiveWindow::for($period, $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line), $events[$sid] ?? []);

            return $window ? ['sid' => $sid, 'start' => $window['start'], 'end' => $window['end']] : null;
        })->filter()->values();
    }

    public function stage(Request $request, InvoicePeriod $invoicePeriod, SourceImportService $imports)
    {
        $d = $request->validate(['file' => 'required|file|extensions:csv,xlsx|max:25600', 'kind' => ['required', Rule::in([...WorkbookSourceReader::KINDS, 'history'])]]);
        try {
            $batch = $imports->stage($invoicePeriod, $d['file'], $d['kind']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['batch_id' => $batch->id, 'preview_url' => route('imports.show', $batch)], 201) : redirect()->route('imports.show', $batch);
    }

    public function preview(Request $request, ImportBatch $batch, SourceImportService $imports)
    {
        $rows = $batch->rows()->when($request->status, fn ($q) => $q->where('status', $request->status))->when($request->sid, fn ($q) => $q->where('sid', $request->sid))->orderByRaw("CASE WHEN status = 'error' THEN 0 ELSE 1 END")->orderBy('id')->paginate(25)->withQueryString();
        if ($request->expectsJson()) {
            return response()->json(['batch' => $batch, 'rows' => $rows]);
        }

        $period = $batch->invoicePeriod;
        $conflicts = collect();
        if (! $batch->committed_at && $batch->status !== 'discarded' && ! $period->isLocked()) {
            $names = ImportBatch::where('invoice_period_id', $period->id)->pluck('original_filename', 'id')->all();
            $conflicts = $batch->rows()->whereIn('kind', ['outages', 'history'])->whereIn('status', ['error', 'valid'])->orderBy('id')->get()
                ->filter(fn ($r) => ($r->status === 'valid' && ($r->normalized['conflict_choice'] ?? null) === 'use_file') || collect($r->errors)->contains(fn ($e) => str_starts_with((string) $e, DowntimeConflicts::ERROR_PREFIX)))
                ->map(fn ($r) => ['row' => $r, 'choice' => $r->normalized['conflict_choice'] ?? null, 'file_seconds' => $r->normalized['duration_seconds'] ?? $r->normalized['downtime_seconds'] ?? null, 'existing' => DowntimeConflicts::active($period, (string) $r->sid)->map(fn ($o) => DowntimeConflicts::describe($o, $names))->all()])
                ->values();
        }

        // A past-month workbook also shows its sheet/column choice and what applying it changes in the period.
        $history = $batch->import_type === 'history'
            ? ['analysis' => $batch->metadata['analysis'] ?? [], 'summary' => $imports->historySummary($batch)]
            : null;

        return view('billing.import', ['title' => 'Periksa File Upload', 'batch' => $batch, 'period' => $period, 'rows' => $rows, 'conflicts' => $conflicts, 'choices' => BillingAdministrationController::choices(), 'backUrl' => $this->importBackUrl($batch), 'history' => $history]);
    }

    /**
     * Past-month workbook: the operator picks another sheet or other columns and the rows are read again.
     */
    public function historyMapping(Request $request, ImportBatch $batch, SourceImportService $imports)
    {
        abort_unless($batch->import_type === 'history', 404);
        $sheets = array_column($batch->metadata['analysis']['sheets'] ?? [], 'name');
        $d = $request->validate([
            'sheet' => ['required', 'string', 'max:255', Rule::in($sheets)],
            'mapping' => ['nullable', 'array:'.implode(',', array_keys(HistoryWorkbookReader::FIELDS))],
            'mapping.*' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,3}$/'],
        ], ['sheet.in' => 'Sheet tidak ditemukan di file ini.', 'sheet.required' => 'Pilih sheet.', 'mapping.*.regex' => 'Kolom tidak dikenal.']);
        $mapping = array_map(fn ($letter) => $letter === null ? null : strtoupper($letter), $d['mapping'] ?? []);
        try {
            $imports->remapHistory($batch, $d['sheet'], $mapping);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('imports.show', $batch)->withErrors(['sheet' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $batch)->with('success', 'Kolom diperbarui. '.number_format($batch->fresh()->total_rows, 0, ',', '.').' baris dibaca ulang dari sheet '.$d['sheet'].'.');
    }

    private function importBackUrl(ImportBatch $batch): string
    {
        return in_array($batch->import_type, ['outages', 'events', 'history'])
            ? route('invoice-periods.step1', $batch->invoice_period_id)
            : route('invoice-periods.advanced', $batch->invoice_period_id);
    }

    public function resolve(Request $request, ImportBatch $batch, ImportRow $row, SourceImportService $imports)
    {
        abort_if($row->import_batch_id !== $batch->id, 404);
        $d = $request->validate(['values' => 'nullable|array', 'reason' => 'required|string|min:3|max:2000', 'ignore' => 'nullable|boolean']);
        $imports->resolve($row, $d['values'] ?? [], $request->boolean('ignore'), $d['reason']);

        return back()->with('success', 'Keputusan baris dicatat.');
    }

    /**
     * Bulk decision for downtime rows that collide with downtime already recorded for the same SSID.
     */
    public function resolveConflicts(Request $request, ImportBatch $batch, SourceImportService $imports)
    {
        abort_if($batch->committed_at || $batch->status === 'discarded', 422, 'File ini sudah diterapkan atau ditarik.');
        $d = $request->validate(['choices' => 'required|array|min:1', 'choices.*' => ['required', Rule::in(['use_file', 'keep_existing'])]], ['choices.required' => 'Pilih nilai yang benar untuk setidaknya satu SSID.']);
        $rows = $batch->rows()->whereIn('kind', ['outages', 'history'])->whereIn('id', array_keys($d['choices']))->get();
        abort_if($rows->count() !== count($d['choices']), 404);
        // Share one batch and period: each row loading its own period (with its ~2.6 MB calculation) ran out of memory.
        $batch->loadMissing('invoicePeriod');
        $rows->each->setRelation('batch', $batch);
        DB::transaction(function () use ($rows, $d, $imports) {
            foreach ($rows as $row) {
                if ($d['choices'][$row->id] === 'use_file') {
                    $imports->resolve($row, ['conflict_choice' => 'use_file'], false, 'Dipilih operator: pakai nilai dari file.');
                } else {
                    $imports->resolve($row, [], true, 'Dipilih operator: pertahankan downtime yang sudah ada.');
                }
            }
        });
        $use = count(array_filter($d['choices'], fn ($c) => $c === 'use_file'));

        return back()->with('success', $use.' SSID memakai nilai dari file, '.(count($d['choices']) - $use).' SSID mempertahankan nilai yang sudah ada.');
    }

    public function revalidate(ImportBatch $batch, SourceImportService $imports)
    {
        $imports->revalidate($batch);

        return back()->with('success', 'File divalidasi ulang dengan data terbaru.');
    }

    public function commit(Request $request, ImportBatch $batch, SourceImportService $imports)
    {
        $result = $imports->commit($batch);

        return $request->expectsJson() ? response()->json($result) : redirect($this->importBackUrl($batch))->with('success', 'Data dari file '.$batch->original_filename.' diterapkan.');
    }

    public function discard(Request $request, ImportBatch $batch, SourceImportService $imports)
    {
        $d = $request->validate(['reason' => 'required|string|min:3|max:2000']);
        $p = $batch->invoicePeriod;
        if ($batch->committed_at) {
            abort_if($batch->rows()->where('status', 'valid')->whereIn('kind', ['inventory', 'tariffs', 'history'])->exists(), 422, 'Daftar layanan/tarif dari file ini sudah diterapkan; koreksi melalui Master atau upload file yang sudah diperbaiki.');
            $imports->withdrawEvents($batch);
            foreach ([OutageEvent::class, InvoiceAdjustment::class, InvoiceTrafficUsage::class] as $model) {
                $model::where('import_batch_id', $batch->id)->delete();
            }
            DowntimeConflicts::restoreSupersededBy($p, $batch->id);
            $declarations = array_values(array_filter($p->sla_coverage['declarations'] ?? [], fn ($d) => (int) $d['batch_id'] !== $batch->id));
            $sids = [];
            foreach ($declarations as $declaration) {
                $sids = array_merge($sids, $declaration['sids']);
            }
            $p->update(['sla_coverage' => ['sids' => array_values(array_unique($sids)), 'declarations' => $declarations]]);
            $p->markInputsChanged('source_withdrawn', ['batch_id' => $batch->id, 'reason' => $d['reason']]);
        }
        $batch->rows()->update(['status' => 'ignored', 'resolution_reason' => $d['reason'], 'resolved_by' => auth()->id()]);
        $batch->update(['status' => 'discarded', 'metadata' => array_merge($batch->metadata ?? [], ['withdrawal_reason' => $d['reason']])]);

        return back()->with('success', 'File ditarik dengan alasan tercatat.');
    }

    public function template(string $kind)
    {
        abort_unless(isset(WorkbookSourceReader::TEMPLATES[$kind]), 404);

        return response()->streamDownload(function () use ($kind) {
            $h = fopen('php://output', 'w');
            fputcsv($h, WorkbookSourceReader::TEMPLATES[$kind], ',', '"', '');
            fclose($h);
        }, 'SIJATIM_'.$kind.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downtimeTemplate()
    {
        return response()->streamDownload(function () {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Downtime');
            $sheet->setCellValue('A1', 'SSID');
            $sheet->setCellValue('B1', 'Downtime (detik)');
            $sheet->getStyle('A1:B1')->getFont()->setBold(true);
            $sheet->getColumnDimension('A')->setWidth(24);
            $sheet->getColumnDimension('B')->setWidth(22);
            $sheet->getStyle('A2:A5001')->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle('B2:B5001')->getNumberFormat()->setFormatCode('0');
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:B1');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, 'Template_Downtime_SIJATIM.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function calculate(Request $request, InvoicePeriod $invoicePeriod, InvoiceCalculator $calculator)
    {
        $d = $request->validate(['input_version' => 'required|integer']);
        abort_if((int) $d['input_version'] !== $invoicePeriod->fresh()->input_version, 409, 'Data berubah sejak halaman dibuka. Muat ulang halaman.');
        $result = $calculator->calculate($invoicePeriod);

        return $request->expectsJson() ? response()->json($result) : redirect()->route('invoice-periods.step2', $invoicePeriod)->with('success', 'Invoice selesai dihitung.');
    }

    /**
     * Settles price differences with the Telkom workbook one SSID at a time, then recalculates.
     * Amounts and bandwidths come from the stored calculation, never from the request.
     */
    public function priceDecisions(Request $request, InvoicePeriod $invoicePeriod, InvoiceCalculator $calculator)
    {
        $d = $request->validate(['decisions' => 'required|array|min:1', 'note' => 'nullable|string|max:500'], [
            'decisions.required' => 'Pilih harga untuk minimal satu SSID.', 'decisions.array' => 'Pilih harga untuk minimal satu SSID.',
            'decisions.min' => 'Pilih harga untuk minimal satu SSID.', 'note.max' => 'Catatan terlalu panjang (maksimal 500 huruf).',
        ]);
        $decisions = [];
        foreach ($d['decisions'] as $sid => $choice) {
            abort_unless(is_string($choice) && in_array($choice, ['source', 'system', 'bandwidth'], true), 422, 'Pilihan harga untuk SSID '.$sid.' tidak dikenal.');
            $decisions[(string) $sid] = $choice;
        }
        $note = trim((string) ($d['note'] ?? ''));
        $money = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');

        DB::transaction(function () use ($invoicePeriod, $decisions, $note, $money) {
            $period = InvoicePeriod::whereKey($invoicePeriod->id)->lockForUpdate()->firstOrFail();
            abort_if($period->isLocked(), 423, 'Periode sudah terkunci. Buat revisi untuk mengubah input.');
            abort_unless($period->calculationIsCurrent(), 409, 'Data berubah sejak halaman dibuka. Hitung ulang dulu.');
            $issues = collect($period->calculation['issues'] ?? [])->where('code', 'tariff_discrepancy')->filter(fn ($i) => ! empty($i['detail']))->keyBy('sid');
            foreach ($decisions as $sid => $choice) {
                $issue = $issues->get($sid);
                abort_if(! $issue, 422, 'SSID '.$sid.' tidak punya selisih harga pada perhitungan terbaru. Muat ulang halaman.');
                $detail = $issue['detail'];
                if ($choice === 'bandwidth') {
                    $target = self::priceTargetBandwidth($detail);
                    $line = $period->lines()->find($issue['line_id'] ?? 0);
                    abort_if(! $target || ! $line || ! BandwidthOption::whereKey($target['id'])->exists(), 422, 'Pilih harga Telkom atau harga sistem untuk SSID '.$sid.'.');
                    // Only this period's line changes; the master (bank_jatim_links) stays as it is.
                    $snapshot = $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line);
                    $snapshot['bandwidth_option_id'] = $target['id'];
                    $line->update(['service_snapshot' => $snapshot, 'existing_bandwidth_option_id' => $target['id']]);

                    continue;
                }
                $amount = $choice === 'source' ? ($detail['reference'] ?? null) : ($detail['monthly'] ?? null);
                abort_if($amount === null, 422, 'Harga untuk SSID '.$sid.' tidak bisa ditentukan. Hitung ulang dulu.');
                $tariff = $money($detail['monthly'] ?? $detail['system'] ?? 0);
                $source = $detail['source'] ?? [];
                $row = isset($source['row']) ? 'baris '.$source['row'] : null;
                $where = array_filter([isset($source['file']) ? 'file '.$source['file'] : null, $row]);
                $reason = $choice === 'source'
                    ? 'Disamakan dengan harga workbook Telkom '.$money($detail['reference']).($where ? ' ('.implode(', ', $where).')' : '').'; tarif sistem '.$tariff.'.'
                    : 'Tetap memakai tarif sistem '.$tariff.'; harga Telkom '.$money($detail['reference'] ?? 0).' tidak dipakai.';
                InvoiceAdjustment::create([
                    'invoice_period_id' => $period->id, 'sid' => $sid, 'kind' => 'tariff_override', 'amount' => $amount,
                    'reason' => $reason.($note !== '' ? ' Catatan: '.$note : ''),
                    'reference' => implode(' · ', array_filter([$source['file'] ?? null, $source['sheet'] ?? null, $row])) ?: 'Workbook Telkom',
                    'attributes' => ['decision' => $choice, 'detail' => $detail], 'created_by' => auth()->id(),
                ]);
            }
            $period->markInputsChanged('price_decisions', ['decisions' => $decisions, 'note' => $note !== '' ? $note : null]);
        });
        $calculator->calculate($invoicePeriod->fresh());

        return redirect()->route('invoice-periods.step2', $invoicePeriod)->with('success', count($decisions).' SSID diselesaikan dan invoice dihitung ulang. Override harga bisa dihapus di Menu Lanjutan bila keliru.');
    }

    /**
     * The bandwidth that would make the system price equal the Telkom price for a price difference, if there
     * is exactly one: today's master bandwidth when it matches, otherwise the only other bandwidth with
     * that price at the same router setting. Null when there is none or several.
     *
     * @return array{id: int, name: string, master: bool}|null
     */
    public static function priceTargetBandwidth(array $detail): ?array
    {
        $router = $detail['router'] ?? 'none';
        // A bandwidth equal to the one the system already uses would change nothing.
        $candidates = collect($detail['matches'] ?? [])->filter(fn ($m) => ($m['router'] ?? null) === $router && ! empty($m['bandwidth_option_id']) && ($m['bandwidth'] ?? null) !== ($detail['bandwidth'] ?? null));
        $master = ! empty($detail['master_matches']) && ! empty($detail['master_bandwidth_option_id']) ? (int) $detail['master_bandwidth_option_id'] : null;
        $id = $master !== null && $candidates->contains(fn ($m) => (int) $m['bandwidth_option_id'] === $master) ? $master : null;
        if ($id === null) {
            $ids = $candidates->pluck('bandwidth_option_id')->map(fn ($v) => (int) $v)->unique()->values();
            if ($ids->count() !== 1) {
                return null;
            }
            $id = $ids->first();
        }
        $match = $candidates->first(fn ($m) => (int) $m['bandwidth_option_id'] === $id);

        return ['id' => $id, 'name' => $match['bandwidth'], 'master' => $id === $master];
    }

    public function readiness(InvoicePeriod $invoicePeriod, QueueWorker $worker)
    {
        $this->wakeWorkerFor($invoicePeriod, $worker);
        $issues = $invoicePeriod->readinessIssues();

        return response()->json(['input_version' => $invoicePeriod->input_version, 'fresh' => $invoicePeriod->calculationIsCurrent(), 'ready' => ! collect($issues)->contains(fn ($i) => in_array($i['severity'], ['blocking', 'exception'])), 'locked' => $invoicePeriod->isLocked(), 'totals' => $invoicePeriod->calculation['totals'] ?? null, 'issues' => $issues, 'generation' => $invoicePeriod->revisions()->latest('version')->first(['id', 'version', 'status', 'generation_error'])]);
    }

    public function reviewOutages(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate(['outage_ids' => 'required|array|min:1', 'outage_ids.*' => 'required|integer', 'decision' => ['required', Rule::in(['eligible', 'excluded', 'pending'])], 'reason' => 'required|string|min:3|max:2000']);
        $rows = $invoicePeriod->outages()->whereIn('id', $d['outage_ids'])->get();
        abort_if($rows->count() !== count(array_unique($d['outage_ids'])), 404);
        abort_if($d['decision'] === 'eligible' && $rows->contains('kind', 'evidence'), 422, 'Tiket merupakan bukti pendukung. Import interval/aggregate untuk perhitungan SLA.');
        foreach ($rows as $row) {
            $row->update(['review_status' => $d['decision'], 'reason' => $d['reason'], 'reviewed_by' => auth()->id()]);
        }
        $invoicePeriod->markInputsChanged('outage_review', $d);

        return back()->with('success', 'Keputusan downtime disimpan.');
    }

    public function coverage(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate(['batch_id' => 'required|integer', 'reason' => 'required|string|min:5|max:2000', 'sids' => 'nullable|string|max:20000', 'all_services' => 'nullable|boolean']);
        $batch = $invoicePeriod->importBatches()->whereNotNull('committed_at')->where('status', 'completed')->findOrFail($d['batch_id']);
        abort_unless($batch->rows()->where('status', 'valid')->whereIn('kind', ['outages', 'incidents'])->exists(), 422, 'Pilih sumber SLA yang telah diterapkan.');
        $available = $invoicePeriod->lines()->with('bankJatimLink')->get()->map(fn ($l) => $l->billingSid())->all();
        $sids = $request->boolean('all_services') ? $available : array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $d['sids'] ?? ''))));
        abort_if(! $sids || array_diff($sids, $available), 422, 'SID deklarasi cakupan harus termasuk periode ini.');
        $existing = $invoicePeriod->sla_coverage ?? [];
        $invoicePeriod->update(['sla_coverage' => ['sids' => array_values(array_unique(array_merge($existing['sids'] ?? [], $sids))), 'declarations' => array_merge($existing['declarations'] ?? [], [['batch_id' => $batch->id, 'hash' => $batch->file_hash, 'sids' => $sids, 'reason' => $d['reason'], 'operator' => auth()->id(), 'at' => now()->toIso8601String()]])]]);
        $invoicePeriod->markInputsChanged('coverage_declared', ['batch_id' => $batch->id, 'reason' => $d['reason']]);

        return back()->with('success', 'Cakupan sumber dikonfirmasi. SID tanpa gangguan pada cakupan ini dapat memperoleh downtime nol.');
    }

    public function trafficException(Request $request, InvoicePeriod $invoicePeriod)
    {
        $d = $request->validate(['sid' => 'nullable|string|max:100', 'reason' => 'required|string|min:5|max:2000', 'all_missing' => 'nullable|boolean']);
        $missing = collect($invoicePeriod->calculation['issues'] ?? [])->whereIn('code', ['traffic_missing', 'traffic_coverage'])->pluck('line_id');
        $lines = $invoicePeriod->lines()->when($request->boolean('all_missing'), fn ($q) => $q->whereIn('id', $missing), fn ($q) => $q->whereHas('bankJatimLink', fn ($q) => $q->where('ssid', $d['sid'] ?? '')))->get();
        abort_if($lines->isEmpty(), 422, 'Pilih SID yang memiliki trafik belum tersedia dari perhitungan terbaru.');
        foreach ($lines as $line) {
            $line->update(['traffic_exception_reason' => $d['reason']]);
        }
        $invoicePeriod->markInputsChanged('traffic_exception', ['line_ids' => $lines->pluck('id')->all(), 'reason' => $d['reason']]);

        return back()->with('success', 'Pengecualian dicatat; average tetap belum tersedia.');
    }

    public function adjustment(Request $request, InvoicePeriod $invoicePeriod, SourceImportService $imports)
    {
        $d = $request->validate(['sid' => 'required|string|max:100', 'kind' => ['required', Rule::in(['manual_credit', 'tariff_override'])], 'amount' => 'required|string|max:50', 'reason' => 'required|string|min:3|max:2000', 'reference' => 'required|string|max:2000', 'installation_price' => 'nullable|string', 'relocation_price' => 'nullable|string']);
        [$data, $errors] = $imports->normalize('adjustments', $d, $invoicePeriod);
        if ($errors) {
            return back()->withErrors(['adjustment' => implode(' ', $errors)]);
        }
        foreach (['installation_price', 'relocation_price'] as $fee) {
            if (isset($d[$fee])) {
                $data[$fee] = SourceValue::number($d[$fee]);
            }
        }
        InvoiceAdjustment::create(['invoice_period_id' => $invoicePeriod->id, 'sid' => $data['sid'], 'kind' => $data['kind'], 'amount' => $data['amount'], 'reason' => $data['reason'], 'reference' => $data['reference'], 'attributes' => $data, 'created_by' => auth()->id()]);
        $invoicePeriod->markInputsChanged('adjustment_added', ['sid' => $data['sid']]);

        return back()->with('success', 'Penyesuaian disimpan.');
    }

    public function removeAdjustment(Request $request, InvoicePeriod $invoicePeriod, InvoiceAdjustment $adjustment)
    {
        abort_if($adjustment->invoice_period_id !== $invoicePeriod->id, 404);
        $d = $request->validate(['reason' => 'required|string|min:3|max:2000']);
        $original = $adjustment->toArray();
        $adjustment->delete();
        $invoicePeriod->markInputsChanged('adjustment_removed', ['original' => $original, 'reason' => $d['reason']]);

        return back()->with('success', 'Penyesuaian ditarik.');
    }

    public function finalize(Request $request, InvoicePeriod $invoicePeriod, InvoiceFinalizer $finalizer, QueueWorker $worker)
    {
        $d = $request->validate(['input_version' => 'required|integer']);
        $revision = $finalizer->finalize($invoicePeriod, $d['input_version'], auth()->id());
        $worker->ensureRunning();

        return $request->expectsJson() ? response()->json(['revision_id' => $revision->id, 'status' => $revision->status], 202) : redirect()->route('invoice-periods.step3', $invoicePeriod)->with('success', 'Invoice dikunci. File Excel dan PDF sedang dibuat.');
    }

    public function revise(Request $request, InvoicePeriod $invoicePeriod, InvoiceFinalizer $finalizer)
    {
        $d = $request->validate(['reason' => 'required|string|min:5|max:2000']);
        $finalizer->revise($invoicePeriod, $d['reason']);

        return redirect()->route('invoice-periods.step1', $invoicePeriod)->with('success', 'Revisi dibuka. Data dapat diubah lagi; file yang sudah terbit tetap tersimpan.');
    }

    public function retry(Request $request, InvoiceRevision $revision, QueueWorker $worker)
    {
        abort_unless(in_array($revision->status, ['failed', 'approved']) || ($revision->status === 'generating' && $revision->generation_started_at?->lt(now()->subMinutes(6))), 422, 'Pembuatan file masih berjalan atau telah berhasil.');
        GenerateInvoiceDocuments::dispatch($revision->id)->onConnection('database');
        $worker->ensureRunning();

        return back()->with('success', 'Pembuatan file dijadwalkan ulang.');
    }

    /**
     * Creates the summary picture for an issued version that does not have one yet.
     */
    public function summaryImage(InvoiceRevision $revision, InvoiceDocumentGenerator $documents)
    {
        abort_unless($revision->status === 'ready', 422, 'Gambar ringkasan hanya dibuat untuk versi yang file-nya sudah siap.');
        try {
            $documents->addSummaryImage($revision);
        } catch (\Throwable $e) {
            return back()->withErrors(['summary' => 'Gambar ringkasan gagal dibuat: '.$e->getMessage()]);
        }

        return back()->with('success', 'Gambar ringkasan siap diunduh.');
    }

    public function download(InvoiceOutput $output)
    {
        abort_unless($output->status === 'final' && $output->revision?->status === 'ready' && Storage::disk('local')->exists($output->file_path), 404);
        abort_unless(hash_file('sha256', Storage::disk('local')->path($output->file_path)) === $output->file_hash, 409, 'Integritas file tidak sesuai.');

        return Storage::disk('local')->download($output->file_path, $output->file_name);
    }

    /**
     * Plain-language label, hint and fix link for a readiness issue code.
     *
     * @return array{label: string, hint: string, link: ?string, link_label: ?string}
     */
    public static function issueGuide(string $code, InvoicePeriod $period): array
    {
        $step1 = fn (?string $filter = null) => route('invoice-periods.step1', array_filter([$period, 'filter' => $filter]));
        $master = fn (string $section) => route('price-masters.index', ['section' => $section]);
        $advanced = route('invoice-periods.advanced', $period);
        $guides = [
            'tariff' => ['Harga belum ditemukan', 'Belum ada tarif untuk kombinasi di bawah. Tambahkan di Master Harga › Tarif.', $master('tariffs'), 'Buka Master Harga'],
            'missing_fee' => ['Biaya PSB/relokasi belum diisi', 'Isi biaya instalasi atau relokasi pada tarif. Angka 0 boleh.', $master('tariffs'), 'Buka Master Harga'],
            'tariff_discrepancy' => ['Harga berbeda dengan file Telkom', 'Harga di workbook Telkom tidak sama dengan tarif sistem. Lihat rinciannya di bawah, lalu pilih harga yang dipakai untuk bulan ini.', $advanced, 'Buka Menu Lanjutan'],
            'reference_missing' => ['Harga Telkom kosong di file', 'Hanya catatan: SSID ini tidak punya harga eksisting di workbook Telkom, jadi tidak dibandingkan. Harga sistem dipakai.', null, null],
            'sla_coverage' => ['Downtime belum diisi atau perlu dicek', 'Isi downtime atau tandai SSID tanpa downtime di langkah 1.', $step1('unconfirmed'), 'Isi downtime'],
            'downtime_conflict' => ['Downtime bentrok', 'SSID ini punya lebih dari satu catatan downtime (misalnya diisi manual dan dari file). Pilih nilai yang benar di langkah 1.', $step1('conflict'), 'Pilih nilai yang benar'],
            'invalid_downtime' => ['Downtime melebihi masa aktif', 'Perbaiki angka downtime (detik) untuk SSID ini.', $step1('downtime'), 'Perbaiki downtime'],
            'unknown_sid' => ['SSID belum terdaftar di Master', 'Daftarkan SSID ini di Master Harga › Daftar SSID.', $master('services'), 'Buka Daftar SSID'],
            'duplicate_sid' => ['SSID tercatat dua kali', 'Hubungi admin untuk merapikan daftar layanan periode.', $step1(), 'Lihat layanan'],
            'event_conflict' => ['Dua perubahan di tanggal yang sama', 'Batalkan salah satu perubahan pada SSID ini.', $step1('changed'), 'Lihat perubahan'],
            'event_period' => ['Tanggal perubahan di luar bulan pemakaian', 'Batalkan dan catat ulang perubahan dengan tanggal yang benar.', $step1('changed'), 'Lihat perubahan'],
            'missing_bandwidth' => ['Bandwidth baru belum dipilih', 'Batalkan dan catat ulang upgrade/downgrade dengan bandwidth baru.', $step1('changed'), 'Lihat perubahan'],
            'relocation_address' => ['Alamat baru SSID relokasi belum diisi', 'SSID ini ditandai relokasi di tagihan bulan lalu. Isi alamat barunya di langkah 1.', $step1('relocation'), 'Isi alamat baru'],
            'missing_event' => ['Status order dari draf lama tanpa tanggal', 'Di langkah 1, catat perubahannya beserta tanggal berlaku, atau klik Tandai Existing bila sebenarnya tidak ada perubahan.', $step1('draft_order'), 'Perbaiki di langkah 1'],
            'not_billable' => ['Layanan tidak aktif di bulan ini', 'Periksa tanggal billing SSID di Master atau tanggal Cabut/PSB.', $step1(), 'Lihat layanan'],
            'excess_credit' => ['Potongan melebihi tagihan', 'Periksa kredit manual di Menu Lanjutan.', $advanced, 'Buka Menu Lanjutan'],
            'empty_inventory' => ['Belum ada layanan', 'Salin dari bulan lalu atau ambil layanan dari Master.', $step1(), 'Buka langkah 1'],
            'missing_profile' => ['Data pelanggan & invoice belum diisi', 'Lengkapi di Master Harga › Data Pelanggan & Invoice.', $master('profiles'), 'Buka Master Harga'],
            'uncommitted_import' => ['File upload belum diselesaikan', 'Buka file tersebut, lalu terapkan atau batalkan.', $advanced.'#sumber', 'Lihat file upload'],
            'stale_source' => ['File upload perlu diperiksa ulang', 'Data berubah setelah file diupload. Buka file dan klik Validasi ulang.', $advanced.'#sumber', 'Lihat file upload'],
            'traffic_missing' => ['Data MRTG belum ada', 'Tidak menghalangi penerbitan invoice.', null, null],
            'traffic_coverage' => ['Data MRTG belum lengkap', 'Tidak menghalangi penerbitan invoice.', null, null],
            'source_discrepancy' => ['Total berbeda dengan file sumber', 'Hanya sebagai perbandingan.', $advanced, 'Buka Menu Lanjutan'],
        ];
        [$label, $hint, $link, $linkLabel] = $guides[$code] ?? [str_replace('_', ' ', ucfirst($code)), 'Lihat detail di Menu Lanjutan.', $advanced, 'Buka Menu Lanjutan'];

        return ['label' => $label, 'hint' => $hint, 'link' => $link, 'link_label' => $linkLabel];
    }

    /**
     * SIDs whose single confirmed downtime was entered for a different billable window than today's
     * (a PSB/Cabut was added afterwards), so the operator has to enter it again.
     *
     * @return array<int, string>
     */
    private function staleDowntimeSids(InvoicePeriod $period, Collection $events, Collection $outages): array
    {
        $single = $outages->filter(fn ($rows) => $rows->count() === 1 && $rows->first()->kind === 'aggregate' && $rows->first()->review_status === 'eligible');
        if ($single->isEmpty()) {
            return [];
        }

        return $period->lines()->with('bankJatimLink')->get()->filter(function ($line) use ($period, $events, $single) {
            $sid = $line->billingSid();

            return $sid && $single->has($sid)
                && DowntimeConflicts::outsideWindow($single[$sid]->first(), ActiveWindow::for($period, $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line), $events[$sid] ?? []));
        })->map(fn ($line) => $line->billingSid())->values()->all();
    }

    /**
     * Restricts period lines to SIDs in (or, for "unconfirmed", not in) the given list.
     */
    private function filterLines($query, string $filter, array $sids)
    {
        if ($filter === 'unconfirmed') {
            return $query->where(fn (Builder $q) => $q
                ->where(fn ($q) => $q->whereNull('bank_jatim_link_id')->where(fn ($q) => $q->whereNull('ssid_temp')->orWhereNotIn('ssid_temp', $sids)))
                ->orWhereHas('bankJatimLink', fn ($q) => $q->whereNotIn('ssid', $sids)));
        }

        return $query->where(fn (Builder $q) => $q->whereIn('ssid_temp', $sids)->orWhereHas('bankJatimLink', fn ($q) => $q->whereIn('ssid', $sids)));
    }

    /**
     * Valid [column, direction] requested in the query string, or null for the default order.
     */
    private function requestedSort(Request $request, array $columns): ?array
    {
        $key = $request->query('sort');
        if (! is_string($key) || ! isset($columns[$key])) {
            return null;
        }
        $dir = $request->query('dir');

        return [$key, in_array($dir, ['asc', 'desc'], true) ? $dir : $columns[$key]];
    }

    /**
     * Sorts row arrays by one column. Numeric columns compare as numbers, empty values come last in both
     * directions and ties keep the normal line order. $value overrides how the sort value is read from a row.
     */
    private function sortRows(Collection $rows, string $key, string $dir, ?Closure $value = null): Collection
    {
        $value ??= fn ($row) => $row[$key];
        $numeric = in_array($key, self::NUMERIC_SORTS, true);
        $sign = $dir === 'desc' ? -1 : 1;

        return $rows->sort(function ($a, $b) use ($value, $numeric, $sign) {
            [$x, $y] = [$value($a), $value($b)];
            $emptyX = $x === null || $x === '';
            $emptyY = $y === null || $y === '';
            if ($emptyX || $emptyY) {
                return $emptyX && $emptyY ? $a['number'] <=> $b['number'] : ($emptyX ? 1 : -1);
            }
            $order = $numeric ? (float) $x <=> (float) $y : strnatcasecmp((string) $x, (string) $y);

            return ($order * $sign) ?: ($a['number'] <=> $b['number']);
        })->values();
    }

    /**
     * Label shown in the status badge, so sorting follows what the operator reads.
     */
    private function statusLabel(string $status): string
    {
        $status = strtolower($status);

        return self::EVENT_LABELS[$status] ?? ($status === 'multiple' ? 'Beberapa perubahan' : ucfirst($status));
    }

    /**
     * View model for one step-1 table row (also serialized for the Alpine modals).
     */
    private function row(InvoiceLine $line, InvoicePeriod $period, Collection $events, Collection $outages, Collection $scheduled, Collection $bandwidths, array $batchNames = [], array $relocationFlagged = []): array
    {
        $sid = $line->billingSid();
        $snapshot = $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line);
        $lineEvents = $events[$sid] ?? collect();
        $lineOutages = $outages[$sid] ?? collect();
        $aggregate = $lineOutages->first(fn ($o) => $o->kind === 'aggregate' && $o->review_status === 'eligible');
        $window = ActiveWindow::for($period, $snapshot, $lineEvents);
        $bwEvent = $lineEvents->filter(fn ($e) => ! empty($e->attributes['bandwidth_option_id']))->last();
        $bwId = $bwEvent?->attributes['bandwidth_option_id'] ?? $snapshot['bandwidth_option_id'] ?? $line->existing_bandwidth_option_id;
        $move = $lineEvents->where('event_type', 'relokasi')->last();
        $duration = $lineOutages->count() === 1 ? $aggregate?->duration_seconds : null;
        $stale = $duration !== null && DowntimeConflicts::outsideWindow($aggregate, $window);
        $detail = fn ($attributes) => trim(implode(' · ', array_filter([
            $bandwidths->firstWhere('id', $attributes['bandwidth_option_id'] ?? null)?->bandwidth_name,
            $attributes['new_address'] ?? null,
        ])));

        return [
            'sid' => $sid, 'number' => $line->line_number,
            'branch' => $snapshot['branch'] ?? $line->bankJatimLink?->officeType?->office_type_name ?? '-',
            'address' => $move?->attributes['new_address'] ?? $snapshot['address'] ?? $line->address_snapshot ?? $line->bankJatimLink?->address,
            'service' => $snapshot['service'] ?? $line->bankJatimLink?->service?->service_name,
            'category' => $snapshot['category'] ?? $line->bankJatimLink?->locationCategory?->location_category_name,
            'bandwidth' => $bandwidths->firstWhere('id', $bwId)?->bandwidth_name ?? 'Belum diatur', 'bandwidth_id' => $bwId,
            'status' => $lineEvents->last()?->event_type ?? 'existing',
            'draft_order' => $this->draftOrder($line, $lineEvents),
            // 'pending' / 'done': flagged Relokasi last month, new address (not) entered; 'flagged': flagged this month
            'relocation' => in_array($sid, $relocationFlagged, true) ? (empty($line->new_address) ? 'pending' : 'done') : ($lineEvents->contains(fn ($e) => $e->event_type === 'relokasi' && empty($e->attributes['new_address'])) ? 'flagged' : null),
            'relocation_new_address' => in_array($sid, $relocationFlagged, true) ? $line->new_address : null,
            'relocation_old_address' => in_array($sid, $relocationFlagged, true) ? ($line->old_address_snapshot ?? $snapshot['address'] ?? null) : null,
            'draft_order_label' => self::EVENT_LABELS[$this->draftOrder($line, $lineEvents) ?? ''] ?? null,
            'events' => $lineEvents->map(fn ($e) => [
                'label' => self::EVENT_LABELS[$e->event_type] ?? $e->event_type,
                'date' => $e->event_type === 'relokasi' && empty($e->attributes['new_address']) ? 'tanpa tanggal' : $e->effective_date->format('d/m/Y'),
                'detail' => $detail($e->attributes ?? []), 'scheduled' => ($e->source['origin'] ?? null) === 'scheduled',
                'cancel_url' => route('billing.service-events.remove', [$period, $e]),
            ])->values()->all(),
            'scheduled' => ($scheduled[$sid] ?? collect())->map(fn ($c) => [
                'label' => self::EVENT_LABELS[$c->event_type] ?? $c->event_type, 'date' => $c->effective_date->format('d/m/Y'),
                'detail' => $detail($c->getAttribute('attributes') ?? []), 'target' => $c->targetLabel(),
                'cancel_url' => route('billing.scheduled.cancel', $c),
            ])->values()->all(),
            'downtime' => $duration,
            'downtime_state' => $lineOutages->count() > 1 ? 'conflict' : ($duration !== null ? ($stale ? 'stale' : 'set') : ($lineOutages->isNotEmpty() ? 'review' : 'none')),
            'window_label' => $window ? Carbon::parse($window['start'])->format('d/m/Y').' – '.Carbon::parse($window['end'])->format('d/m/Y') : null,
            'downtime_from_file' => $duration !== null && $aggregate->import_batch_id !== null,
            'downtime_records' => $lineOutages->count(),
            'downtime_options' => $lineOutages->map(fn ($o) => DowntimeConflicts::describe($o, $batchNames))->values()->all(),
            'downtime_choosable' => $lineOutages->every(fn ($o) => $o->kind === 'aggregate' && $o->review_status === 'eligible'),
            'downtime_source' => $aggregate ? DowntimeConflicts::describe($aggregate, $batchNames)['source'] : null,
            'review_url' => route('invoice-periods.advanced', [$period, 'sid' => $sid]).'#downtime',
            'active_seconds' => $window['seconds'] ?? 0,
            'sla' => $duration === null || $stale || ! $window ? null : (1 - $duration / $window['seconds']) * 100,
        ];
    }

    private function audit(?InvoicePeriod $period, string $action, array $details): void
    {
        DB::table('billing_audit_events')->insert(['invoice_period_id' => $period?->id, 'action' => $action, 'details' => json_encode($details), 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
