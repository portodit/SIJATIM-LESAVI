<?php

namespace App\Http\Controllers;

use App\Models\Snapshot;
use App\Models\InvoicePeriod;
use App\Models\BankJatimLink;
use App\Models\OfficeType;
use App\Models\LocationCategory;
use App\Models\Service;
use App\Models\SnapshotLocation;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SnapshotController extends Controller
{
    public function index()
    {
        // Load from InvoicePeriod instead of Snapshot
        $periods = InvoicePeriod::with('creator')
            ->active()
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->paginate(12);

        return view('snapshots.index', [
            'title' => 'PERIODE INVOICE',
            'subtitle' => 'Kelola data periode invoice Bank jatim',
            'periods' => $periods,
            'snapshots' => collect([]),
        ]);
    }

    public function index_OLD()
    {
        $snapshots = Snapshot::with('user')
            ->latest()
            ->paginate(10);

        return view('snapshots.index', [
            'title' => 'Snapshot',
            'subtitle' => 'Kelola data snapshot invoice Bank Jatim',
            'snapshots' => $snapshots,
        ]);
    }

    public function create()
    {
        return view('snapshots.create', [
            'title' => 'Buat Snapshot Baru',
            'subtitle' => 'Mulai periode billing baru',
        ]);
    }

    public function createDetail()
    {
        $pendingPeriod = session('pending_period', [
            'name' => 'TAGIHAN LAYANAN TELKOM - BANK JATIM PERIODE',
            'month' => date('n'),
            'year' => date('Y'),
            'notes' => '',
        ]);

        return view('snapshots.create-detail', [
            'title' => 'Detail Periode Invoice',
            'subtitle' => $pendingPeriod['name'] ?? 'Periode Baru',
        ]);
    }

    public function savePendingPeriod(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        // Cek apakah periode sudah ada
        if (InvoicePeriod::periodExists($request->month, $request->year)) {
            return response()->json([
                'success' => false,
                'message' => 'Periode ini sudah ada.'
            ], 422);
        }

        $bulanNama = ['', 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'];

        // Generate values
        $periodCode = InvoicePeriod::generatePeriodCode($request->month, $request->year);
        $startDate = InvoicePeriod::generateStartDate($request->month, $request->year);
        $endDate = InvoicePeriod::generateEndDate($request->month, $request->year);

        try {
            DB::beginTransaction();

            // Create invoice period
            $period = InvoicePeriod::create([
                'period_code' => $periodCode,
                'month' => $request->month,
                'year' => $request->year,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'billing_days' => 30,
                'status' => InvoicePeriod::STATUS_DRAFT,
                'current_step_code' => InvoicePeriod::STEP_OVERVIEW,
                'progress_percentage' => 0,
                'is_locked' => false,
                'created_by' => auth()->id(),
                'notes' => $request->notes ?? '',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'period_id' => $period->id,
                'period_code' => $period->period_code,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat periode: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getExistingPeriods()
    {
        $periods = InvoicePeriod::select('month', 'year')
            ->get()
            ->map(function ($p) {
                return [
                    'month' => $p->month,
                    'year' => $p->year,
                    'code' => $p->period_code,
                ];
            });

        return response()->json(['success' => true, 'periods' => $periods]);
    }

    public function showPeriodDetail($id)
    {
        $period = InvoicePeriod::findOrFail($id);

        $period->load([
            'creator',
            'finalizer',
            'stepStatuses.step',
        ]);

        // Get steps with their statuses
        $steps = \App\Models\InvoiceStep::where('is_active', true)
            ->orderBy('step_number')
            ->get()
            ->map(function ($step) use ($period) {
                $status = $period->stepStatuses->firstWhere('invoice_step_id', $step->id);
                return [
                    'step' => $step,
                    'status' => $status,
                ];
            });

        // Calculate progress
        $totalSteps = $steps->count();
        $completedSteps = $steps->filter(function ($item) {
            return $item['status'] && in_array($item['status']->status, [
                \App\Models\InvoiceStepStatus::STATUS_COMPLETED,
                \App\Models\InvoiceStepStatus::STATUS_SKIPPED
            ]);
        })->count();

        $progressPercentage = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;

        // Get previous period
        $previousPeriod = InvoicePeriod::where(function ($query) use ($period) {
            $query->where('year', '<', $period->year)
                  ->orWhere(function ($q) use ($period) {
                      $q->where('year', $period->year)
                        ->where('month', '<', $period->month);
                  });
        })
        ->where('id', '!=', $period->id)
        ->orderBy('year', 'desc')
        ->orderBy('month', 'desc')
        ->first();

        return view('snapshots.period-detail', [
            'title' => $period->period_code,
            'subtitle' => 'Periode: ' . $period->period_name,
            'period' => $period,
            'steps' => $steps,
            'progressPercentage' => $progressPercentage,
            'completedSteps' => $completedSteps,
            'totalSteps' => $totalSteps,
            'previousPeriod' => $previousPeriod,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'period_month' => 'required|integer|between:1,12',
            'period_year' => 'required|integer|min:2020|max:2099',
        ]);

        // Cek apakah periode sudah ada
        if (InvoicePeriod::periodExists($request->period_month, $request->period_year)) {
            return back()->withErrors(['period' => 'Periode ini sudah ada.']);
        }

        // Generate period code
        $periodCode = InvoicePeriod::generatePeriodCode($request->period_month, $request->period_year);

        // Buat invoice period baru
        $invoicePeriod = InvoicePeriod::create([
            'period_code' => $periodCode,
            'month' => $request->period_month,
            'year' => $request->period_year,
            'status' => InvoicePeriod::STATUS_DRAFT,
            'current_step_code' => InvoicePeriod::STEP_OVERVIEW,
            'progress_percentage' => 0,
            'is_locked' => false,
            'created_by' => auth()->id(),
            'notes' => $request->notes,
        ]);

        // Buat juga snapshot untuk backward compatibility
        $snapshot = Snapshot::create([
            'user_id' => auth()->id(),
            'name' => $request->name,
            'snapshot_code' => $periodCode,
            'period_month' => $request->period_month,
            'period_year' => $request->period_year,
            'status' => 'draft',
            'current_step' => 1,
            'step_statuses' => [],
        ]);

        return redirect()->route('invoice-periods.show', $invoicePeriod)
            ->with('success', 'Periode invoice berhasil dibuat!');
    }

    public function show(Snapshot $snapshot)
    {
        $snapshot->load(['locations', 'traffics', 'downtimes', 'invoices', 'user']);

        $steps = Snapshot::getStepDefinitions();
        $stepStatuses = $snapshot->step_statuses ?? [];
        $completedCount = $snapshot->getCompletedStepsCount();
        $totalSteps = count($steps);
        $progressPercentage = round(($completedCount / $totalSteps) * 100);

        // Get previous period snapshot
        $previousSnapshot = Snapshot::where('period_year', $snapshot->period_year)
            ->where('period_month', '<', $snapshot->period_month)
            ->where('id', '!=', $snapshot->id)
            ->orderBy('period_year', 'desc')
            ->orderBy('period_month', 'desc')
            ->first();

        if (!$previousSnapshot) {
            $previousSnapshot = Snapshot::where('period_year', '<', $snapshot->period_year)
                ->orderBy('period_year', 'desc')
                ->orderBy('period_month', 'desc')
                ->first();
        }

        return view('snapshots.show', [
            'title' => $snapshot->name,
            'subtitle' => 'Periode: ' . $snapshot->period,
            'snapshot' => $snapshot,
            'steps' => $steps,
            'stepStatuses' => $stepStatuses,
            'progressPercentage' => $progressPercentage,
            'completedCount' => $completedCount,
            'totalSteps' => $totalSteps,
            'previousSnapshot' => $previousSnapshot,
        ]);
    }

    public function edit(Snapshot $snapshot)
    {
        return view('snapshots.edit', [
            'title' => 'Edit Snapshot',
            'subtitle' => $snapshot->name,
            'snapshot' => $snapshot,
        ]);
    }

    public function update(Request $request, Snapshot $snapshot)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'period_month' => 'required|integer|between:1,12',
            'period_year' => 'required|integer|min:2020|max:2099',
            'status' => 'required|in:draft,input,validation,calculation,completed',
        ]);

        $snapshot->update($request->only(['name', 'period_month', 'period_year', 'status', 'notes']));

        return redirect()->route('snapshots.show', $snapshot)
            ->with('success', 'Snapshot berhasil diupdate!');
    }

    public function destroy(Snapshot $snapshot)
    {
        $snapshot->delete();

        return redirect()->route('snapshots.index')
            ->with('success', 'Snapshot berhasil dihapus!');
    }

    public function updateStep(Request $request, Snapshot $snapshot)
    {
        $request->validate([
            'step' => 'required|integer|between:0,9',
            'status' => 'required|in:pending,in_progress,completed,error',
        ]);

        $statuses = $snapshot->step_statuses ?? [];
        $statuses[$request->step] = $request->status;
        $snapshot->step_statuses = $statuses;
        $snapshot->current_step = $request->step + 1;
        $snapshot->save();

        return response()->json([
            'success' => true,
            'current_step' => $snapshot->current_step,
            'step_statuses' => $snapshot->step_statuses,
        ]);
    }

    // Step 1: Manage SSID List
    public function step1(Snapshot $snapshot)
    {
        $snapshot->load(['locations', 'user']);

        $stepStatuses = $snapshot->step_statuses ?? [];
        $stepStatuses[1] = $stepStatuses[1] ?? 'in_progress';
        $snapshot->step_statuses = $stepStatuses;
        $snapshot->save();

        // Get existing SSIDs - empty arrays for now (no tables yet)
        $officeTypes = collect([]);
        $locationCategories = collect([]);
        $services = collect([]);

        // Get existing SSIDs for this snapshot
        $locations = $snapshot->locations()->with(['officeType', 'locationCategory', 'service'])->get();

        return view('snapshots.step-1', [
            'title' => 'Sub Tahap 1.1 - Manage SSID List',
            'subtitle' => $snapshot->name,
            'snapshot' => $snapshot,
            'stepStatuses' => $stepStatuses,
            'officeTypes' => $officeTypes,
            'locationCategories' => $locationCategories,
            'services' => $services,
            'locations' => $locations,
        ]);
    }

    /**
     * Preview SSID Import - process Excel and return comparison results
     */
    public function previewSsidImport(Request $request, Snapshot $snapshot)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // 10MB max
        ]);

        try {
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (count($rows) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'File tidak memiliki data',
                ], 422);
            }

            // Skip header row
            $dataRows = array_slice($rows, 1);
            $results = [];
            $stats = [
                'new' => 0,
                'unchanged' => 0,
                'ops_change' => 0,
                'conflict' => 0,
                'duplicate' => 0,
            ];

            $seenSsids = []; // Track SSIDs in this file for duplicate detection

            DB::beginTransaction();

            foreach ($dataRows as $index => $row) {
                // Map columns: NO, JENIS KANTOR, ALAMAT, TYPE ORDER, SID, TANGGAL MULAI AKTIF LAYANAN, IP Host Telkom
                $ssid = trim((string)($row[4] ?? '')); // Column E = SSID/SID
                if (empty($ssid)) continue;

                $jenisKantor = trim((string)($row[1] ?? ''));
                $alamat = trim((string)($row[2] ?? ''));
                $kategoriLokasi = trim((string)($row[3] ?? ''));
                $tanggalMulai = $this->parseDate($row[5] ?? ''); // Column F
                $ipHost = trim((string)($row[6] ?? '')); // Column G

                // Find existing SSID in database
                $existingLink = BankJatimLink::where('ssid', $ssid)->first();

                $status = 'new';
                $existingData = null;

                // Check for duplicates in file
                if (isset($seenSsids[$ssid])) {
                    $status = 'duplicate';
                    $stats['duplicate']++;
                } else {
                    $seenSsids[$ssid] = true;

                    if ($existingLink) {
                        // SSID exists - check what changed
                        $masterDataFields = ['office_type_id', 'location_category_id', 'service_id', 'service_start_date'];
                        $opsDataFields = ['address', 'ip_host_telkom'];

                        $hasMasterDataChange = false;
                        $hasOpsChange = false;

                        // Check master data changes
                        $officeType = OfficeType::where('office_type_name', 'LIKE', '%' . $jenisKantor . '%')->first();
                        if ($officeType && $existingLink->office_type_id != $officeType->id) {
                            $hasMasterDataChange = true;
                        }

                        $locationCategory = LocationCategory::where('category_name', 'LIKE', '%' . $kategoriLokasi . '%')->first();
                        if ($locationCategory && $existingLink->location_category_id != $locationCategory->id) {
                            $hasMasterDataChange = true;
                        }

                        $service = Service::where('service_name', 'LIKE', '%' . $kategoriLokasi . '%')->first();
                        if ($service && $existingLink->service_id != $service->id) {
                            $hasMasterDataChange = true;
                        }

                        // Check ops data changes
                        if ($existingLink->address != $alamat || $existingLink->ip_host_telkom != $ipHost) {
                            $hasOpsChange = true;
                        }

                        if ($hasMasterDataChange) {
                            $status = 'conflict';
                            $stats['conflict']++;
                        } elseif ($hasOpsChange) {
                            $status = 'ops_change';
                            $stats['ops_change']++;
                        } else {
                            $status = 'unchanged';
                            $stats['unchanged']++;
                        }

                        $existingData = [
                            'id' => $existingLink->id,
                            'alamat' => $existingLink->address,
                            'ip_host_telkom' => $existingLink->ip_host_telkom,
                            'jenis_kantor' => $existingLink->officeType->office_type_name ?? '',
                            'kategori_lokasi' => $existingLink->locationCategory->category_name ?? '',
                            'layanan' => $existingLink->service->service_name ?? '',
                        ];
                    } else {
                        $stats['new']++;
                    }
                }

                $results[] = [
                    'row_number' => $index + 2,
                    'no' => trim((string)($row[0] ?? '')),
                    'jenis_kantor' => $jenisKantor,
                    'alamat' => $alamat,
                    'kategori_lokasi' => $kategoriLokasi,
                    'ssid' => $ssid,
                    'tanggal_mulai' => $tanggalMulai,
                    'ip_host_telkom' => $ipHost,
                    'status' => $status,
                    'existing_data' => $existingData,
                ];
            }

            DB::rollBack(); // Don't commit - this is just preview

            return response()->json([
                'success' => true,
                'data' => $results,
                'stats' => $stats,
                'total' => count($results),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save SSID Import - commit the import to database
     */
    public function saveSsidImport(Request $request, Snapshot $snapshot)
    {
        $request->validate([
            'data' => 'required|array',
        ]);

        try {
            DB::beginTransaction();

            $data = $request->input('data');
            $newSsids = [];
            $updatedCount = 0;

            foreach ($data as $item) {
                $ssid = trim($item['ssid']);
                $status = $item['status'];
                $action = $item['action'] ?? 'keep_existing'; // keep_existing, use_new, create_new

                if ($status === 'duplicate') {
                    continue; // Skip duplicates
                }

                if ($status === 'unchanged') {
                    continue; // No changes needed
                }

                if ($status === 'new' && $action === 'skip') {
                    continue;
                }

                if (in_array($status, ['new', 'conflict', 'ops_change']) && in_array($action, ['skip', 'keep_existing'])) {
                    continue;
                }

                // Find or create office type
                $officeType = OfficeType::firstOrCreate(
                    ['office_type_code' => $this->generateCode($item['jenis_kantor'])],
                    [
                        'office_type_name' => $item['jenis_kantor'],
                        'sort_order' => OfficeType::max('sort_order') + 1,
                        'is_active' => true,
                    ]
                );

                // Find or create location category
                $locationCategory = LocationCategory::firstOrCreate(
                    ['category_code' => $this->generateCode($item['kategori_lokasi'])],
                    [
                        'category_name' => $item['kategori_lokasi'],
                        'sort_order' => LocationCategory::max('sort_order') + 1,
                        'is_active' => true,
                    ]
                );

                // Find or create service
                $service = Service::firstOrCreate(
                    ['service_code' => $this->generateCode($item['kategori_lokasi'])],
                    [
                        'service_name' => $item['kategori_lokasi'],
                        'sort_order' => Service::max('sort_order') + 1,
                        'is_active' => true,
                    ]
                );

                // Get next sequence in office type
                $nextSeq = BankJatimLink::where('office_type_id', $officeType->id)->max('sequence_in_office_type') + 1;

                $linkData = [
                    'ssid' => $ssid,
                    'office_type_id' => $officeType->id,
                    'sequence_in_office_type' => $nextSeq,
                    'location_category_id' => $locationCategory->id,
                    'service_id' => $service->id,
                    'address' => $item['alamat'],
                    'ip_host_telkom' => $item['ip_host_telkom'],
                    'service_start_date' => $item['tanggal_mulai'],
                    'link_status' => BankJatimLink::STATUS_ACTIVE,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ];

                if ($status === 'new' || $action === 'create_new') {
                    $newSsids[] = $linkData;
                } else {
                    // Update existing
                    $existing = BankJatimLink::where('ssid', $ssid)->first();
                    if ($existing) {
                        $existing->update($linkData);
                        $updatedCount++;
                    }
                }
            }

            // Bulk insert new SSIDs
            if (!empty($newSsids)) {
                BankJatimLink::insert($newSsids);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Import berhasil',
                'stats' => [
                    'new' => count($newSsids),
                    'updated' => $updatedCount,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error saving data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download SSID Format Template
     */
    public function downloadSsidFormat(Snapshot $snapshot)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $headers = ['NO', 'JENIS KANTOR', 'ALAMAT', 'KATEGORI LOKASI', 'SID (SSID)', 'TANGGAL MULAI AKTIF LAYANAN', 'IP Host Telkom'];
        $sheet->fromArray($headers, null, 'A1');

        // Sample data from existing SSIDs
        $existingLinks = BankJatimLink::with(['officeType', 'locationCategory', 'service'])
            ->orderedForInvoice()
            ->limit(5)
            ->get();

        $rowNum = 2;
        foreach ($existingLinks as $link) {
            $sheet->setCellValue('A' . $rowNum, $link->sequence_in_office_type);
            $sheet->setCellValue('B' . $rowNum, $link->officeType->office_type_name ?? '');
            $sheet->setCellValue('C' . $rowNum, $link->address);
            $sheet->setCellValue('D' . $rowNum, $link->locationCategory->category_name ?? '');
            $sheet->setCellValue('E' . $rowNum, $link->ssid);
            $sheet->setCellValue('F' . $rowNum, $link->service_start_date ? $link->service_start_date->format('Y-m-d') : '');
            $sheet->setCellValue('G' . $rowNum, $link->ip_host_telkom);
            $rowNum++;
        }

        // Auto-size columns
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $filename = 'format_ssid_' . $snapshot->snapshot_code . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
        exit;
    }

    /**
     * Store single new SSID
     */
    public function storeSsid(Request $request, Snapshot $snapshot)
    {
        $request->validate([
            'ssid' => 'required|unique:bank_jatim_links,ssid',
            'jenis_kantor' => 'required|string',
            'alamat' => 'required|string',
            'kategori_lokasi' => 'required|string',
            'layanan' => 'required|string',
            'ip_host_telkom' => 'required|ip',
            'tanggal_mulai' => 'required|date',
        ]);

        try {
            // Find or create related records
            $officeType = OfficeType::firstOrCreate(
                ['office_type_code' => $this->generateCode($request->jenis_kantor)],
                [
                    'office_type_name' => $request->jenis_kantor,
                    'sort_order' => OfficeType::max('sort_order') + 1,
                    'is_active' => true,
                ]
            );

            $locationCategory = LocationCategory::firstOrCreate(
                ['category_code' => $this->generateCode($request->kategori_lokasi)],
                [
                    'category_name' => $request->kategori_lokasi,
                    'sort_order' => LocationCategory::max('sort_order') + 1,
                    'is_active' => true,
                ]
            );

            $service = Service::firstOrCreate(
                ['service_code' => $this->generateCode($request->layanan)],
                [
                    'service_name' => $request->layanan,
                    'sort_order' => Service::max('sort_order') + 1,
                    'is_active' => true,
                ]
            );

            // Get next sequence
            $nextSeq = BankJatimLink::where('office_type_id', $officeType->id)->max('sequence_in_office_type') + 1;

            $link = BankJatimLink::create([
                'ssid' => $request->ssid,
                'office_type_id' => $officeType->id,
                'sequence_in_office_type' => $nextSeq,
                'location_category_id' => $locationCategory->id,
                'service_id' => $service->id,
                'address' => $request->alamat,
                'ip_host_telkom' => $request->ip_host_telkom,
                'service_start_date' => $request->tanggal_mulai,
                'link_status' => BankJatimLink::STATUS_ACTIVE,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'SSID berhasil ditambahkan',
                'data' => $link,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get API data for SSID table
     */
    public function getSsidData(Snapshot $snapshot)
    {
        $links = BankJatimLink::with(['officeType', 'locationCategory', 'service'])
            ->orderedForInvoice()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $links->map(function ($link) {
                return [
                    'id' => $link->id,
                    'no' => $link->sequence_in_office_type,
                    'jenis_kantor' => $link->officeType->office_type_name ?? '',
                    'alamat' => $link->address,
                    'kategori_lokasi' => $link->locationCategory->category_name ?? '',
                    'layanan' => $link->service->service_name ?? '',
                    'ssid' => $link->ssid,
                    'ip_host_telkom' => $link->ip_host_telkom,
                    'tanggal_mulai' => $link->service_start_date ? $link->service_start_date->format('Y-m-d') : '',
                    'status' => 'unchanged',
                ];
            }),
        ]);
    }

    // Helper functions
    private function parseDate($value)
    {
        if (empty($value)) return null;
        try {
            if (is_numeric($value)) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
            }
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return $value;
        }
    }

    private function generateCode($name)
    {
        $code = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper($name));
        return substr($code, 0, 10);
    }
}
