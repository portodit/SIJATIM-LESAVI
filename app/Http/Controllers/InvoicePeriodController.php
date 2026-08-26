<?php

namespace App\Http\Controllers;

use App\Models\InvoicePeriod;
use App\Models\InvoiceStep;
use App\Models\InvoiceStepStatus;
use App\Models\InvoiceLine;
use App\Models\TypeOrder;
use App\Models\BandwidthOption;
use App\Exports\Step2TemplateExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class InvoicePeriodController extends Controller
{
    /**
     * Display a listing of invoice periods.
     */
    public function index(Request $request)
    {
        $query = InvoicePeriod::with('creator')
            ->active()
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc');

        // Filter by year if provided
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $periods = $query->paginate(10)->withQueryString();

        // Get available years for filter
        $years = InvoicePeriod::select('year')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year');

        return view('invoice-periods.index', [
            'title' => 'Periode Invoice',
            'subtitle' => 'Kelola folder kerja tagihan Bank Jatim',
            'periods' => $periods,
            'years' => $years,
        ]);
    }

    /**
     * Show the form for creating a new invoice period.
     */
    public function create()
    {
        return view('invoice-periods.create', [
            'title' => 'Buat Periode Invoice Baru',
            'subtitle' => 'Mulai periode billing baru',
        ]);
    }

    /**
     * Store a newly created invoice period in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'period_month' => 'required|integer|between:1,12',
            'period_year' => 'required|integer|min:2020|max:2100',
            'notes' => 'nullable|string|max:1000',
        ], [
            'period_month.required' => 'Bulan periode wajib dipilih',
            'period_year.required' => 'Tahun periode wajib diisi',
            'period_year.min' => 'Tahun minimal adalah 2020',
        ]);

        // Check if period already exists
        if (InvoicePeriod::periodExists($request->period_month, $request->period_year)) {
            $monthName = InvoicePeriod::MONTH_NAMES[$request->period_month];
            $year = $request->period_year;
            return back()->withInput()->withErrors([
                'period_month' => "Periode {$monthName} {$year} sudah ada."
            ]);
        }

        try {
            DB::beginTransaction();

            // Generate values
            $periodCode = InvoicePeriod::generatePeriodCode($request->period_month, $request->period_year);
            $startDate = InvoicePeriod::generateStartDate($request->period_month, $request->period_year);
            $endDate = InvoicePeriod::generateEndDate($request->period_month, $request->period_year);

            // Create invoice period
            $period = InvoicePeriod::create([
                'period_code' => $periodCode,
                'month' => $request->period_month,
                'year' => $request->period_year,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'billing_days' => 30,
                'status' => InvoicePeriod::STATUS_DRAFT,
                'current_step_code' => InvoicePeriod::STEP_OVERVIEW,
                'progress_percentage' => 0,
                'is_locked' => false,
                'created_by' => Auth::id(),
                'notes' => $request->notes,
            ]);

            // Create step statuses for all active steps
            $activeSteps = InvoiceStep::where('is_active', true)->orderBy('step_number')->get();

            foreach ($activeSteps as $step) {
                InvoiceStepStatus::create([
                    'invoice_period_id' => $period->id,
                    'invoice_step_id' => $step->id,
                    'status' => InvoiceStepStatus::STATUS_NOT_STARTED,
                    'error_count' => 0,
                    'warning_count' => 0,
                ]);
            }

            // Set overview step to in_progress
            $overviewStep = InvoiceStep::where('step_code', InvoicePeriod::STEP_OVERVIEW)->first();
            if ($overviewStep) {
                $overviewStatus = InvoiceStepStatus::where('invoice_period_id', $period->id)
                    ->where('invoice_step_id', $overviewStep->id)
                    ->first();
                if ($overviewStatus) {
                    $overviewStatus->update([
                        'status' => InvoiceStepStatus::STATUS_IN_PROGRESS,
                        'started_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('invoice-periods.show', $period)
                ->with('success', "Periode invoice {$period->period_code} berhasil dibuat!");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->withErrors([
                'error' => 'Gagal membuat periode invoice: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Display the specified invoice period.
     */
    public function show(InvoicePeriod $invoicePeriod)
    {
        $invoicePeriod->load([
            'creator',
            'finalizer',
            'stepStatuses.step',
        ]);

        // Get steps with their statuses
        $steps = InvoiceStep::where('is_active', true)
            ->orderBy('step_number')
            ->get()
            ->map(function ($step) use ($invoicePeriod) {
                $status = $invoicePeriod->stepStatuses->firstWhere('invoice_step_id', $step->id);
                return [
                    'step' => $step,
                    'status' => $status,
                ];
            });

        // Calculate progress
        $totalSteps = $steps->count();
        $completedSteps = $steps->filter(function ($item) {
            return $item['status'] && in_array($item['status']->status, [
                InvoiceStepStatus::STATUS_COMPLETED,
                InvoiceStepStatus::STATUS_SKIPPED
            ]);
        })->count();

        $progressPercentage = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;

        // Get previous period
        $previousPeriod = InvoicePeriod::where(function ($query) use ($invoicePeriod) {
            $query->where('year', '<', $invoicePeriod->year)
                  ->orWhere(function ($q) use ($invoicePeriod) {
                      $q->where('year', $invoicePeriod->year)
                        ->where('month', '<', $invoicePeriod->month);
                  });
        })
        ->where('id', '!=', $invoicePeriod->id)
        ->orderBy('year', 'desc')
        ->orderBy('month', 'desc')
        ->first();

        return view('invoice-periods.show', [
            'title' => $invoicePeriod->period_code,
            'subtitle' => 'Periode: ' . $invoicePeriod->period_name,
            'period' => $invoicePeriod,
            'steps' => $steps,
            'progressPercentage' => $progressPercentage,
            'completedSteps' => $completedSteps,
            'totalSteps' => $totalSteps,
            'previousPeriod' => $previousPeriod,
        ]);
    }

    /**
     * Show the form for editing the specified invoice period.
     */
    public function edit(InvoicePeriod $invoicePeriod)
    {
        if (!$invoicePeriod->canEdit()) {
            return redirect()->route('invoice-periods.show', $invoicePeriod)
                ->with('error', 'Periode yang sudah final atau diarsipkan tidak dapat diedit.');
        }

        return view('invoice-periods.edit', [
            'title' => 'Edit Periode Invoice',
            'subtitle' => $invoicePeriod->period_code,
            'period' => $invoicePeriod,
        ]);
    }

    /**
     * Update the specified invoice period in storage.
     */
    public function update(Request $request, InvoicePeriod $invoicePeriod)
    {
        if (!$invoicePeriod->canEdit()) {
            return redirect()->route('invoice-periods.show', $invoicePeriod)
                ->with('error', 'Periode yang sudah final atau diarsipkan tidak dapat diedit.');
        }

        $request->validate([
            'notes' => 'nullable|string|max:1000',
            'status' => [
                'required',
                Rule::in([
                    InvoicePeriod::STATUS_DRAFT,
                    InvoicePeriod::STATUS_IN_PROGRESS,
                    InvoicePeriod::STATUS_VALIDATION,
                    InvoicePeriod::STATUS_FINALIZED,
                    InvoicePeriod::STATUS_REVISION,
                    InvoicePeriod::STATUS_ARCHIVED,
                ]),
            ],
        ]);

        $invoicePeriod->update([
            'notes' => $request->notes,
            'status' => $request->status,
        ]);

        return redirect()->route('invoice-periods.show', $invoicePeriod)
            ->with('success', 'Periode invoice berhasil diperbarui!');
    }

    /**
     * Remove the specified invoice period from storage.
     */
    public function destroy(InvoicePeriod $invoicePeriod)
    {
        if ($invoicePeriod->status !== InvoicePeriod::STATUS_DRAFT) {
            return redirect()->route('invoice-periods.index')
                ->with('error', 'Hanya periode dengan status Draft yang dapat dihapus.');
        }

        $invoicePeriod->delete();

        return redirect()->route('invoice-periods.index')
            ->with('success', 'Periode invoice berhasil dihapus!');
    }

    /**
     * Get step status for AJAX updates
     */
    public function step1(InvoicePeriod $invoicePeriod)
    {
        $invoicePeriod->load(['stepStatuses.step']);

        // Get office types, location categories, services for dropdowns
        $officeTypes = \App\Models\OfficeType::active()->ordered()->get();
        $locationCategories = \App\Models\LocationCategory::active()->ordered()->get();
        $services = \App\Models\Service::active()->ordered()->get();
        $bandwidthOptions = \App\Models\BandwidthOption::active()->ordered()->get();

        // Get invoice lines count for this period
        $invoiceLinesCount = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->count();

        // Get all bank jatim links (master data)
        $bankJatimLinks = \App\Models\BankJatimLink::with(['officeType', 'locationCategory', 'service'])
            ->orderBy('office_type_id')
            ->orderBy('sequence_in_office_type')
            ->get();

        // Get current step
        $currentStep = \App\Models\InvoiceStep::where('step_code', 'ssid_list')->first();
        $steps = \App\Models\InvoiceStep::where('is_active', true)
            ->orderBy('step_number')
            ->get();

        // Get invoice lines with their relations for the table
        $invoiceLines = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service'])
            ->orderBy('line_number')
            ->get()
            ->map(function($line) {
                $startDate = null;
                $bankLink = $line->bankJatimLink;
                if ($bankLink && $bankLink->service_start_date) {
                    $startDate = $bankLink->service_start_date instanceof \Carbon\Carbon
                        ? $bankLink->service_start_date->format('Y-m-d')
                        : $bankLink->service_start_date;
                }
                $ssid = $bankLink ? $bankLink->ssid : ($line->ssid_temp ?? '-');
                $officeType = $bankLink ? ($bankLink->officeType ?? null) : null;
                $locationCategory = $bankLink ? ($bankLink->locationCategory ?? null) : null;
                $service = $bankLink ? ($bankLink->service ?? null) : null;

                return [
                    'id' => $line->id,
                    'line_number' => $line->line_number,
                    'ssid' => $ssid,
                    'office_type' => ['id' => $officeType?->id, 'name' => $officeType?->name ?? '-'],
                    'location_category' => ['id' => $locationCategory?->id, 'name' => $locationCategory?->name ?? '-'],
                    'service' => ['id' => $service?->id, 'name' => $service?->name ?? '-'],
                    'ip_host_telkom' => $bankLink ? $bankLink->ip_host_telkom : '-',
                    'address' => $bankLink ? $bankLink->address : '-',
                    'service_start_date' => $startDate,
                    'is_new_ssid' => $line->is_new_ssid ?? false,
                ];
            })
            ->toArray();

        $stepData = [
            'officeTypes' => $officeTypes,
            'locationCategories' => $locationCategories,
            'services' => $services,
            'bandwidthOptions' => $bandwidthOptions,
            'bankJatimLinks' => $bankJatimLinks,
            'invoiceLines' => $invoiceLines,
            'invoiceLinesCount' => $invoiceLinesCount,
            'pendingNewSsidsCount' => \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                ->where('is_new_ssid', true)
                ->whereNull('bank_jatim_link_id')
                ->count(),
        ];

        return view('invoice-periods.step', [
            'title' => 'Sub Tahap 1.1 - Manage SSID List',
            'subtitle' => $invoicePeriod->period_code,
            'period' => $invoicePeriod,
            'currentStep' => $currentStep,
            'steps' => $steps,
            'stepData' => $stepData,
            'invoiceLinesCount' => $invoiceLinesCount,
        ]);
    }

    /**
     * Get paginated SSID data for table
     */
    public function getSsidData(Request $request, InvoicePeriod $invoicePeriod)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $query = \App\Models\BankJatimLink::with(['officeType', 'locationCategory', 'service', 'currentBandwidth'])
            ->orderBy('office_type_id')
            ->orderBy('sequence_in_office_type');

        $total = $query->count();
        $links = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $links->items(),
            'pagination' => [
                'current_page' => $links->currentPage(),
                'per_page' => $links->perPage(),
                'total' => $total,
                'last_page' => $links->lastPage(),
                'from' => $links->firstItem(),
                'to' => $links->lastItem(),
            ]
        ]);
    }

    /**
     * Download SSID import format (Simple - 1 column SSID)
     */
    public function downloadSsidFormat(InvoicePeriod $invoicePeriod)
    {
        $csvContent = "SSID\n";
        $csvContent .= "BANKJTM-KPU-001\n";
        $csvContent .= "BANKJTM-UTAMA-001\n";
        $csvContent .= "4700020-38090\n";

        $fileName = 'format_import_ssid_' . $invoicePeriod->period_code . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ];

        return response($csvContent, 200, $headers);
    }

    /**
     * Check SSIDs and AUTO-PROCESS
     */
    public function checkSsidImport(Request $request, InvoicePeriod $invoicePeriod)
    {
        $request->validate([
            'ssids' => 'required|string',
        ]);

        $inputSsids = preg_split('/[\s,;\n\r]+/', $request->ssids);
        $inputSsids = array_filter(array_map('trim', $inputSsids));
        $inputSsids = array_unique($inputSsids);

        if (empty($inputSsids)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada SSID yang valid',
            ]);
        }

        // Get existing SSIDs from database
        $existingLinks = \App\Models\BankJatimLink::whereIn('ssid', $inputSsids)
            ->with(['officeType', 'locationCategory', 'service'])
            ->orderBy('office_type_id')
            ->orderBy('sequence_in_office_type')
            ->get()
            ->keyBy('ssid');

        // Get existing invoice lines for this period
        $existingLines = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->pluck('bank_jatim_link_id')
            ->toArray();

        // Check if there are pending new SSIDs
        $pendingNewSsids = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->where('is_new_ssid', true)
            ->whereNull('bank_jatim_link_id')
            ->pluck('ssid_temp')
            ->filter()
            ->toArray();

        if (!empty($pendingNewSsids)) {
            return response()->json([
                'success' => false,
                'message' => "Ada " . count($pendingNewSsids) . " SSID baru yang belum diselesaikan.",
                'pending_ssids' => $pendingNewSsids,
            ]);
        }

        $ssidsFoundInDb = $existingLinks->keys()->toArray();
        $ssidsNotFoundInDb = array_diff($inputSsids, $ssidsFoundInDb);

        $results = [
            'auto_added_count' => 0,
            'skipped_count' => 0,
            'new_ssid_count' => count($ssidsNotFoundInDb),
            'new_ssids' => [],
            'skipped_ssids' => [],
            'auto_added_ssids' => [],
            'has_pending_new_ssids' => count($ssidsNotFoundInDb) > 0,
        ];

        DB::beginTransaction();
        try {
            $maxLineNumber = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->max('line_number') ?? 0;

            // Process SSIDs that exist in DB
            foreach ($existingLinks as $ssid => $link) {
                if (in_array($link->id, $existingLines)) {
                    $results['skipped_ssids'][] = [
                        'ssid' => $ssid,
                        'jenis_kantor' => $link->officeType->name ?? '-',
                        'kategori' => $link->locationCategory->name ?? '-',
                        'layanan' => $link->service->name ?? '-',
                    ];
                    $results['skipped_count']++;
                } else {
                    $lineNumber = $link->sequence_in_office_type ?? ($maxLineNumber + 1);
                    $existingLine = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                        ->where('line_number', $lineNumber)
                        ->first();
                    if ($existingLine) {
                        $lineNumber = $maxLineNumber + 1;
                        $maxLineNumber = $lineNumber;
                    } else {
                        $maxLineNumber = max($maxLineNumber, $lineNumber);
                    }

                    \App\Models\InvoiceLine::create([
                        'invoice_period_id' => $invoicePeriod->id,
                        'bank_jatim_link_id' => $link->id,
                        'line_number' => $lineNumber,
                        'effective_date' => $invoicePeriod->start_date,
                        'address_snapshot' => $link->address,
                        'downtime_days' => 0,
                        'downtime_hours' => 0,
                        'downtime_minutes' => 0,
                        'sla_target' => 99.5,
                        'restitution_manual' => 0,
                        'restitution_over_sla' => 0,
                        'restitution_total' => 0,
                    ]);

                    $results['auto_added_ssids'][] = [
                        'ssid' => $ssid,
                        'jenis_kantor' => $link->officeType->name ?? '-',
                        'kategori' => $link->locationCategory->name ?? '-',
                        'layanan' => $link->service->name ?? '-',
                        'ip_host' => $link->ip_host_telkom ?? '-',
                    ];
                    $results['auto_added_count']++;
                }
            }

            // Process SSIDs that are NEW (not in DB) - STORE AS PENDING
            foreach ($ssidsNotFoundInDb as $ssid) {
                $maxLineNumber++;

                \App\Models\InvoiceLine::create([
                    'invoice_period_id' => $invoicePeriod->id,
                    'bank_jatim_link_id' => null,
                    'line_number' => $maxLineNumber,
                    'effective_date' => $invoicePeriod->start_date,
                    'is_new_ssid' => true,
                    'ssid_temp' => $ssid,
                    'address_snapshot' => null,
                    'downtime_days' => 0,
                    'downtime_hours' => 0,
                    'downtime_minutes' => 0,
                    'sla_target' => 99.5,
                    'restitution_manual' => 0,
                    'restitution_over_sla' => 0,
                    'restitution_total' => 0,
                ]);

                $results['new_ssids'][] = [
                    'ssid' => $ssid,
                    'line_number' => $maxLineNumber,
                    'jenis_kantor' => '-',
                    'kategori' => '-',
                    'layanan' => '-',
                ];
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses SSID: ' . $e->getMessage(),
            ]);
        }

        $message = "Proses selesai. ";
        $message .= "{$results['auto_added_count']} SSID otomatis ditambahkan. ";
        if ($results['skipped_count'] > 0) {
            $message .= "{$results['skipped_count']} SSID sudah ada di periode. ";
        }
        if ($results['new_ssid_count'] > 0) {
            $message .= "{$results['new_ssid_count']} SSID BARU perlu diisi datanya.";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'results' => $results,
        ]);
    }

    /**
     * Save imported SSIDs to invoice_lines
     */
    public function saveImportedSsids(Request $request, InvoicePeriod $invoicePeriod)
    {
        $request->validate([
            'ready_to_add' => 'nullable|array',
            'new_ssids' => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            $maxLineNumber = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->max('line_number') ?? 0;
            $addedCount = 0;

            $readyToAdd = $request->input('ready_to_add', []);
            foreach ($readyToAdd as $item) {
                $linkId = is_array($item) ? ($item['bank_jatim_link_id'] ?? null) : $item;
                if (!$linkId) continue;

                $link = \App\Models\BankJatimLink::find($linkId);
                if (!$link) continue;

                $exists = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                    ->where('bank_jatim_link_id', $linkId)
                    ->exists();
                if ($exists) continue;

                $lineNumber = $link->sequence_in_office_type ?? ($maxLineNumber + 1);
                $existingLine = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                    ->where('line_number', $lineNumber)
                    ->first();
                if ($existingLine) {
                    $lineNumber = $maxLineNumber + 1;
                    $maxLineNumber = $lineNumber;
                } else {
                    $maxLineNumber = max($maxLineNumber, $lineNumber);
                }

                \App\Models\InvoiceLine::create([
                    'invoice_period_id' => $invoicePeriod->id,
                    'bank_jatim_link_id' => $linkId,
                    'line_number' => $lineNumber,
                    'effective_date' => $invoicePeriod->start_date,
                    'address_snapshot' => $link->address,
                    'downtime_days' => 0,
                    'downtime_hours' => 0,
                    'downtime_minutes' => 0,
                    'sla_target' => 99.5,
                    'restitution_manual' => 0,
                    'restitution_over_sla' => 0,
                    'restitution_total' => 0,
                ]);
                $addedCount++;
            }

            $newSsids = $request->input('new_ssids', []);
            foreach ($newSsids as $newSsid) {
                if (isset($newSsid['bank_jatim_link_id'])) {
                    $link = \App\Models\BankJatimLink::find($newSsid['bank_jatim_link_id']);
                    if ($link) {
                        $lineNumber = $link->sequence_in_office_type ?? ($maxLineNumber + 1);
                        $existingLine = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                            ->where('line_number', $lineNumber)
                            ->first();
                        if ($existingLine) {
                            $lineNumber = $maxLineNumber + 1;
                        }

                        \App\Models\InvoiceLine::create([
                            'invoice_period_id' => $invoicePeriod->id,
                            'bank_jatim_link_id' => $newSsid['bank_jatim_link_id'],
                            'line_number' => $lineNumber,
                            'effective_date' => $invoicePeriod->start_date,
                            'address_snapshot' => $link->address,
                            'is_new_ssid' => true,
                            'new_ssid_note' => 'SSID baru ditambahkan di periode ' . $invoicePeriod->period_code,
                        ]);
                        $addedCount++;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$addedCount} SSID berhasil disimpan ke invoice",
                'count' => $addedCount,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan SSID: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Store new SSID to bank_jatim_links AND add to invoice_lines for this period
     */
    public function storeNewSsid(Request $request, InvoicePeriod $invoicePeriod)
    {
        $request->validate([
            'ssid' => 'required|string|max:255|unique:bank_jatim_links,ssid',
            'office_type_id' => 'required|exists:office_types,id',
            'location_category_id' => 'required|exists:location_categories,id',
            'service_id' => 'required|exists:services,id',
            'ip_host_telkom' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'service_start_date' => 'nullable|date',
            'link_status' => 'nullable|in:active,inactive',
            'pending_line_id' => 'nullable|exists:invoice_lines,id',
        ]);

        DB::beginTransaction();
        try {
            $officeTypeId = $request->office_type_id;
            $locationCategoryId = $request->location_category_id;

            // Get next sequence number within the same location_category
            $maxSeqInCategory = \App\Models\BankJatimLink::where('office_type_id', $officeTypeId)
                ->where('location_category_id', $locationCategoryId)
                ->max('sequence_in_office_type') ?? 0;

            $newSequence = $maxSeqInCategory + 1;

            // Renumber SSIDs that will be after this
            \App\Models\BankJatimLink::where('office_type_id', $officeTypeId)
                ->where('location_category_id', $locationCategoryId)
                ->where('sequence_in_office_type', '>=', $newSequence)
                ->increment('sequence_in_office_type');

            // Create in bank_jatim_links
            $link = \App\Models\BankJatimLink::create([
                'ssid' => $request->ssid,
                'office_type_id' => $officeTypeId,
                'location_category_id' => $locationCategoryId,
                'service_id' => $request->service_id,
                'sequence_in_office_type' => $newSequence,
                'ip_host_telkom' => $request->ip_host_telkom,
                'address' => $request->address,
                'service_start_date' => $request->service_start_date,
                'link_status' => $request->link_status ?? 'active',
                'created_by' => Auth::id(),
            ]);

            // Check if this is resolving a pending SSID
            if ($request->pending_line_id) {
                $pendingLine = \App\Models\InvoiceLine::find($request->pending_line_id);
                if ($pendingLine && $pendingLine->invoice_period_id == $invoicePeriod->id) {
                    $pendingLine->update([
                        'bank_jatim_link_id' => $link->id,
                        'address_snapshot' => $link->address,
                        'is_new_ssid' => true,
                        'new_ssid_note' => 'SSID baru ditambahkan di periode ' . $invoicePeriod->period_code,
                    ]);
                    $lineNumber = $pendingLine->line_number;
                } else {
                    $lineNumber = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->max('line_number') ?? 0;
                    $lineNumber++;
                    $this->createInvoiceLineForNewSsid($invoicePeriod, $link, $lineNumber);
                }
            } else {
                $lineNumber = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->max('line_number') ?? 0;
                $lineNumber++;
                $this->createInvoiceLineForNewSsid($invoicePeriod, $link, $lineNumber);
            }

            DB::commit();

            $link->load(['officeType', 'locationCategory', 'service']);

            return response()->json([
                'success' => true,
                'message' => 'SSID berhasil ditambahkan (urutan: ' . $newSequence . ' dalam kategori ini)',
                'data' => [
                    'id' => $link->id,
                    'ssid' => $link->ssid,
                    'office_type' => $link->officeType ? ['name' => $link->officeType->name] : null,
                    'location_category' => $link->locationCategory ? ['name' => $link->locationCategory->name] : null,
                    'service' => $link->service ? ['name' => $link->service->name] : null,
                    'ip_host_telkom' => $link->ip_host_telkom,
                    'address' => $link->address,
                    'service_start_date' => $link->service_start_date,
                    'link_status' => $link->link_status,
                    'line_number' => $lineNumber,
                    'sequence' => $newSequence,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Helper: Create invoice line for new SSID
     */
    private function createInvoiceLineForNewSsid(InvoicePeriod $invoicePeriod, $link, int $lineNumber)
    {
        \App\Models\InvoiceLine::create([
            'invoice_period_id' => $invoicePeriod->id,
            'bank_jatim_link_id' => $link->id,
            'line_number' => $lineNumber,
            'effective_date' => $invoicePeriod->start_date,
            'address_snapshot' => $link->address,
            'downtime_days' => 0,
            'downtime_hours' => 0,
            'downtime_minutes' => 0,
            'sla_target' => 99.5,
            'restitution_manual' => 0,
            'restitution_over_sla' => 0,
            'restitution_total' => 0,
            'is_new_ssid' => true,
            'new_ssid_note' => 'SSID baru ditambahkan di periode ' . $invoicePeriod->period_code,
        ]);
    }

    /**
     * Update SSID data in invoice_lines and bank_jatim_links
     */
    public function updateSsid(Request $request, InvoicePeriod $invoicePeriod, InvoiceLine $invoiceLine)
    {
        $request->validate([
            'office_type_id' => 'required|exists:office_types,id',
            'location_category_id' => 'required|exists:location_categories,id',
            'service_id' => 'required|exists:services,id',
            'ip_host_telkom' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'service_start_date' => 'nullable|date',
            'link_status' => 'nullable|in:active,inactive',
        ]);

        DB::beginTransaction();
        try {
            $link = $invoiceLine->bankJatimLink;

            $oldOfficeTypeId = $link->office_type_id;
            $oldLocationCategoryId = $link->location_category_id;
            $oldSequence = $link->sequence_in_office_type;

            $newOfficeTypeId = $request->office_type_id;
            $newLocationCategoryId = $request->location_category_id;

            $locationCategoryChanged = ($oldLocationCategoryId != $newLocationCategoryId);
            $officeTypeChanged = ($oldOfficeTypeId != $newOfficeTypeId);

            if ($locationCategoryChanged || $officeTypeChanged) {
                // Remove from old location category
                \App\Models\BankJatimLink::where('office_type_id', $oldOfficeTypeId)
                    ->where('location_category_id', $oldLocationCategoryId)
                    ->where('sequence_in_office_type', '>', $oldSequence)
                    ->decrement('sequence_in_office_type');

                // Get max sequence in new location category
                $maxSeqInNewCategory = \App\Models\BankJatimLink::where('office_type_id', $newOfficeTypeId)
                    ->where('location_category_id', $newLocationCategoryId)
                    ->max('sequence_in_office_type') ?? 0;
                $newSequence = $maxSeqInNewCategory + 1;

                // Renumber in new category
                \App\Models\BankJatimLink::where('office_type_id', $newOfficeTypeId)
                    ->where('location_category_id', $newLocationCategoryId)
                    ->where('sequence_in_office_type', '>=', $newSequence)
                    ->increment('sequence_in_office_type');

                $link->update([
                    'office_type_id' => $newOfficeTypeId,
                    'location_category_id' => $newLocationCategoryId,
                    'service_id' => $request->service_id,
                    'sequence_in_office_type' => $newSequence,
                    'ip_host_telkom' => $request->ip_host_telkom,
                    'address' => $request->address,
                    'service_start_date' => $request->service_start_date,
                    'link_status' => $request->link_status ?? $link->link_status,
                    'updated_by' => Auth::id(),
                ]);
            } else {
                $link->update([
                    'service_id' => $request->service_id,
                    'ip_host_telkom' => $request->ip_host_telkom,
                    'address' => $request->address,
                    'service_start_date' => $request->service_start_date,
                    'link_status' => $request->link_status ?? $link->link_status,
                    'updated_by' => Auth::id(),
                ]);
            }

            if ($request->has('address')) {
                $invoiceLine->update([
                    'address_snapshot' => $request->address,
                ]);
            }

            DB::commit();

            $link->load(['officeType', 'locationCategory', 'service']);

            return response()->json([
                'success' => true,
                'message' => 'SSID berhasil diupdate' . ($locationCategoryChanged || $officeTypeChanged ? '. Urutan diperbarui.' : ''),
                'data' => [
                    'id' => $link->id,
                    'ssid' => $link->ssid,
                    'office_type' => $link->officeType ? ['id' => $link->officeType->id, 'name' => $link->officeType->name] : null,
                    'location_category' => $link->locationCategory ? ['id' => $link->locationCategory->id, 'name' => $link->locationCategory->name] : null,
                    'service' => $link->service ? ['id' => $link->service->id, 'name' => $link->service->name] : null,
                    'sequence' => $link->sequence_in_office_type,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal update: ' . $e->getMessage(),
            ]);
        }
    }

    public function getStepStatus(InvoicePeriod $invoicePeriod, string $stepCode)
    {
        $step = InvoiceStep::where('step_code', $stepCode)->firstOrFail();
        $status = InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
            ->where('invoice_step_id', $step->id)
            ->first();

        return response()->json([
            'success' => true,
            'step' => $step,
            'status' => $status,
        ]);
    }

    /**
     * Check if invoice lines exist for this period
     */
    public function checkLines(InvoicePeriod $invoicePeriod)
    {
        $count = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->count();

        return response()->json([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Update step status
     */
    public function updateStepStatus(Request $request, InvoicePeriod $invoicePeriod)
    {
        $request->validate([
            'step_code' => 'required|exists:invoice_steps,step_code',
            'status' => 'required|in:not_started,in_progress,completed,error,skipped',
            'notes' => 'nullable|string|max:1000',
        ]);

        $step = InvoiceStep::where('step_code', $request->step_code)->firstOrFail();

        $stepStatus = InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
            ->where('invoice_step_id', $step->id)
            ->first();

        $updateData = [
            'status' => $request->status,
            'notes' => $request->notes ?? null,
        ];

        if ($request->status === InvoiceStepStatus::STATUS_IN_PROGRESS) {
            if (!$stepStatus) {
                $stepStatus = InvoiceStepStatus::create([
                    'invoice_period_id' => $invoicePeriod->id,
                    'invoice_step_id' => $step->id,
                    'status' => $request->status,
                    'started_at' => now(),
                    'notes' => $request->notes ?? null,
                ]);
            } else {
                if (!$stepStatus->started_at) {
                    $updateData['started_at'] = now();
                }
                $stepStatus->update($updateData);
            }
        } elseif ($request->status === InvoiceStepStatus::STATUS_COMPLETED) {
            $updateData['completed_at'] = now();
            $updateData['completed_by'] = Auth::id();
            if ($stepStatus) {
                $stepStatus->update($updateData);
            } else {
                $stepStatus = InvoiceStepStatus::create([
                    'invoice_period_id' => $invoicePeriod->id,
                    'invoice_step_id' => $step->id,
                    'status' => $request->status,
                    'completed_at' => now(),
                    'completed_by' => Auth::id(),
                    'notes' => $request->notes ?? null,
                ]);
            }
        } else {
            if ($stepStatus) {
                $stepStatus->update($updateData);
            } else {
                $stepStatus = InvoiceStepStatus::create([
                    'invoice_period_id' => $invoicePeriod->id,
                    'invoice_step_id' => $step->id,
                    'status' => $request->status,
                    'notes' => $request->notes ?? null,
                ]);
            }
        }

        $invoicePeriod->update(['current_step_code' => $request->step_code]);
        $this->recalculateProgress($invoicePeriod);

        return response()->json([
            'success' => true,
            'message' => 'Status step berhasil diperbarui',
            'step_status' => $stepStatus->fresh(),
            'period_progress' => $invoicePeriod->fresh()->progress_percentage,
        ]);
    }

    /**
     * Recalculate and update progress percentage
     */
    private function recalculateProgress(InvoicePeriod $period)
    {
        $totalSteps = $period->stepStatuses()->count();
        $completedSteps = $period->stepStatuses()
            ->whereIn('status', [
                InvoiceStepStatus::STATUS_COMPLETED,
                InvoiceStepStatus::STATUS_SKIPPED
            ])
            ->count();

        $progress = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;

        $period->update(['progress_percentage' => $progress]);

        if ($progress == 100 && $period->status === InvoicePeriod::STATUS_IN_PROGRESS) {
            $period->update(['status' => InvoicePeriod::STATUS_VALIDATION]);
        }
    }

    /**
     * Step 2: Type Order & Bandwidth Data
     */
    public function step2(InvoicePeriod $invoicePeriod)
    {
        // VALIDASI: Cek apakah step sebelumnya (ssid_list) sudah selesai
        $ssidListStep = \App\Models\InvoiceStep::where('step_code', InvoicePeriod::STEP_SSID_LIST)->first();
        if ($ssidListStep) {
            $ssidListStatus = InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
                ->where('invoice_step_id', $ssidListStep->id)
                ->first();

            if (!$ssidListStatus || !in_array($ssidListStatus->status, [InvoiceStepStatus::STATUS_COMPLETED, InvoiceStepStatus::STATUS_SKIPPED])) {
                return redirect()->route('invoice-periods.show', $invoicePeriod)
                    ->with('warning', "Anda harus menyelesaikan Step 1 terlebih dahulu.");
            }
        }

        $invoicePeriod->load(['stepStatuses.step']);

        // Get SSIDs dari Step 1
        $invoiceLines = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service', 'typeOrder', 'invoiceBandwidth'])
            ->orderBy('line_number')
            ->get();

        $invoiceLinesCount = $invoiceLines->count();
        $typeOrders = \App\Models\TypeOrder::active()->ordered()->get();
        $bandwidthOptions = \App\Models\BandwidthOption::active()->ordered()->get();
        $currentStep = \App\Models\InvoiceStep::where('step_code', 'type_order')->first();
        $stepStatus = $invoicePeriod->stepStatuses->firstWhere('invoice_step_id', $currentStep->id);

        // force_edit=1: reset status to in_progress so page loads in edit mode
        if (request()->boolean('force_edit') && $stepStatus && in_array($stepStatus->status, ['completed', 'skipped'])) {
            $stepStatus->update(['status' => \App\Models\InvoiceStepStatus::STATUS_IN_PROGRESS]);
            return redirect()->route('invoice-periods.step2', $invoicePeriod)->with('info', 'Mode edit diaktifkan.');
        }
        $steps = \App\Models\InvoiceStep::where('is_active', true)->orderBy('step_number')->get();
        $filledCount = $invoiceLines->filter(fn($line) => $line->isTypeOrderComplete())->count();
        $isComplete = $invoiceLinesCount > 0 && $filledCount == $invoiceLinesCount;

        $stepData = [
            'targetCount' => $invoiceLinesCount,
            'filledCount' => $filledCount,
            'isComplete' => $isComplete,
        ];

        return view('invoice-periods.step2', [
            'title' => 'Sub Tahap 1.2 - Type Order & Bandwidth',
            'subtitle' => $invoicePeriod->period_code,
            'period' => $invoicePeriod,
            'currentStep' => $currentStep,
            'steps' => $steps,
            'stepData' => $stepData,
            'invoiceLines' => $invoiceLines,
            'typeOrders' => $typeOrders,
            'bandwidthOptions' => $bandwidthOptions,
            'stepStatus' => $stepStatus,
        ]);
    }

    /**
     * Download Step 2 Template XLSX
     */
    public function downloadStep2Template(InvoicePeriod $invoicePeriod)
    {
        // Get SSIDs dari Step 1
        $invoiceLines = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service', 'bankJatimLink.currentBandwidth'])
            ->orderBy('line_number')
            ->get();

        $typeOrders = TypeOrder::active()->ordered()->get();
        $bandwidthOptions = BandwidthOption::active()->ordered()->get();

        $export = new Step2TemplateExport($invoicePeriod, $invoiceLines, $typeOrders, $bandwidthOptions);
        return $export->download();
    }

    /**
     * Import Step 2 Data from XLSX
     */
    public function importStep2Data(Request $request, InvoicePeriod $invoicePeriod)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $file = $request->file('file');

        \Illuminate\Support\Facades\Log::debug('IMPORT_FILE', [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(1);

        if (!$sheet) {
            return redirect()->back()->with('error', 'Sheet input tidak ditemukan.');
        }

        $typeOrders = TypeOrder::active()->ordered()->get()->keyBy('type_order_code');
        $bandwidthOptions = BandwidthOption::active()->ordered()->get();

        $highestRow = $sheet->getHighestRow();

        // Scan kolom D untuk cari header "SSID"
        $headerRow = null;
        for ($r = 1; $r <= min($highestRow, 30); $r++) {
            $val = (string) $sheet->getCell("D{$r}")->getValue();
            if (strcasecmp(trim($val), 'SSID') === 0) {
                $headerRow = $r;
                break;
            }
        }

        if (!$headerRow) {
            \Illuminate\Support\Facades\Log::error('IMPORT_FAILED', ['reason' => 'header_not_found', 'highestRow' => $highestRow]);
            return redirect()->back()->with('error', 'Header SSID tidak ditemukan (highestRow=' . $highestRow . '). Pastikan file template benar.');
        }

        $results = ['success' => [], 'errors' => [], 'total' => 0, 'valid' => 0, 'invalid' => 0];

        for ($rowNum = $headerRow + 1; $rowNum <= $highestRow; $rowNum++) {
            // Get SSID from column D — handle both string and numeric (integer SSID like 1212012121)
            $ssidRaw = $sheet->getCell('D' . $rowNum)->getValue();
            $ssid = is_numeric($ssidRaw) ? (string) (int) $ssidRaw : trim((string) $ssidRaw);

            if (empty($ssid) || $ssid === 'SSID') continue;

            // Get TYPE ORDER from column E
            $typeOrderCode = trim((string) $sheet->getCell('E' . $rowNum)->getValue());

            // Get TGL PERUBAHAN from column F — HANDLE EXCEL SERIAL DATE
            $tglCell = $sheet->getCell('F' . $rowNum);
            $tglRaw = $tglCell->getValue();
            $effectiveDate = $this->parseExcelDate($tglRaw);

            // Get BANDWIDTH columns G, H, I
            $existingBw = trim((string) $sheet->getCell('G' . $rowNum)->getValue());
            $upgradeBw = trim((string) $sheet->getCell('H' . $rowNum)->getValue());
            $downgradeBw = trim((string) $sheet->getCell('I' . $rowNum)->getValue());

            \Illuminate\Support\Facades\Log::debug('IMPORT_ROW', [
                'row' => $rowNum,
                'ssid' => $ssid,
                'typeOrderCode' => $typeOrderCode,
                'tglRaw' => $tglRaw,
                'effectiveDate' => $effectiveDate ? $effectiveDate->format('Y-m-d') : null,
                'existingBw' => $existingBw,
                'upgradeBw' => $upgradeBw,
                'downgradeBw' => $downgradeBw,
            ]);

            $processResult = $this->processStep2Row(
                $invoicePeriod, $ssid, $typeOrderCode, $effectiveDate,
                $existingBw, $upgradeBw, $downgradeBw,
                $typeOrders, $bandwidthOptions
            );

            if ($processResult['success']) {
                $results['success'][] = $processResult['data'];
                $results['valid']++;
            } else {
                $results['errors'][] = $processResult['error'];
                $results['invalid']++;
            }
            $results['total']++;
        }

        $this->updateStep2Progress($invoicePeriod);

        $message = "Import selesai. {$results['valid']} berhasil, {$results['invalid']} gagal.";
        if ($results['errors']) {
            $message .= " Detail: " . implode('; ', array_slice($results['errors'], 0, 5));
        }

        \Illuminate\Support\Facades\Log::info('IMPORT_DONE', $results);

        if ($results['invalid'] > 0) {
            return redirect()->back()->with('warning', $message);
        }

        return redirect()->route('invoice-periods.step2', $invoicePeriod->id)->with('success', $message);
    }

    /**
     * Preview import: read file, detect changes, return preview (NO database commit).
     */
    public function previewStep2Import(Request $request, InvoicePeriod $invoicePeriod)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $file = $request->file('file');
        $parsedRows = $this->parseStep2File($file);

        if (isset($parsedRows['error'])) {
            return response()->json(['success' => false, 'message' => $parsedRows['error']], 422);
        }

        $typeOrders = TypeOrder::active()->ordered()->get()->keyBy('type_order_code');
        $bandwidthOptions = BandwidthOption::active()->ordered()->get();

        $preview = $this->buildStep2Preview($invoicePeriod, $parsedRows['rows'], $typeOrders, $bandwidthOptions);

        return response()->json([
            'success' => true,
            'total_rows' => count($parsedRows['rows']),
            'preview' => $preview,
        ]);
    }

    /**
     * Commit import: apply the validated import data to the database.
     */
    public function commitStep2Import(Request $request, InvoicePeriod $invoicePeriod)
    {
        $request->validate([
            'data' => 'required|array',
        ]);

        $data = $request->input('data', []);
        $typeOrders = TypeOrder::active()->ordered()->get()->keyBy('type_order_code');
        $bandwidthOptions = BandwidthOption::active()->ordered()->get();

        $results = ['success_count' => 0, 'error_count' => 0, 'errors' => []];

        foreach ($data as $item) {
            $result = $this->applyStep2Row($invoicePeriod, $item, $typeOrders, $bandwidthOptions);
            if ($result['success']) {
                $results['success_count']++;
            } else {
                $results['error_count']++;
                $results['errors'][] = $result['error'];
            }
        }

        $this->updateStep2Progress($invoicePeriod);

        return response()->json([
            'success' => true,
            'message' => "Import selesai. {$results['success_count']} berhasil, {$results['error_count']} gagal.",
            'results' => $results,
        ]);
    }

    /**
     * Parse the uploaded XLSX file and return row data (no DB interaction).
     */
    private function parseStep2File($file): array
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        } catch (\Exception $e) {
            return ['error' => 'Tidak dapat membaca file Excel: ' . $e->getMessage()];
        }

        $sheet = $spreadsheet->getSheet(1);
        if (!$sheet) {
            return ['error' => 'Sheet input tidak ditemukan di file Excel.'];
        }

        $highestRow = $sheet->getHighestRow();

        // Find SSID header row in column D
        $headerRow = null;
        for ($r = 1; $r <= min($highestRow, 30); $r++) {
            $val = (string) $sheet->getCell("D{$r}")->getValue();
            if (strcasecmp(trim($val), 'SSID') === 0) {
                $headerRow = $r;
                break;
            }
        }

        if (!$headerRow) {
            return ['error' => 'Header "SSID" tidak ditemukan di kolom D. Pastikan file template benar.'];
        }

        $rows = [];
        for ($rowNum = $headerRow + 1; $rowNum <= $highestRow; $rowNum++) {
            $ssidRaw = $sheet->getCell('D' . $rowNum)->getValue();
            $ssid = is_numeric($ssidRaw) ? (string) (int) $ssidRaw : trim((string) $ssidRaw);

            if (empty($ssid) || $ssid === 'SSID') continue;

            $typeOrderCode = trim((string) $sheet->getCell('E' . $rowNum)->getValue());
            $tglRaw = $sheet->getCell('F' . $rowNum)->getValue();
            $effectiveDate = $this->parseExcelDate($tglRaw);
            $existingBw = trim((string) $sheet->getCell('G' . $rowNum)->getValue());
            $upgradeBw = trim((string) $sheet->getCell('H' . $rowNum)->getValue());
            $downgradeBw = trim((string) $sheet->getCell('I' . $rowNum)->getValue());

            $rows[] = [
                'ssid' => $ssid,
                'type_order_code' => $typeOrderCode,
                'effective_date' => $effectiveDate,
                'effective_date_str' => $effectiveDate ? $effectiveDate->format('d/m/Y') : null,
                'existing_bw' => $existingBw,
                'upgrade_bw' => $upgradeBw,
                'downgrade_bw' => $downgradeBw,
                'row_number' => $rowNum,
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * Build preview: compare parsed rows against current DB values.
     * Returns new, changed, and error items.
     */
    private function buildStep2Preview($invoicePeriod, array $rows, $typeOrders, $bandwidthOptions): array
    {
        $preview = [
            'new' => [],       // SSID not in period yet
            'changed' => [],   // SSID exists with different values
            'unchanged' => [], // SSID exists, same values
            'errors' => [],    // Validation errors
        ];

        foreach ($rows as $row) {
            // Find invoice line by SSID
            $line = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                ->whereHas('bankJatimLink', fn($q) => $q->where('ssid', $row['ssid']))
                ->first();

            if (!$line) {
                $line = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                    ->whereHas('bankJatimLink', fn($q) => $q->where('ssid', (int) $row['ssid']))
                    ->first();
            }

            if (!$line) {
                $preview['errors'][] = [
                    'ssid' => $row['ssid'],
                    'row' => $row['row_number'],
                    'reason' => "SSID '{$row['ssid']}' tidak ditemukan di periode ini",
                ];
                continue;
            }

            // Resolve type order
            $typeOrderId = null;
            $typeOrderName = null;
            $typeOrderSuggestion = null;
            if ($row['type_order_code']) {
                $correctedCode = $this->findBestTypeOrder($row['type_order_code'], $typeOrders);
                if ($correctedCode) {
                    $typeOrder = $typeOrders->get($correctedCode);
                    $typeOrderId = $typeOrder->id;
                    $typeOrderName = $typeOrder->type_order_name;
                } else {
                    $typeOrderSuggestion = $this->suggestClosestTypeOrder($row['type_order_code'], $typeOrders);
                    $preview['errors'][] = [
                        'ssid' => $row['ssid'],
                        'row' => $row['row_number'],
                        'field' => 'type_order',
                        'input' => $row['type_order_code'],
                        'suggestion' => $typeOrderSuggestion,
                        'reason' => "Type Order '{$row['type_order_code']}' tidak valid. Mungkin maksud: " . ($typeOrderSuggestion ?? 'tidak ada'),
                    ];
                }
            }

            // Resolve bandwidths
            $existingBwId = $row['existing_bw'] ? $this->findBestBandwidth($row['existing_bw'], $bandwidthOptions) : null;
            $existingBwName = $existingBwId ? ($this->findBwById($existingBwId, $bandwidthOptions)?->bandwidth_name ?? $row['existing_bw']) : $row['existing_bw'];

            $upgradeBwId = $row['upgrade_bw'] ? $this->findBestBandwidth($row['upgrade_bw'], $bandwidthOptions) : null;
            $upgradeBwName = $upgradeBwId ? ($this->findBwById($upgradeBwId, $bandwidthOptions)?->bandwidth_name ?? $row['upgrade_bw']) : $row['upgrade_bw'];

            $downgradeBwId = $row['downgrade_bw'] ? $this->findBestBandwidth($row['downgrade_bw'], $bandwidthOptions) : null;
            $downgradeBwName = $downgradeBwId ? ($this->findBwById($downgradeBwId, $bandwidthOptions)?->bandwidth_name ?? $row['downgrade_bw']) : $row['downgrade_bw'];

            // Bandwidth validation errors
            if ($row['existing_bw'] && !$existingBwId) {
                $preview['errors'][] = [
                    'ssid' => $row['ssid'],
                    'row' => $row['row_number'],
                    'field' => 'existing_bw',
                    'input' => $row['existing_bw'],
                    'reason' => "Bandwidth '{$row['existing_bw']}' tidak ditemukan di master bandwidth",
                ];
            }
            if ($row['upgrade_bw'] && !$upgradeBwId) {
                $preview['errors'][] = [
                    'ssid' => $row['ssid'],
                    'row' => $row['row_number'],
                    'field' => 'upgrade_bw',
                    'input' => $row['upgrade_bw'],
                    'reason' => "Bandwidth UPGRADE '{$row['upgrade_bw']}' tidak ditemukan di master bandwidth",
                ];
            }
            if ($row['downgrade_bw'] && !$downgradeBwId) {
                $preview['errors'][] = [
                    'ssid' => $row['ssid'],
                    'row' => $row['row_number'],
                    'field' => 'downgrade_bw',
                    'input' => $row['downgrade_bw'],
                    'reason' => "Bandwidth DOWNGRADE '{$row['downgrade_bw']}' tidak ditemukan di master bandwidth",
                ];
            }

            // Build current state from DB
            $currentTypeOrderName = $line->typeOrder?->type_order_name;
            $currentExistingBwName = $line->existingBandwidth?->bandwidth_name;
            $currentUpgradeBwName = $line->upgradeBandwidth?->bandwidth_name;
            $currentDowngradeBwName = $line->downgradeBandwidth?->bandwidth_name;
            $currentEffectiveDate = $line->effective_date ? $line->effective_date->format('d/m/Y') : null;

            // Detect changes
            $changes = [];
            $hasChanges = false;

            if ($typeOrderId !== null && $line->type_order_id !== $typeOrderId) {
                $changes['type_order'] = [
                    'old' => $currentTypeOrderName ?? '(kosong)',
                    'new' => $typeOrderName,
                    'new_id' => $typeOrderId,
                ];
                $hasChanges = true;
            }
            if ($row['effective_date_str'] && $row['effective_date_str'] !== $currentEffectiveDate) {
                $changes['effective_date'] = [
                    'old' => $currentEffectiveDate ?? '(kosong)',
                    'new' => $row['effective_date_str'],
                    'new_value' => $effectiveDate ?? null,
                ];
                $hasChanges = true;
            }
            if ($existingBwId !== null && $line->existing_bandwidth_option_id !== $existingBwId) {
                $changes['existing_bw'] = [
                    'old' => $currentExistingBwName ?? '(kosong)',
                    'new' => $existingBwName,
                    'new_id' => $existingBwId,
                ];
                $hasChanges = true;
            }
            if ($upgradeBwId !== null && $line->upgrade_bandwidth_option_id !== $upgradeBwId) {
                $changes['upgrade_bw'] = [
                    'old' => $currentUpgradeBwName ?? '(kosong)',
                    'new' => $upgradeBwName,
                    'new_id' => $upgradeBwId,
                ];
                $hasChanges = true;
            }
            if ($downgradeBwId !== null && $line->downgrade_bandwidth_option_id !== $downgradeBwId) {
                $changes['downgrade_bw'] = [
                    'old' => $currentDowngradeBwName ?? '(kosong)',
                    'new' => $downgradeBwName,
                    'new_id' => $downgradeBwId,
                ];
                $hasChanges = true;
            }

            $item = [
                'ssid' => $row['ssid'],
                'row' => $row['row_number'],
                'line_id' => $line->id,
                'changes' => $changes,
                'type_order_id' => $typeOrderId,
                'effective_date' => $row['effective_date']?->format('Y-m-d'),
                'existing_bw_id' => $existingBwId,
                'upgrade_bw_id' => $upgradeBwId,
                'downgrade_bw_id' => $downgradeBwId,
                'current' => [
                    'type_order' => $currentTypeOrderName,
                    'effective_date' => $currentEffectiveDate,
                    'existing_bw' => $currentExistingBwName,
                    'upgrade_bw' => $currentUpgradeBwName,
                    'downgrade_bw' => $currentDowngradeBwName,
                ],
            ];

            if ($hasChanges) {
                $preview['changed'][] = $item;
            } else {
                $preview['unchanged'][] = $item;
            }
        }

        return $preview;
    }

    /**
     * Suggest closest type order code from available options.
     */
    private function suggestClosestTypeOrder(string $input, $typeOrders): ?string
    {
        $normInput = $this->strNorm($input);
        $best = null;
        $bestDist = PHP_INT_MAX;

        foreach ($typeOrders as $code => $order) {
            foreach ([$this->strNorm($code), $this->strNorm($order->type_order_name ?? '')] as $candidate) {
                if ($candidate === '') continue;
                if (abs(strlen($candidate) - strlen($normInput)) > 4) continue;
                $dist = $this->levenshtein($normInput, $candidate);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $code;
                }
            }
        }

        return $bestDist <= 4 ? $best : null;
    }

    /**
     * Apply a single step2 row update to the database.
     */
    private function applyStep2Row($invoicePeriod, array $item, $typeOrders, $bandwidthOptions): array
    {
        $line = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->where('id', $item['line_id'])
            ->first();

        if (!$line) {
            return ['success' => false, 'error' => "SSID '{$item['ssid']}' tidak ditemukan di periode ini"];
        }

        $updateData = [];
        if (isset($item['type_order_id']) && $item['type_order_id']) {
            $updateData['type_order_id'] = $item['type_order_id'];
        }
        if (isset($item['effective_date']) && $item['effective_date']) {
            $updateData['effective_date'] = $item['effective_date'];
        }
        if (isset($item['existing_bw_id']) && $item['existing_bw_id']) {
            $updateData['existing_bandwidth_option_id'] = $item['existing_bw_id'];
        }
        if (isset($item['upgrade_bw_id']) && $item['upgrade_bw_id']) {
            $updateData['upgrade_bandwidth_option_id'] = $item['upgrade_bw_id'];
        }
        if (isset($item['downgrade_bw_id']) && $item['downgrade_bw_id']) {
            $updateData['downgrade_bandwidth_option_id'] = $item['downgrade_bw_id'];
        }

        if (!empty($updateData)) {
            $line->update($updateData);
        }

        return ['success' => true];
    }

    /**
     * Parse date value from Excel — handles serial number, DateTime object, and string
     */
    private function parseExcelDate($value)
    {
        if (empty($value) || $value === '-' || $value === '') return null;

        // Already a DateTime object
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        // Numeric = Excel serial date number (e.g., 45662 = 2025-01-05)
        if (is_numeric($value)) {
            try {
                $serial = (int) $value;
                // Validate: serial dates are > 0 and < 100000
                if ($serial > 0 && $serial < 100000) {
                    return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($serial));
                }
            } catch (\Exception $e) {
                // Fall through to string parsing
            }
        }

        // String — try common formats
        $stringVal = trim((string) $value);
        if (empty($stringVal) || $stringVal === '-') return null;

        $formats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y'];
        foreach ($formats as $format) {
            $dt = Carbon::createFromFormat($format, $stringVal);
            if ($dt !== false) {
                return $dt;
            }
        }

        // Last resort: Carbon parse
        try {
            return Carbon::parse($stringVal);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Process single Step 2 row
     */
    private function processStep2Row($invoicePeriod, $ssid, $typeOrderCode, $effectiveDate, $existingBw, $upgradeBw, $downgradeBw, $typeOrders, $bandwidthOptions)
    {
        // Find invoice line by SSID — try string AND integer
        $line = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->whereHas('bankJatimLink', fn($q) => $q->where('ssid', $ssid))
            ->first();

        if (!$line) {
            // Try integer lookup
            $line = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                ->whereHas('bankJatimLink', fn($q) => $q->where('ssid', (int) $ssid))
                ->first();
        }

        if (!$line) {
            return ['success' => false, 'error' => "SSID '{$ssid}' tidak ditemukan di periode ini"];
        }

        // Find type order — typo-tolerant
        $typeOrder = null;
        if ($typeOrderCode) {
            $correctedCode = $this->findBestTypeOrder($typeOrderCode, $typeOrders);
            if (!$correctedCode) {
                return ['success' => false, 'error' => "Type Order '{$typeOrderCode}' tidak valid. Contoh: " . implode(', ', $typeOrders->keys()->toArray())];
            }
            $typeOrder = $correctedCode ? $typeOrders->get($correctedCode) : null;
        }

        // Find bandwidth options — typo-tolerant
        $existingBwId = $existingBw ? $this->findBestBandwidth($existingBw, $bandwidthOptions) : null;
        $upgradeBwId = $upgradeBw ? $this->findBestBandwidth($upgradeBw, $bandwidthOptions) : null;
        $downgradeBwId = $downgradeBw ? $this->findBestBandwidth($downgradeBw, $bandwidthOptions) : null;

        if ($existingBw && !$existingBwId) {
            \Illuminate\Support\Facades\Log::warning('IMPORT_BW_NOT_FOUND', ['input' => $existingBw, 'ssid' => $ssid]);
        }
        if ($upgradeBw && !$upgradeBwId) {
            \Illuminate\Support\Facades\Log::warning('IMPORT_BW_NOT_FOUND', ['input' => $upgradeBw, 'ssid' => $ssid]);
        }
        if ($downgradeBw && !$downgradeBwId) {
            \Illuminate\Support\Facades\Log::warning('IMPORT_BW_NOT_FOUND', ['input' => $downgradeBw, 'ssid' => $ssid]);
        }

        // Build update data
        $updateData = [];
        if ($typeOrder) $updateData['type_order_id'] = $typeOrder->id;
        if ($effectiveDate) $updateData['effective_date'] = $effectiveDate;
        if ($existingBwId) $updateData['existing_bandwidth_option_id'] = $existingBwId;
        if ($upgradeBwId) $updateData['upgrade_bandwidth_option_id'] = $upgradeBwId;
        if ($downgradeBwId) $updateData['downgrade_bandwidth_option_id'] = $downgradeBwId;

        if (!empty($updateData)) {
            $line->update($updateData);
        }

        return [
            'success' => true,
            'data' => [
                'ssid' => $ssid,
                'type_order' => $typeOrder ? $typeOrder->type_order_name : '(kosong)',
                'date' => $effectiveDate ? $effectiveDate->format('d/m/Y') : '(kosong)',
                'existing_bw' => $existingBwId ? ($this->findBwById($existingBwId, $bandwidthOptions)?->bandwidth_name ?? $existingBw) : '(kosong)',
                'upgrade_bw' => $upgradeBwId ? ($this->findBwById($upgradeBwId, $bandwidthOptions)?->bandwidth_name ?? $upgradeBw) : '(kosong)',
                'downgrade_bw' => $downgradeBwId ? ($this->findBwById($downgradeBwId, $bandwidthOptions)?->bandwidth_name ?? $downgradeBw) : '(kosong)',
            ]
        ];
    }

    private function findBwById($id, $bandwidthOptions)
    {
        return $bandwidthOptions->first(fn($i, $item) => $item->id === $id);
    }

    private function findToByCode($code, $typeOrders)
    {
        return $typeOrders->first(fn($i, $item) => $item->type_order_code === $code);
    }

    /**
     * Update Step 2 inline data (AJAX)
     */
    public function updateStep2Data(Request $request, InvoicePeriod $invoicePeriod, InvoiceLine $invoiceLine)
    {
        // Validate this line belongs to this period
        if ($invoiceLine->invoice_period_id !== $invoicePeriod->id) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid untuk periode ini'
            ], 403);
        }

        $updateData = [];

        // Type Order
        if ($request->has('type_order_id')) {
            $typeOrderId = $request->type_order_id;
            if ($typeOrderId === '' || $typeOrderId === null) {
                $updateData['type_order_id'] = null;
                // Also clear bandwidth if type order is cleared
                $updateData['upgrade_bandwidth_option_id'] = null;
                $updateData['downgrade_bandwidth_option_id'] = null;
            } else {
                $typeOrder = TypeOrder::find($typeOrderId);
                if (!$typeOrder) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Type Order tidak valid'
                    ], 400);
                }
                $updateData['type_order_id'] = $typeOrderId;
            }
        }

        // Tanggal efektif
        if ($request->has('effective_date')) {
            $effectiveDate = $request->effective_date;
            if ($effectiveDate === '' || $effectiveDate === null) {
                $updateData['effective_date'] = null;
            } else {
                try {
                    $updateData['effective_date'] = Carbon::parse($effectiveDate);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Format tanggal tidak valid'
                    ], 400);
                }
            }
        }

        // Existing Bandwidth
        if ($request->has('existing_bandwidth_option_id')) {
            $bwId = $request->existing_bandwidth_option_id;
            $updateData['existing_bandwidth_option_id'] = ($bwId === '' || $bwId === null) ? null : (int) $bwId;
        }

        // Upgrade Bandwidth
        if ($request->has('upgrade_bandwidth_option_id')) {
            $bwId = $request->upgrade_bandwidth_option_id;
            $updateData['upgrade_bandwidth_option_id'] = ($bwId === '' || $bwId === null) ? null : (int) $bwId;
        }

        // Downgrade Bandwidth
        if ($request->has('downgrade_bandwidth_option_id')) {
            $bwId = $request->downgrade_bandwidth_option_id;
            $updateData['downgrade_bandwidth_option_id'] = ($bwId === '' || $bwId === null) ? null : (int) $bwId;
        }

        if (!empty($updateData)) {
            $invoiceLine->update($updateData);
            $invoiceLine->refresh();
        }

        // Determine if needsUpgrade
        $needsUpgrade = false;
        if ($invoiceLine->typeOrder) {
            $needsUpgrade = $invoiceLine->typeOrder->needsNewBandwidth();
        }

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil disimpan',
            'needsUpgrade' => $needsUpgrade,
            'isComplete' => $invoiceLine->isTypeOrderComplete(),
        ]);
    }

    /**
     * Complete Step 2
     */
    public function completeStep2(Request $request, InvoicePeriod $invoicePeriod)
    {
        // Validate all SSIDs have type order
        $invoiceLines = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->get();
        $emptyCount = $invoiceLines->whereNull('type_order_id')->count();

        if ($emptyCount > 0) {
            return back()->with('error', "Masih ada {$emptyCount} SSID yang belum memiliki Type Order!");
        }

        // Mark step as completed
        $step = InvoiceStep::where('step_code', InvoicePeriod::STEP_TYPE_ORDER)->first();
        if ($step) {
            $stepStatus = InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
                ->where('invoice_step_id', $step->id)
                ->first();

            if ($stepStatus) {
                $stepStatus->update([
                    'status' => InvoiceStepStatus::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            }
        }

        // Recalculate progress
        $this->recalculateProgress($invoicePeriod);

        return redirect()->route('invoice-periods.step', ['invoicePeriod' => $invoicePeriod, 'step' => 3])
            ->with('success', 'Step 2 berhasil diselesaikan!');
    }

    /**
     * Update Step 2 Progress
     */
    private function updateStep2Progress(InvoicePeriod $invoicePeriod)
    {
        $step = InvoiceStep::where('step_code', InvoicePeriod::STEP_TYPE_ORDER)->first();
        if (!$step) return;

        $stepStatus = InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
            ->where('invoice_step_id', $step->id)
            ->first();

        if (!$stepStatus) {
            $stepStatus = InvoiceStepStatus::create([
                'invoice_period_id' => $invoicePeriod->id,
                'invoice_step_id' => $step->id,
                'status' => InvoiceStepStatus::STATUS_IN_PROGRESS,
                'error_count' => 0,
                'warning_count' => 0,
            ]);
        } elseif ($stepStatus->status === InvoiceStepStatus::STATUS_NOT_STARTED) {
            $stepStatus->update([
                'status' => InvoiceStepStatus::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
        }

        // Update error/warning counts
        $invoiceLines = InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->get();
        $emptyCount = $invoiceLines->whereNull('type_order_id')->count();
        $incompleteCount = $invoiceLines->filter(fn($l) => $l->type_order_id && !$l->isTypeOrderComplete())->count();

        $stepStatus->update([
            'error_count' => $emptyCount,
            'warning_count' => $incompleteCount,
        ]);
    }

    /**
     * Download bandwidth import format - Template untuk SSID di periode ini
     */
    public function downloadBandwidthFormat(InvoicePeriod $invoicePeriod)
    {
        // Get SSIDs dari Step 1
        $invoiceLines = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service', 'bankJatimLink.currentBandwidth'])
            ->orderBy('line_number')
            ->get();

        $typeOrders = \App\Models\TypeOrder::active()->ordered()->get();
        $bandwidthOptions = \App\Models\BandwidthOption::active()->ordered()->get();

        // Generate CSV content
        $csvContent = $this->generateBandwidthCsvFormatForPeriod($invoiceLines, $typeOrders, $bandwidthOptions, $invoicePeriod);

        $fileName = 'type_order_bandwidth_' . $invoicePeriod->period_code . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return response($csvContent, 200, $headers);
    }

    /**
     * Generate CSV format for bandwidth import - khusus SSID di periode ini
     */
    private function generateBandwidthCsvFormatForPeriod($invoiceLines, $typeOrders, $bandwidthOptions, $period)
    {
        $lines = [];

        // Header info
        $lines[] = 'TYPE ORDER & BANDWIDTH IMPORT - ' . $period->period_code;
        $lines[] = 'Periode Folder Kerja: ' . $period->period_code;
        $lines[] = 'Generated: ' . now()->format('d/m/Y H:i:s');
        $lines[] = 'Total SSID: ' . $invoiceLines->count();
        $lines[] = '';
        $lines[] = '=== TYPE ORDERS ===';
        foreach ($typeOrders as $to) {
            $lines[] = $to->type_order_code . ' - ' . $to->type_order_name;
        }
        $lines[] = '';
        $lines[] = '=== BANDWIDTH OPTIONS ===';
        foreach ($bandwidthOptions as $bo) {
            $lines[] = $bo->bandwidth_name;
        }
        $lines[] = '';
        $lines[] = '=== ATURAN ===';
        $lines[] = 'EXISTING: Isi kolom EXISTING saja';
        $lines[] = 'UPGRADE: Isi EXISTING + UPGRADE';
        $lines[] = 'DOWNGRADE: Isi EXISTING + DOWNGRADE';
        $lines[] = 'PSB: Isi EXISTING saja';
        $lines[] = 'PEMUTUSAN: Isi EXISTING saja';
        $lines[] = 'RELOKASI: Isi EXISTING saja';
        $lines[] = '';
        $lines[] = '=== DATA (SALIN KE BAWAH INI) ===';
        $lines[] = 'NO;JENIS KANTOR;ALAMAT;LAYANAN;SSID;TYPE ORDER;TGL PERUBAHAN;EXISTING;UPGRADE;DOWNGRADE';

        // Data rows - dari invoice_lines periode ini
        $no = 1;
        foreach ($invoiceLines as $line) {
            $bankLink = $line->bankJatimLink;
            $officeType = $bankLink->officeType->name ?? '-';
            $address = str_replace(["\n", "\r"], ' ', $bankLink->address ?? '-');
            $service = $bankLink->service->name ?? '-';
            $ssid = $bankLink->ssid ?? '-';

            // Format: NO;JENIS KANTOR;ALAMAT;LAYANAN;SSID;TYPE ORDER;TGL PERUBAHAN;EXISTING;UPGRADE;DOWNGRADE
            $lines[] = $no . ';' . $officeType . ';' . $address . ';' . $service . ';' . $ssid . ';;;;;;';
            $no++;
        }

        return implode("\n", $lines);
    }

    /**
     * Import bandwidth data from paste/JSON or file
     */
    public function importBandwidthData(Request $request, InvoicePeriod $invoicePeriod)
    {
        $typeOrders = \App\Models\TypeOrder::active()->get()->keyBy('type_order_code');
        $bandwidthOptions = \App\Models\BandwidthOption::active()->get()->keyBy(function($item) {
            return strtolower($item->bandwidth_name);
        });
        $bankLinks = \App\Models\BankJatimLink::all()->keyBy('ssid');

        // Check if data comes from paste (JSON)
        if ($request->has('data')) {
            return $this->importFromPaste($request, $invoicePeriod, $typeOrders, $bandwidthOptions, $bankLinks);
        }

        // Otherwise, handle file upload
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls',
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());
        $rows = array_filter(explode("\n", $content), fn($r) => trim($r) !== '');

        // Find data start (after === DATA === marker)
        $dataStartIndex = 0;
        foreach ($rows as $i => $row) {
            if (strpos($row, '=== DATA') !== false) {
                $dataStartIndex = $i + 1;
                break;
            }
        }

        $results = [
            'success' => [],
            'errors' => [],
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
        ];

        // Parse data rows
        for ($i = $dataStartIndex; $i < count($rows); $i++) {
            $row = trim($rows[$i]);
            if (empty($row) || strpos($row, 'NO;') !== false) continue;

            $parts = explode(';', $row);
            if (count($parts) < 9) continue;

            $ssid = trim($parts[4]);  // SSID ada di kolom ke-5
            $typeOrderCode = trim($parts[5]);  // TYPE ORDER di kolom ke-6
            $tglPerubahan = trim($parts[6]);  // TGL PERUBAHAN di kolom ke-7
            $existingBw = trim($parts[7]);  // EXISTING di kolom ke-8
            $upgradeBw = trim($parts[8]);  // UPGRADE di kolom ke-9
            $downgradeBw = isset($parts[9]) ? trim($parts[9]) : '';  // DOWNGRADE di kolom ke-10

            $processResult = $this->processBandwidthRowFromPaste(
                $invoicePeriod, $ssid, $typeOrderCode, $tglPerubahan,
                $existingBw, $upgradeBw, $downgradeBw,
                $typeOrders, $bandwidthOptions, $bankLinks
            );

            if ($processResult['success']) {
                $results['success'][] = $processResult['data'];
                $results['valid']++;
            } else {
                $results['errors'][] = $processResult['error'];
                $results['invalid']++;
            }
            $results['total']++;
        }

        return response()->json([
            'success' => $results['invalid'] === 0,
            'message' => "Import selesai. {$results['valid']} berhasil, {$results['invalid']} gagal.",
            'results' => $results,
        ]);
    }

    /**
     * Import from paste (JSON data)
     */
    private function importFromPaste(Request $request, InvoicePeriod $invoicePeriod, $typeOrders, $bandwidthOptions, $bankLinks)
    {
        $data = $request->input('data', []);
        $results = [
            'success' => [],
            'errors' => [],
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
        ];

        foreach ($data as $row) {
            $ssid = trim($row['ssid'] ?? '');
            $typeOrderCode = trim($row['type_order_code'] ?? '');
            $tglPerubahan = trim($row['tgl_perubahan'] ?? '');
            $existingBw = trim($row['existing_bw'] ?? '');
            $upgradeBw = trim($row['upgrade_bw'] ?? '');
            $downgradeBw = trim($row['downgrade_bw'] ?? '');

            $processResult = $this->processBandwidthRowFromPaste(
                $invoicePeriod, $ssid, $typeOrderCode, $tglPerubahan,
                $existingBw, $upgradeBw, $downgradeBw,
                $typeOrders, $bandwidthOptions, $bankLinks
            );

            if ($processResult['success']) {
                $results['success'][] = $processResult['data'];
                $results['valid']++;
            } else {
                $results['errors'][] = $processResult['error'];
                $results['invalid']++;
            }
            $results['total']++;
        }

        return response()->json([
            'success' => $results['invalid'] === 0,
            'message' => "Import selesai. {$results['valid']} berhasil, {$results['invalid']} gagal.",
            'results' => $results,
        ]);
    }

    /**
     * Process single bandwidth row
     */
    private function processBandwidthRowFromPaste($invoicePeriod, $ssid, $typeOrderCode, $tglPerubahan, $existingBw, $upgradeBw, $downgradeBw, $typeOrders, $bandwidthOptions, $bankLinks)
    {
        // Find bank link by SSID
        $link = $bankLinks->get($ssid);
        if (!$link) {
            return ['success' => false, 'error' => "SSID '{$ssid}' tidak ditemukan di master"];
        }

        // Find type order
        $typeOrder = $typeOrders->get(strtoupper($typeOrderCode));
        if (!$typeOrder) {
            return ['success' => false, 'error' => "Type Order '{$typeOrderCode}' tidak valid untuk SSID '{$ssid}'"];
        }

        // Find bandwidth options by name
        $existingBwId = $this->findBandwidthId($existingBw, $bandwidthOptions);
        $upgradeBwId = $this->findBandwidthId($upgradeBw, $bandwidthOptions);
        $downgradeBwId = $this->findBandwidthId($downgradeBw, $bandwidthOptions);

        // Parse tanggal perubahan
        $effectiveDate = null;
        if ($tglPerubahan && $tglPerubahan !== '-') {
            try {
                $effectiveDate = \Carbon\Carbon::parse($tglPerubahan);
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        // Find invoice line for this period and SSID
        $line = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
            ->where('bank_jatim_link_id', $link->id)
            ->first();

        if (!$line) {
            return ['success' => false, 'error' => "SSID '{$ssid}' tidak ditemukan di periode ini"];
        }

        // Update invoice line with type order
        $line->update([
            'type_order_id' => $typeOrder->id,
            'effective_date' => $effectiveDate ?? $invoicePeriod->start_date,
        ]);

        // Create/update bandwidth child record
        \App\Models\InvoiceBandwidth::updateOrCreate(
            ['invoice_line_id' => $line->id],
            [
                'existing_bw_id' => $existingBwId,
                'upgrade_bw_id' => $upgradeBwId,
                'downgrade_bw_id' => $downgradeBwId,
            ]
        );

        return [
            'success' => true,
            'data' => [
                'ssid' => $ssid,
                'type_order' => $typeOrder->type_order_name,
            ]
        ];
    }

    /**
     * Find bandwidth option ID by name/speed
     */
    private function findBandwidthId($bandwidthText, $bandwidthOptions)
    {
        if (!$bandwidthText || $bandwidthText === '-') return null;

        $search = strtolower(trim($bandwidthText));

        // Check if already a numeric ID
        if (is_numeric($search)) {
            return (int) $search;
        }

        foreach ($bandwidthOptions as $option) {
            $name = strtolower($option->bandwidth_name ?? '');
            $speed = strtolower($option->bandwidth_speed ?? '');

            if (str_contains($name, $search) || str_contains($speed, $search)) {
                return $option->id;
            }
        }

        return null;
    }

    /**
     * Normalize string untuk typo-tolerant matching:
     *   - lowercase
     *   - trim spasi di kedua ujung
     *   - ganti underscore (_) dan dash (-) jadi spasi
     *   - hapus semua karakter non-alphanumeric dan non-spasi
     *   - collapse multiple spasi jadi satu
     *
     * Contoh:
     *   'EKSISTING'  -> 'eksisting'
     *   'pasang_baru' -> 'pasang baru'
     *   '10 Mbps'    -> '10mbps'
     *   '10Mbps'     -> '10mbps'
     */
    /**
     * Normalize string untuk fuzzy matching — konsisten untuk semua lookup.
     * Hapus semua non-alphanumeric, lowercase, spasi collapse.
     */
    private function strNorm(string $v): string
    {
        $v = strtolower(trim($v));
        // Hapus semua karakter non-alphanumeric (termasuk spasi, strip, underscore, titik, dll)
        $v = preg_replace('/[^a-z0-9]+/s', '', $v);
        return $v;
    }

    private function strNormKeepSpace(string $v): string
    {
        $v = strtolower(trim($v));
        $v = str_replace(['_', '-'], ' ', $v);
        $v = preg_replace('/[^a-z0-9 ]+/s', '', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        return trim($v);
    }

    /**
     * Compute Levenshtein distance (edit distance) antara dua string.
     * Dipakai untuk fuzzy match typo seperti 'eksisting' vs 'existing'.
     */
    private function levenshtein(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA === 0) return $lenB;
        if ($lenB === 0) return $lenA;

        $prev = range(0, $lenB);
        $curr = [];
        for ($i = 1; $i <= $lenA; $i++) {
            $curr[0] = $i;
            for ($j = 1; $j <= $lenB; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $curr[$j] = min(
                    $curr[$j - 1] + 1,        // insertion
                    $prev[$j] + 1,           // deletion
                    $prev[$j - 1] + $cost    // substitution
                );
            }
            $prev = $curr;
        }
        return $prev[$lenB];
    }

    /**
     * Cari type_order_code paling cocok dari master dengan typo-tolerant matching.
     *
     * Strategi 2 langkah:
     *   1. Normalize input + normalize semua kandidat master, lalu exact match.
     *   2. Kalau belum ketemu, fuzzy match dengan Levenshtein threshold
     *      - distance <= 2 untuk kata dengan panjang >= 6
     *      - distance <= 1 untuk kata dengan panjang 3-5
     *      - distance <= 0 (==exact) untuk kata dengan panjang <= 2
     *
     * Return canonical type_order_code kalau ketemu, atau null kalau tidak.
     *
     * Contoh:
     *   findBestTypeOrder('eksisting', ...)   -> 'existing'
     *   findBestTypeOrder('PASANG_BARU', ...) -> 'pasang_baru'
     *   findBestTypeOrder('pasang baru', ...) -> 'pasang_baru'
     *   findBestTypeOrder('downgrae', ...)    -> 'downgrade' (jika master berisi)
     */
    private function findBestTypeOrder(?string $input, $typeOrders): ?string
    {
        if ($input === null || trim($input) === '') return null;

        $normInput = $this->strNorm($input);

        foreach ($typeOrders as $code => $order) {
            if ($this->strNorm($code) === $normInput
                || $this->strNorm($order->type_order_name ?? '') === $normInput) {
                return $code;
            }
        }

        $bestCode = null;
        $bestDist = PHP_INT_MAX;
        foreach ($typeOrders as $code => $order) {
            foreach ([$this->strNorm($code), $this->strNorm($order->type_order_name ?? '')] as $candidate) {
                if ($candidate === '') continue;
                if (abs(strlen($candidate) - strlen($normInput)) > 4) continue;

                $dist = $this->levenshtein($normInput, $candidate);
                if ($dist <= 2 && $dist < $bestDist) {
                    $bestDist = $dist;
                    $bestCode = $code;
                }
            }
        }

        return $bestCode;
    }

    private function findBestBandwidth(?string $input, $bandwidthOptions): ?int
    {
        if ($input === null || trim($input) === '' || $input === '-') return null;

        if (is_numeric(trim($input))) {
            return $this->findBandwidthId($input, $bandwidthOptions);
        }

        $normInput = $this->strNorm($input);

        foreach ($bandwidthOptions as $option) {
            if ($this->strNorm($option->bandwidth_name ?? '') === $normInput) {
                return $option->id;
            }
        }

        $bestId = null;
        $bestDist = PHP_INT_MAX;
        foreach ($bandwidthOptions as $option) {
            $nameNorm = $this->strNorm($option->bandwidth_name ?? '');
            if ($nameNorm === '') continue;
            if (abs(strlen($nameNorm) - strlen($normInput)) > 5) continue;

            $dist = $this->levenshtein($normInput, $nameNorm);
            if ($dist <= 3 && $dist < $bestDist) {
                $bestDist = $dist;
                $bestId = $option->id;
            }
        }

        return $bestId;
    }

    /**
     * Remove SSID from period only (not from database master)
     */
    public function removeSsidFromPeriod(Request $request, InvoicePeriod $invoicePeriod)
    {
        $request->validate([
            'line_id' => 'required_without:ssid_ids|integer|exists:invoice_lines,id',
            'ssid_ids' => 'required_without:line_id|array',
            'ssid_ids.*' => 'required|exists:invoice_lines,id',
        ]);

        DB::beginTransaction();
        try {
            $idsToDelete = $request->line_id ? [$request->line_id] : $request->ssid_ids;

            $deletedCount = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                ->whereIn('id', $idsToDelete)
                ->delete();

            // Renumber line numbers
            $remainingLines = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)
                ->orderBy('line_number')
                ->get();

            $lineNum = 1;
            foreach ($remainingLines as $line) {
                $line->update(['line_number' => $lineNum]);
                $lineNum++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$deletedCount} SSID berhasil dihapus dari periode ini",
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus SSID: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear all SSID data from this period (for reimport)
     */
    public function clearSsidData(InvoicePeriod $invoicePeriod)
    {
        DB::beginTransaction();
        try {
            $deletedCount = \App\Models\InvoiceLine::where('invoice_period_id', $invoicePeriod->id)->delete();

            // Clear related step data
            $this->clearRelatedStepData($invoicePeriod->id);

            // Reset step ssid_list jadi in_progress
            $ssidListStep = \App\Models\InvoiceStep::where('step_code', 'ssid_list')->first();
            if ($ssidListStep) {
                $stepStatus = \App\Models\InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
                    ->where('invoice_step_id', $ssidListStep->id)
                    ->first();
                if ($stepStatus) {
                    $stepStatus->update([
                        'status' => \App\Models\InvoiceStepStatus::STATUS_IN_PROGRESS,
                        'completed_at' => null,
                        'completed_by' => null,
                    ]);
                }
            }

            $this->resetSubsequentSteps($invoicePeriod);
            $invoicePeriod->update(['current_step_code' => 'ssid_list']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Data berhasil direset.",
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal mereset data: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Hapus data related step (bandwidth, traffic, price, sla, restitution, dll)
     */
    private function clearRelatedStepData(int $periodId)
    {
        \App\Models\InvoiceBandwidth::whereHas('invoiceLine', function ($q) use ($periodId) {
            $q->where('invoice_period_id', $periodId);
        })->delete();

        \App\Models\InvoiceTrafficUsage::whereHas('invoiceLine', function ($q) use ($periodId) {
            $q->where('invoice_period_id', $periodId);
        })->delete();

        \App\Models\InvoicePrice::whereHas('invoiceLine', function ($q) use ($periodId) {
            $q->where('invoice_period_id', $periodId);
        })->delete();

        \App\Models\InvoiceSla::whereHas('invoiceLine', function ($q) use ($periodId) {
            $q->where('invoice_period_id', $periodId);
        })->delete();

        \App\Models\InvoiceRestitution::whereHas('invoiceLine', function ($q) use ($periodId) {
            $q->where('invoice_period_id', $periodId);
        })->delete();

        \App\Models\InvoiceSummary::where('invoice_period_id', $periodId)->delete();
        \App\Models\InvoiceOutput::where('invoice_period_id', $periodId)->delete();

        \App\Models\InvoiceLine::where('invoice_period_id', $periodId)
            ->update([
                'type_order_id' => null,
                'effective_date' => null,
            ]);
    }

    /**
     * Reset semua step setelah ssid_list ke not_started
     */
    private function resetSubsequentSteps(InvoicePeriod $invoicePeriod)
    {
        $ssidListStep = \App\Models\InvoiceStep::where('step_code', 'ssid_list')->first();
        if (!$ssidListStep) return;

        $subsequentSteps = \App\Models\InvoiceStep::where('is_active', true)
            ->where('step_number', '>', $ssidListStep->step_number)
            ->get();

        foreach ($subsequentSteps as $step) {
            $stepStatus = \App\Models\InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
                ->where('invoice_step_id', $step->id)
                ->first();

            if ($stepStatus) {
                $stepStatus->update([
                    'status' => \App\Models\InvoiceStepStatus::STATUS_NOT_STARTED,
                    'started_at' => null,
                    'completed_at' => null,
                    'completed_by' => null,
                    'error_count' => 0,
                    'warning_count' => 0,
                ]);
            }
        }
    }

    /**
     * Show form to add multiple new SSIDs
     */
    public function ssidAddForm(Request $request, InvoicePeriod $invoicePeriod)
    {
        $ssidList = [];
        if ($request->has('ssids')) {
            $ssidList = explode(',', $request->ssids);
            $ssidList = array_filter(array_map('trim', $ssidList));
        }

        $officeTypes = \App\Models\OfficeType::active()->ordered()->get();
        $locationCategories = \App\Models\LocationCategory::active()->ordered()->get();
        $services = \App\Models\Service::active()->ordered()->get();

        return view('invoice-periods.ssid-add-form', [
            'title' => 'Tambah SSID Baru',
            'subtitle' => $invoicePeriod->period_code,
            'period' => $invoicePeriod,
            'ssidList' => $ssidList,
            'officeTypes' => $officeTypes,
            'locationCategories' => $locationCategories,
            'services' => $services,
        ]);
    }
}
