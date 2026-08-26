<?php

namespace App\Http\Controllers;

use App\Models\InvoicePeriod;
use App\Models\InvoiceStep;
use App\Models\InvoiceStepStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InvoiceStepController extends Controller
{
    /**
     * Display the step view for a specific invoice period
     */
    public function show(InvoicePeriod $invoicePeriod, int $stepNumber)
    {
        // Get current step info
        $currentStep = InvoiceStep::where('step_number', $stepNumber)->where('is_active', true)->firstOrFail();

        // VALIDASI: Cek apakah semua step sebelumnya sudah selesai (skip overview)
        $previousSteps = InvoiceStep::where('step_number', '<', $stepNumber)
            ->where('is_active', true)
            ->where('step_code', '!=', 'overview') // Skip overview
            ->orderBy('step_number')
            ->get();

        foreach ($previousSteps as $prevStep) {
            $prevStatus = InvoiceStepStatus::where('invoice_period_id', $invoicePeriod->id)
                ->where('invoice_step_id', $prevStep->id)
                ->first();

            // Jika step sebelumnya belum completed/skipped, block akses
            if (!$prevStatus || !in_array($prevStatus->status, [InvoiceStepStatus::STATUS_COMPLETED, InvoiceStepStatus::STATUS_SKIPPED])) {
                return redirect()->route('invoice-periods.show', $invoicePeriod)
                    ->with('warning', "Anda harus menyelesaikan \"{$prevStep->step_name}\" terlebih dahulu sebelum mengakses \"{$currentStep->step_name}\".");
            }
        }

        // Get all steps for navigation
        $steps = InvoiceStep::where('is_active', true)
            ->orderBy('step_number')
            ->get();

        // Load period with step statuses
        $invoicePeriod->load(['stepStatuses.step', 'creator']);

        // Get step status for current step
        $stepStatus = $invoicePeriod->stepStatuses->firstWhere('invoice_step_id', $currentStep->id);

        // Get step content data based on step type
        $stepData = $this->getStepData($invoicePeriod, $currentStep);

        return view('invoice-periods.step', [
            'title' => $currentStep->step_name,
            'subtitle' => $invoicePeriod->period_code,
            'period' => $invoicePeriod,
            'currentStep' => $currentStep,
            'steps' => $steps,
            'stepStatus' => $stepStatus,
            'stepData' => $stepData,
            'invoiceLinesCount' => $stepData['invoiceLinesCount'] ?? 0,
        ]);
    }

    /**
     * Get data for specific step
     */
    private function getStepData(InvoicePeriod $period, InvoiceStep $step)
    {
        return match($step->step_code) {
            'overview' => $this->getOverviewData($period),
            'ssid_list' => $this->getSsidListData($period),
            'type_order' => $this->getTypeOrderData($period),
            'bandwidth' => $this->getBandwidthData($period),
            'bandwidth_benchmark' => $this->getBandwidthBenchmarkData($period),
            'traffic_usage' => $this->getTrafficData($period),
            'price' => $this->getPriceData($period),
            'sla' => $this->getSlaData($period),
            'restitution' => $this->getRestitutionData($period),
            'price_benchmark' => $this->getPriceBenchmarkData($period),
            'finalization' => $this->getFinalizationData($period),
            default => [],
        };
    }

    private function getOverviewData(InvoicePeriod $period)
    {
        return [
            'officeTypes' => \App\Models\OfficeType::active()->ordered()->get(),
            'locationCategories' => \App\Models\LocationCategory::active()->ordered()->get(),
            'services' => \App\Models\Service::active()->ordered()->get(),
        ];
    }

    private function getSsidListData(InvoicePeriod $period)
    {
        // Get invoice lines with their relations for the table
        $invoiceLines = \App\Models\InvoiceLine::where('invoice_period_id', $period->id)
            ->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service'])
            ->orderBy('line_number')
            ->get();

        $invoiceLinesCount = $invoiceLines->count();

        $mappedLines = $invoiceLines->map(function($line) {
            $officeType = $line->bankJatimLink->officeType ?? null;
            $locationCategory = $line->bankJatimLink->locationCategory ?? null;
            $service = $line->bankJatimLink->service ?? null;
            $effectiveDate = $line->effective_date ? $line->effective_date->format('Y-m-d') : null;

            return [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'ssid' => $line->bankJatimLink->ssid ?? $line->ssid_temp ?? '-',
                'is_new_ssid' => (bool) $line->is_new_ssid,
                'office_type' => $officeType ? [
                    'id' => $officeType->id,
                    'name' => $officeType->name,
                ] : ['id' => null, 'name' => '-'],
                'location_category' => $locationCategory ? [
                    'id' => $locationCategory->id,
                    'name' => $locationCategory->name,
                ] : ['id' => null, 'name' => '-'],
                'service' => $service ? [
                    'id' => $service->id,
                    'name' => $service->name,
                ] : ['id' => null, 'name' => '-'],
                'ip_host_telkom' => $line->bankJatimLink->ip_host_telkom ?? '-',
                'address' => $line->bankJatimLink->address ?? '-',
                'service_start_date' => $effectiveDate,
            ];
        })->toArray();

        // Count pending new SSIDs (is_new_ssid but office_type not set)
        $pendingNewSsidsCount = collect($mappedLines)
            ->filter(fn($item) => $item['is_new_ssid'] && ($item['office_type']['name'] === '-' || $item['office_type']['name'] === null))
            ->count();

        return [
            'officeTypes' => \App\Models\OfficeType::active()->ordered()->get(),
            'locationCategories' => \App\Models\LocationCategory::active()->ordered()->get(),
            'services' => \App\Models\Service::active()->ordered()->get(),
            'bandwidthOptions' => \App\Models\BandwidthOption::active()->ordered()->get(),
            'bankJatimLinks' => \App\Models\BankJatimLink::with(['officeType', 'locationCategory', 'service'])
                ->orderBy('office_type_id')
                ->orderBy('sequence_in_office_type')
                ->get(),
            'invoiceLinesCount' => $invoiceLinesCount,
            'invoiceLines' => $mappedLines,
            'pendingNewSsidsCount' => $pendingNewSsidsCount,
        ];
    }

    private function getTypeOrderData(InvoicePeriod $period)
    {
        $invoiceLinesCount = \App\Models\InvoiceLine::where('invoice_period_id', $period->id)->count();
        $invoiceLinesWithTypeOrderCount = \App\Models\InvoiceLine::where('invoice_period_id', $period->id)
            ->whereNotNull('type_order_id')
            ->where('type_order_id', '!=', '')
            ->count();

        // Get SSIDs from Step 1 (all invoice lines)
        $invoiceLines = \App\Models\InvoiceLine::where('invoice_period_id', $period->id)
            ->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory'])
            ->get();

        $step1Ssids = $invoiceLines
            ->map(fn($line) => [
                'id' => $line->id,
                'ssid' => $line->bankJatimLink->ssid ?? null,
                'bank_jatim_link_id' => $line->bank_jatim_link_id,
                'office_type' => $line->bankJatimLink->officeType->name ?? '-',
                'location_category' => $line->bankJatimLink->locationCategory->name ?? '-',
                'has_type_order' => $line->type_order_id !== null,
            ])
            ->filter(fn($item) => $item['ssid'] !== null)
            ->values()
            ->toArray();

        // SSIDs dari Step 1
        $step1SsidIds = collect($step1Ssids)->pluck('bank_jatim_link_id')->filter()->toArray();

        // SSIDs yang sudah ada Type Order (Step 2)
        $step2Ssids = \App\Models\InvoiceLine::where('invoice_period_id', $period->id)
            ->whereNotNull('type_order_id')
            ->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'typeOrder'])
            ->get()
            ->map(fn($line) => [
                'id' => $line->id,
                'ssid' => $line->bankJatimLink->ssid ?? null,
                'bank_jatim_link_id' => $line->bank_jatim_link_id,
                'office_type' => $line->bankJatimLink->officeType->name ?? '-',
                'location_category' => $line->bankJatimLink->locationCategory->name ?? '-',
                'type_order' => $line->typeOrder->type_order_name ?? '-',
            ])
            ->filter(fn($item) => $item['ssid'] !== null)
            ->values()
            ->toArray();

        $step2SsidIds = collect($step2Ssids)->pluck('bank_jatim_link_id')->filter()->toArray();

        // Hitung SSID TERTINGGAL (ada di Step 1 tapi belum ada Type Order)
        $missingSsidIds = array_diff($step1SsidIds, $step2SsidIds);
        $missingSsids = collect($step1Ssids)
            ->whereIn('bank_jatim_link_id', $missingSsidIds)
            ->values()
            ->toArray();

        // Hitung SSID BERLEBIH (ada di Step 2 tapi tidak ada di Step 1 - tidak mungkin dalam kondisi normal)
        // Ini bisa terjadi jika SSID dihapus manual dari Step 1
        $extraSsidIds = array_diff($step2SsidIds, $step1SsidIds);
        $extraSsids = collect($step2Ssids)
            ->whereIn('bank_jatim_link_id', $extraSsidIds)
            ->values()
            ->toArray();

        // Target count dari Step 1
        $targetCount = count($step1Ssids);
        $currentCount = count($step2Ssids);
        $isComplete = $targetCount > 0 && $currentCount == $targetCount && empty($missingSsids);

        // SSIDs untuk dropdown (yang belum ada type order)
        $availableSsids = collect($step1Ssids)
            ->whereIn('bank_jatim_link_id', $missingSsidIds)
            ->values()
            ->toArray();

        return [
            'typeOrders' => \App\Models\TypeOrder::all(),
            'invoiceLinesCount' => $invoiceLinesCount,
            'invoiceLinesWithTypeOrderCount' => $invoiceLinesWithTypeOrderCount,
            'invoiceLinesSsids' => collect($step1Ssids)->pluck('ssid')->toArray(),
            // Validasi SSID
            'targetCount' => $targetCount,
            'currentCount' => $currentCount,
            'isComplete' => $isComplete,
            'missingSsids' => $missingSsids,
            'extraSsids' => $extraSsids,
            'missingCount' => count($missingSsids),
            'extraCount' => count($extraSsids),
            'availableSsids' => $availableSsids,
        ];
    }

    private function getBandwidthData(InvoicePeriod $period)
    {
        return [
            'bandwidthOptions' => \App\Models\BandwidthOption::active()->ordered()->get(),
        ];
    }

    private function getBandwidthBenchmarkData(InvoicePeriod $period)
    {
        return [];
    }

    private function getTrafficData(InvoicePeriod $period)
    {
        return [];
    }

    private function getPriceData(InvoicePeriod $period)
    {
        return [
            'priceMasters' => \App\Models\PriceMaster::all(),
        ];
    }

    private function getSlaData(InvoicePeriod $period)
    {
        return [];
    }

    private function getRestitutionData(InvoicePeriod $period)
    {
        return [];
    }

    private function getPriceBenchmarkData(InvoicePeriod $period)
    {
        return [];
    }

    private function getFinalizationData(InvoicePeriod $period)
    {
        return [
            'summary' => $period->summary,
        ];
    }

    /**
     * Update step status
     */
    public function updateStatus(Request $request, InvoicePeriod $invoicePeriod)
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
                ]);
            }
        } else {
            if ($stepStatus) {
                $stepStatus->update($updateData);
            } else {
                InvoiceStepStatus::create([
                    'invoice_period_id' => $invoicePeriod->id,
                    'invoice_step_id' => $step->id,
                    'status' => $request->status,
                ]);
            }
        }

        // Update current step
        $invoicePeriod->update(['current_step_code' => $request->step_code]);

        // Recalculate progress
        $this->recalculateProgress($invoicePeriod);

        return response()->json([
            'success' => true,
            'message' => 'Status step berhasil diperbarui',
            'step_status' => $stepStatus->fresh(),
        ]);
    }

    private function recalculateProgress(InvoicePeriod $period)
    {
        $totalSteps = $period->stepStatuses()->count();
        $completedSteps = $period->stepStatuses()
            ->whereIn('status', [InvoiceStepStatus::STATUS_COMPLETED, InvoiceStepStatus::STATUS_SKIPPED])
            ->count();

        $progress = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
        $period->update(['progress_percentage' => $progress]);

        if ($progress == 100 && $period->status === InvoicePeriod::STATUS_IN_PROGRESS) {
            $period->update(['status' => InvoicePeriod::STATUS_VALIDATION]);
        }
    }
}
