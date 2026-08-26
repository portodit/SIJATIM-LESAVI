@extends('layouts.sijatim')

@push('styles')
<style>
.hide-scrollbar::-webkit-scrollbar { display: none; }
.hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
.dropzone { border: 2px dashed #d1d5db; transition: all 0.3s; }
.dropzone.dragover { border-color: #E6002D; background-color: #fef2f2; }
.dropzone:hover { border-color: #9ca3af; }
.editable-cell { cursor: pointer; transition: background-color 0.15s; min-height: 36px; display: flex; align-items: center; padding: 0 12px; }
.editable-cell:hover { background-color: #f3f4f6; }
.editable-cell.has-value { background-color: #ecfdf5; }
.editable-cell.empty { color: #9ca3af; font-style: italic; }
input.inline-edit { width: 100%; height: 36px; border: 2px solid #E6002D; border-radius: 6px; padding: 0 10px; font-size: 0.875rem; outline: none; }
.inline-edit:disabled { border: 1px solid #d1d5db; background-color: #f9fafb; color: #9ca3af; cursor: not-allowed; }
.inline-edit.has-value { border-color: #10b981 !important; background-color: #ecfdf5 !important; }
select.inline-edit { appearance: none; -webkit-appearance: none; width: 100%; height: 36px; border: 2px solid #E6002D; border-radius: 6px; padding: 0 32px 0 10px; font-size: 0.875rem; outline: none; background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M2 4l4 4 4-4'/%3E%3C/svg%3E") no-repeat right 10px center; cursor: pointer; }
select.inline-edit:focus { border-color: #E6002D; box-shadow: 0 0 0 3px rgba(230,0,45,0.1); outline: none; }
select.inline-edit:disabled { border: 1px solid #d1d5db; background-color: #f9fafb; color: #9ca3af; cursor: not-allowed; background-image: none; }
select.inline-edit.has-value { border-color: #10b981 !important; background-color: #ecfdf5 !important; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2310b981' d='M2 4l4 4 4-4'/%3E%3C/svg%3E") !important; }
select.inline-edit.has-value:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.1); }
select.inline-edit.bw-inactive { border: 1px solid #d1d5db; background-color: #f9fafb; color: #9ca3af; cursor: not-allowed; background-image: none; }
input.inline-edit.bw-inactive { border: 1px solid #d1d5db; background-color: #f9fafb; color: #9ca3af; cursor: not-allowed; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; display: flex; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.hidden { display: none; }
</style>
@endpush

@section('content')
<div class="max-w-7xl mx-auto">

<!-- Header -->
<div class="flex items-center justify-between mb-4">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="badge badge-gray">{{ $period->period_code }}</span>
            <span class="badge bg-[#E6002D]/10 text-[#E6002D]">Step 2</span>
        </div>
        <h1 class="text-xl font-bold text-gray-900">Type Order & Bandwidth</h1>
    </div>
    <a href="{{ route('invoice-periods.show', $period) }}" class="btn btn-secondary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Kembali
    </a>
</div>

<!-- Step Navigation -->
<div class="bg-white rounded-xl border border-gray-200 p-3 mb-4 overflow-x-auto hide-scrollbar">
    <div class="flex items-center gap-2">
        @php
            $stepsArray = $steps->where('step_number', '>', 0)->values();
        @endphp
        @foreach($stepsArray as $index => $step)
            @php
                $stepStatus = $period->stepStatuses->firstWhere('invoice_step_id', $step->id);
                $isActive = $step->id === $currentStep->id;
                $isCompleted = ($stepStatus && in_array($stepStatus->status, ['completed', 'skipped'])) || ($stepStatus && $stepStatus->completed_at);
                $statusClass = $isCompleted ? 'bg-emerald-500 text-white' : ($isActive ? 'bg-[#E6002D] text-white' : 'bg-gray-100 text-gray-400');
                $canAccess = true;
                $blockedBy = null;
                if (!$isCompleted && !$isActive) {
                    for ($i = 0; $i < $index; $i++) {
                        $prevStep = $stepsArray[$i];
                        $prevStatus = $period->stepStatuses->firstWhere('invoice_step_id', $prevStep->id);
                        if (!$prevStatus || !in_array($prevStatus->status, ['completed', 'skipped'])) {
                            $canAccess = false;
                            $blockedBy = $prevStep->step_name;
                            break;
                        }
                    }
                }
            @endphp
            @if($isCompleted && !$isActive)
                <a href="{{ route('invoice-periods.step', ['invoicePeriod' => $period, 'step' => $step->step_number]) }}"
                   class="flex items-center gap-2 px-3 py-1.5 rounded-lg shrink-0 hover:bg-emerald-50 transition-colors cursor-pointer group">
                    <div class="w-6 h-6 rounded-full {{ $statusClass }} flex items-center justify-center text-xs font-semibold">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <span class="text-xs whitespace-nowrap text-gray-600 group-hover:text-emerald-600">{{ $step->step_name }}</span>
                </a>
            @elseif($isActive)
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg shrink-0 bg-[#E6002D]/5 cursor-not-allowed">
                    <div class="w-6 h-6 rounded-full {{ $statusClass }} flex items-center justify-center text-xs font-semibold">{{ $step->step_number }}</div>
                    <span class="text-xs whitespace-nowrap text-[#E6002D] font-medium">{{ $step->step_name }}</span>
                </div>
            @elseif($canAccess)
                <a href="{{ route('invoice-periods.step', ['invoicePeriod' => $period, 'step' => $step->step_number]) }}"
                   class="flex items-center gap-2 px-3 py-1.5 rounded-lg shrink-0 hover:bg-gray-50 transition-colors cursor-pointer group">
                    <div class="w-6 h-6 rounded-full {{ $statusClass }} flex items-center justify-center text-xs font-semibold">{{ $step->step_number }}</div>
                    <span class="text-xs whitespace-nowrap text-gray-600 group-hover:text-gray-900">{{ $step->step_name }}</span>
                </a>
            @else
                <button onclick="showBlockedWarning('{{ $blockedBy }}')"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-lg shrink-0 hover:bg-gray-50 transition-colors cursor-not-allowed group">
                    <div class="w-6 h-6 rounded-full {{ $statusClass }} flex items-center justify-center text-xs font-semibold">{{ $step->step_number }}</div>
                    <span class="text-xs whitespace-nowrap text-gray-400 group-hover:text-gray-500">{{ $step->step_name }}</span>
                </button>
            @endif
            @if(!$loop->last)
                <div class="w-5 h-0.5 shrink-0 {{ $isCompleted ? 'bg-emerald-400' : 'bg-gray-200' }}"></div>
            @endif
        @endforeach
    </div>
</div>

{{-- Progress + Actions Bar --}}
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        {{-- Progress --}}
        <div class="flex-1 min-w-0">
            @php
                $filledCount = $invoiceLines->whereNotNull('type_order_id')->filter(fn($l) => $l->isTypeOrderComplete())->count();
                $totalCount = $invoiceLines->count();
                $percentage = $totalCount > 0 ? round(($filledCount / $totalCount) * 100) : 0;
                $incompleteCount = $invoiceLines->filter(fn($l) => $l->type_order_id && !$l->isTypeOrderComplete())->count();
                $emptyCount = $invoiceLines->whereNull('type_order_id')->count();
            @endphp
            <div class="flex items-center gap-3 mb-2">
                <span class="text-sm text-gray-600">
                    <span class="text-emerald-600 font-bold">{{ $filledCount }}</span> / {{ $totalCount }} SSID terisi lengkap
                </span>
                <span class="text-lg font-bold {{ $percentage == 100 ? 'text-emerald-600' : 'text-[#E6002D]' }}">{{ $percentage }}%</span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-2.5">
                <div class="h-2.5 rounded-full transition-all duration-700 ease-out"
                     style="width: {{ $percentage }}%; background: linear-gradient(90deg, #10B981 0%, #34D399 100%);"></div>
            </div>
        </div>

        {{-- Summary Chips --}}
        <div class="flex items-center gap-2 shrink-0">
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                Lengkap: {{ $filledCount }}
            </span>
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                Belum: {{ $incompleteCount }}
            </span>
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-50 text-gray-600 border border-gray-200">
                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                Kosong: {{ $emptyCount }}
            </span>
        </div>

        {{-- Action Buttons --}}
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('invoice-periods.step2.download-template', $period) }}"
               class="btn btn-secondary text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Template
            </a>
            <button type="button" onclick="openImportModal()" class="btn bg-[#E6002D] text-white hover:bg-[#C7002A] text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                Import Excel
            </button>
        </div>
    </div>
</div>

@php
    // Compute isStepCompleted directly from DB
    $dbStep = \App\Models\InvoiceStep::where('step_code', 'type_order')->first();
    $dbStatus = $period->stepStatuses->firstWhere('invoice_step_id', $dbStep->id);
    $isStepCompleted = $dbStatus && in_array($dbStatus->status, ['completed', 'skipped']);
@endphp

{{-- Data Table --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    @if($invoiceLines->isEmpty())
        <div class="p-12 text-center">
            <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">Belum ada SSID</h3>
            <p class="text-gray-500 text-sm mb-4">Selesaikan Step 1 terlebih dahulu untuk menambah SSID ke periode ini.</p>
            <a href="{{ route('invoice-periods.step', ['invoicePeriod' => $period, 'step' => 1]) }}"
               class="btn bg-[#E6002D] text-white hover:bg-[#C7002A]">
                Lanjut ke Step 1
            </a>
        </div>
    @else
        @php
            // Compute isStepCompleted directly from DB to ensure correctness
            $dbStep = \App\Models\InvoiceStep::where('step_code', 'type_order')->first();
            $dbStatus = $period->stepStatuses->firstWhere('invoice_step_id', $dbStep->id);
            $isStepCompleted = $dbStatus && in_array($dbStatus->status, ['completed', 'skipped']);
        @endphp
        <div class="overflow-x-auto max-h-[70vh] overflow-y-auto rounded-lg border border-gray-200">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                    <tr>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 w-12">NO</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 min-w-32">JENIS KANTOR</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 min-w-28">LAYANAN</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 min-w-28">SSID</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 min-w-24">TYPE ORDER</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 min-w-28">TGL PERUBAHAN</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 min-w-20 text-amber-600">EXISTING *</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 min-w-20">UPGRADE</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-600 min-w-20">DOWNGRADE</th>
                    </tr>
                    <tr class="bg-gray-50">
                        <th colspan="6"></th>
                        <th class="px-3 pb-1 text-xs font-normal text-gray-500 italic text-center">Bandwidth SAAT INI</th>
                        <th class="px-3 pb-1 text-xs font-normal text-gray-500 italic text-center">Bandwidth BARU (jika UPGRADE)</th>
                        <th class="px-3 pb-1 text-xs font-normal text-gray-500 italic text-center">Bandwidth BARU (jika DOWNGRADE)</th>
                    </tr>
                </thead>
                <tbody id="step2TableBody">
                    @foreach($invoiceLines as $line)
                        @php
                            $bankLink = $line->bankJatimLink;
                            $officeType = $bankLink->officeType->name ?? '-';
                            $service = $bankLink->service->name ?? '-';
                            $ssid = $bankLink->ssid ?? '-';
                            $typeOrder = $line->typeOrder;
                            $typeOrderCode = $typeOrder ? strtoupper($typeOrder->type_order_code) : '';
                            $typeOrderName = $typeOrder ? strtoupper($typeOrder->type_order_name) : '-';
                            $existingBw = $line->existingBandwidth;
                            $existingBwName = $existingBw ? $existingBw->bandwidth_name : '-';
                            $upgradeBw = $line->upgradeBandwidth;
                            $upgradeBwName = $upgradeBw ? $upgradeBw->bandwidth_name : '-';
                            $downgradeBw = $line->downgradeBandwidth;
                            $downgradeBwName = $downgradeBw ? $downgradeBw->bandwidth_name : '-';
                            $effectiveDate = $line->effective_date ? $line->effective_date->format('d/m/Y') : '-';
                            $isComplete = $line->isTypeOrderComplete();
                            $rowClass = $isComplete ? 'bg-emerald-50/30' : '';
                        @endphp

                        @if($isStepCompleted)
                            {{-- MODE VIEW (step sudah completed) --}}
                            <tr class="border-b border-gray-100 hover:bg-gray-50/50 {{ $rowClass }}">
                                <td class="px-3 py-2 text-center text-gray-400 w-12">{{ $line->line_number }}</td>
                                <td class="px-3 py-2 text-gray-800 min-w-32">{{ $officeType }}</td>
                                <td class="px-3 py-2 text-gray-800 min-w-28">{{ $service }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-700 min-w-28">
                                    <span title="{{ $ssid }}">{{ Str::limit($ssid, 20) }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    @if($typeOrderCode)
                                        @php $badgeClass = match($typeOrderCode) { 'EXISTING' => 'bg-blue-100 text-blue-700', 'UPGRADE' => 'bg-emerald-100 text-emerald-700', 'DOWNGRADE' => 'bg-orange-100 text-orange-700', 'RELOKASI' => 'bg-purple-100 text-purple-700', 'PEMUTUSAN' => 'bg-red-100 text-red-700', 'PASANG_BARU' => 'bg-teal-100 text-teal-700', default => 'bg-gray-100 text-gray-700' }; @endphp
                                        <span class="px-2 py-0.5 rounded text-xs font-medium {{ $badgeClass }}">{{ $typeOrderCode }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-600 min-w-28">{{ $effectiveDate }}</td>
                                <td class="px-3 py-2 min-w-20 text-center">
                                    @if($existingBw)
                                        <span class="px-2 py-0.5 bg-gray-100 text-gray-700 rounded text-xs">{{ $existingBwName }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 min-w-20 text-center">
                                    @if(in_array($typeOrderCode, ['UPGRADE', 'PASANG_BARU']) && $upgradeBw)
                                        <span class="px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded text-xs">{{ $upgradeBwName }}</span>
                                    @elseif(in_array($typeOrderCode, ['UPGRADE', 'PASANG_BARU']))
                                        <span class="text-gray-400">-</span>
                                    @else
                                        <span class="text-gray-300">--</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 min-w-20 text-center">
                                    @if($typeOrderCode === 'DOWNGRADE' && $downgradeBw)
                                        <span class="px-2 py-0.5 bg-orange-100 text-orange-700 rounded text-xs">{{ $downgradeBwName }}</span>
                                    @elseif($typeOrderCode === 'DOWNGRADE')
                                        <span class="text-gray-400">-</span>
                                    @else
                                        <span class="text-gray-300">--</span>
                                    @endif
                                </td>
                            </tr>
                        @else
                            {{-- MODE EDIT (step belum completed) --}}
                            <tr class="border-b border-gray-100 hover:bg-gray-50/50 {{ $rowClass }}"
                                data-line-id="{{ $line->id }}"
                                data-ssid="{{ $ssid }}"
                                data-type-order="{{ $typeOrderCode }}">
                                <td class="px-3 py-2 text-center text-gray-400 w-12">{{ $line->line_number }}</td>
                                <td class="px-3 py-2 text-gray-800 min-w-32">{{ $officeType }}</td>
                                <td class="px-3 py-2 text-gray-800 min-w-28">{{ $service }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-700 min-w-28">
                                    <span title="{{ $ssid }}">{{ Str::limit($ssid, 20) }}</span>
                                </td>

                                {{-- TYPE ORDER --}}
                                <td class="px-3 py-2 min-w-24">
                                    <select class="inline-edit{{ $typeOrderCode ? ' has-value' : '' }}"
                                            data-field="type_order_id"
                                            data-line-id="{{ $line->id }}"
                                            onchange="onTypeOrderChange(this)">
                                        <option value="">-- Pilih --</option>
                                        @foreach($typeOrders as $to)
                                            <option value="{{ $to->id }}" {{ $line->type_order_id == $to->id ? 'selected' : '' }}>
                                                {{ strtoupper($to->type_order_code) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>

                                {{-- TGL PERUBAHAN --}}
                                <td class="px-3 py-2 min-w-28">
                                    <input type="date"
                                           class="inline-edit{{ $line->effective_date ? ' has-value' : '' }}"
                                           data-field="effective_date"
                                           data-line-id="{{ $line->id }}"
                                           value="{{ $line->effective_date?->format('Y-m-d') }}"
                                           onchange="saveInlineEdit(this)">
                                </td>

                                {{-- EXISTING (always enabled) --}}
                                <td class="px-3 py-2 min-w-20">
                                    <select class="inline-edit bw-existing{{ $existingBw ? ' has-value' : '' }}"
                                            data-field="existing_bandwidth_option_id"
                                            data-line-id="{{ $line->id }}"
                                            onchange="saveInlineEdit(this)">
                                        <option value="">--</option>
                                        @foreach($bandwidthOptions as $bw)
                                            <option value="{{ $bw->id }}" {{ $line->existing_bandwidth_option_id == $bw->id ? 'selected' : '' }}>
                                            {{ $bw->bandwidth_name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </td>

                                {{-- UPGRADE (enabled only for UPGRADE or PASANG_BARU type order) --}}
                                <td class="px-3 py-2 min-w-20">
                                    <select class="inline-edit bw-upgrade{{ $upgradeBw ? ' has-value' : '' }}{{ !in_array($typeOrderCode, ['UPGRADE', 'PASANG_BARU']) ? ' bw-inactive' : '' }}"
                                            data-field="upgrade_bandwidth_option_id"
                                            data-line-id="{{ $line->id }}"
                                            {{ !in_array($typeOrderCode, ['UPGRADE', 'PASANG_BARU']) ? 'disabled' : '' }}
                                            onchange="saveInlineEdit(this)">
                                        <option value="">--</option>
                                        @foreach($bandwidthOptions as $bw)
                                            <option value="{{ $bw->id }}" {{ $line->upgrade_bandwidth_option_id == $bw->id ? 'selected' : '' }}>
                                            {{ $bw->bandwidth_name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </td>

                                {{-- DOWNGRADE (enabled only for DOWNGRADE type order) --}}
                                <td class="px-3 py-2 min-w-20">
                                    <select class="inline-edit bw-downgrade{{ $downgradeBw ? ' has-value' : '' }}{{ $typeOrderCode != 'DOWNGRADE' ? ' bw-inactive' : '' }}"
                                            data-field="downgrade_bandwidth_option_id"
                                            data-line-id="{{ $line->id }}"
                                            {{ $typeOrderCode != 'DOWNGRADE' ? 'disabled' : '' }}
                                            onchange="saveInlineEdit(this)">
                                        <option value="">--</option>
                                        @foreach($bandwidthOptions as $bw)
                                            <option value="{{ $bw->id }}" {{ $line->downgrade_bandwidth_option_id == $bw->id ? 'selected' : '' }}>
                                            {{ $bw->bandwidth_name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Bottom Actions --}}
<div class="flex items-center justify-between mt-4">
    <a href="{{ route('invoice-periods.step', ['invoicePeriod' => $period, 'step' => 1]) }}"
       class="btn btn-secondary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Kembali ke Step 1
    </a>
    @php
        $dbStep = \App\Models\InvoiceStep::where('step_code', 'type_order')->first();
        $dbStatus = $period->stepStatuses->firstWhere('invoice_step_id', $dbStep->id);
        $isStepCompleted = $dbStatus && in_array($dbStatus->status, ['completed', 'skipped']);
        $nextStep = $steps->where('step_number', '>', $currentStep->step_number)->where('is_active', true)->first();
    @endphp
    @if($isStepCompleted)
        {{-- State: Step sudah completed --}}
        <div class="flex items-center gap-2">
            <a href="{{ route('invoice-periods.step2', $period) }}?force_edit=1"
               class="btn bg-blue-600 text-white hover:bg-blue-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
                Edit Data
            </a>
            @if($nextStep)
                <a href="{{ route('invoice-periods.step', ['invoicePeriod' => $period, 'step' => $nextStep->step_number]) }}"
                   class="btn bg-emerald-600 text-white hover:bg-emerald-700">
                    Lanjut ke {{ $nextStep->step_name }}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                </a>
            @endif
        </div>
    @else
        {{-- State: Step belum completed --}}
        <div class="flex items-center gap-2">
            {{-- Simpan: refresh page untuk lihat perubahan terbaru (auto-save sudah menyimpan data) --}}
            <a href="{{ route('invoice-periods.step2', $period) }}"
               class="btn bg-blue-600 text-white hover:bg-blue-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Refresh Data
            </a>
            @if($stepData['isComplete'] ?? false)
                <form action="{{ route('invoice-periods.step2.complete', $period) }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="btn bg-emerald-600 text-white hover:bg-emerald-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Tandai Selesai & Lanjut
                    </button>
                </form>
            @else
                <button type="button" class="btn bg-emerald-600 text-white opacity-50 cursor-not-allowed" disabled>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Tandai Selesai & Lanjut
                    <span class="text-xs ml-1 opacity-75">(Lengkapi semua SSID dulu)</span>
                </button>
            @endif
        </div>
    @endif
</div>

</div>
@endsection

{{-- Import Modal --}}
<div id="importModal" class="modal-overlay hidden">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-hidden flex flex-col">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">Import Data Type Order & Bandwidth</h3>
            <button onclick="closeImportModal()" class="p-1 hover:bg-gray-100 rounded-lg transition-colors">
                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="overflow-y-auto flex-1" id="importModalBody">
            {{-- Step 1: Upload File --}}
            <div id="importStep1" class="p-6">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Pilih file Excel yang sudah diisi</label>
                    <div class="dropzone rounded-xl p-8 text-center cursor-pointer hover:border-[#E6002D] transition-colors"
                         id="modalDropzone"
                         onclick="document.getElementById('importFile').click()">
                        <input type="file" name="file" id="importFile" class="hidden" accept=".xlsx,.xls" onchange="handleModalFileSelect(this)">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-gray-700 font-medium">Klik atau drag file Excel ke sini</p>
                                <p class="text-sm text-gray-500 mt-1">Format: XLSX (2 sheet — DATABASE + sheet periode)</p>
                            </div>
                        </div>
                    </div>
                    <div id="modalFileInfo" class="hidden mt-3 p-3 bg-gray-50 rounded-lg flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">
                                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900 text-sm" id="modalFileName">-</p>
                                <p class="text-xs text-gray-500" id="modalFileSize">-</p>
                            </div>
                        </div>
                        <button type="button" onclick="clearModalFile()" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <button type="button" id="btnPreviewImport" onclick="previewImport()" class="w-full btn bg-[#E6002D] text-white hover:bg-[#C7002A] disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    Preview Import
                </button>
            </div>

            {{-- Step 2: Preview --}}
            <div id="importStep2" class="hidden p-6">
                <div class="text-center py-8" id="previewLoading">
                    <div class="animate-spin w-8 h-8 border-2 border-[#E6002D] border-t-transparent rounded-full mx-auto mb-3"></div>
                    <p class="text-gray-500 text-sm">Memproses file...</p>
                </div>
                <div id="previewContent" class="hidden">
                    {{-- Summary --}}
                    <div class="grid grid-cols-3 gap-2 mb-4">
                        <div class="bg-emerald-50 rounded-lg p-3 text-center border border-emerald-200">
                            <p class="text-2xl font-bold text-emerald-700" id="previewNewCount">0</p>
                            <p class="text-xs text-emerald-600">Baru</p>
                        </div>
                        <div class="bg-amber-50 rounded-lg p-3 text-center border border-amber-200">
                            <p class="text-2xl font-bold text-amber-700" id="previewChangedCount">0</p>
                            <p class="text-xs text-amber-600">Berubah</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-3 text-center border border-gray-200">
                            <p class="text-2xl font-bold text-gray-600" id="previewErrorCount">0</p>
                            <p class="text-xs text-gray-500">Error</p>
                        </div>
                    </div>

                    {{-- Changes List --}}
                    <div id="previewChanges" class="max-h-64 overflow-y-auto mb-4 space-y-2">
                    </div>

                    {{-- Error List --}}
                    <div id="previewErrors" class="hidden max-h-40 overflow-y-auto mb-4">
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" onclick="backToStep1()" class="btn btn-secondary flex-1">Batal</button>
                        <button type="button" id="btnCommitImport" onclick="commitImport()" class="btn bg-emerald-600 text-white hover:bg-emerald-700 flex-1">
                            Ya, Import Data
                        </button>
                    </div>
                </div>
            </div>

            {{-- Step 3: Results --}}
            <div id="importStep3" class="hidden p-6">
                <div class="text-center py-8" id="resultLoading">
                    <div class="animate-spin w-8 h-8 border-2 border-[#E6002D] border-t-transparent rounded-full mx-auto mb-3"></div>
                    <p class="text-gray-500 text-sm">Mengimport data...</p>
                </div>
                <div id="resultContent" class="hidden">
                    <div class="text-center mb-4">
                        <div class="w-16 h-16 rounded-full bg-emerald-100 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-1">Import Selesai</h4>
                        <p class="text-sm text-gray-500" id="resultSummary">-</p>
                    </div>

                    {{-- Error Summary --}}
                    <div id="resultErrors" class="hidden mb-4">
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-sm font-medium text-red-700" id="resultErrorCount">-</p>
                                <button onclick="downloadErrorLog()" class="text-xs text-red-600 hover:text-red-800 underline flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                    </svg>
                                    Download Log
                                </button>
                            </div>
                            <div id="resultErrorList" class="text-xs text-red-600 space-y-1 max-h-32 overflow-y-auto">
                            </div>
                        </div>
                    </div>

                    <button onclick="closeImportModal(); location.reload()" class="w-full btn bg-[#E6002D] text-white hover:bg-[#C7002A]">
                        Tutup & Refresh Tabel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const periodId = {{ $period->id }};
let importFileData = null;
let importPreviewData = null;
let importResultErrors = [];

// ========== Modal ==========
function openImportModal() {
    document.getElementById('importModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    resetImportModal();
}

function closeImportModal() {
    document.getElementById('importModal').classList.add('hidden');
    document.body.style.overflow = '';
    resetImportModal();
}

function resetImportModal() {
    importFileData = null;
    importPreviewData = null;
    importResultErrors = [];
    document.getElementById('importFile').value = '';
    document.getElementById('modalFileInfo').classList.add('hidden');
    document.getElementById('modalDropzone').classList.remove('hidden');
    document.getElementById('btnPreviewImport').disabled = true;
    document.getElementById('importStep1').classList.remove('hidden');
    document.getElementById('importStep2').classList.add('hidden');
    document.getElementById('importStep3').classList.add('hidden');
}

function backToStep1() {
    document.getElementById('importStep2').classList.add('hidden');
    document.getElementById('importStep1').classList.remove('hidden');
}

// ========== File Handling ==========
function handleModalFileSelect(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        importFileData = file;
        document.getElementById('modalFileName').textContent = file.name;
        document.getElementById('modalFileSize').textContent = formatFileSize(file.size);
        document.getElementById('modalFileInfo').classList.remove('hidden');
        document.getElementById('modalDropzone').classList.add('hidden');
        document.getElementById('btnPreviewImport').disabled = false;
    }
}

function clearModalFile() {
    document.getElementById('importFile').value = '';
    importFileData = null;
    document.getElementById('modalFileInfo').classList.add('hidden');
    document.getElementById('modalDropzone').classList.remove('hidden');
    document.getElementById('btnPreviewImport').disabled = true;
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

// Drag and drop in modal
const modalDropzone = document.getElementById('modalDropzone');
if (modalDropzone) {
    ['dragenter', 'dragover'].forEach(e => modalDropzone.addEventListener(e, ev => {
        ev.preventDefault();
        modalDropzone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach(e => modalDropzone.addEventListener(e, ev => {
        ev.preventDefault();
        modalDropzone.classList.remove('dragover');
    }));
    modalDropzone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length > 0) {
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            document.getElementById('importFile').files = dt.files;
            handleModalFileSelect(document.getElementById('importFile'));
        }
    });
}

// ========== Preview Import ==========
async function previewImport() {
    if (!importFileData) return;

    document.getElementById('importStep1').classList.add('hidden');
    document.getElementById('importStep2').classList.remove('hidden');
    document.getElementById('previewLoading').classList.remove('hidden');
    document.getElementById('previewContent').classList.add('hidden');

    const formData = new FormData();
    formData.append('file', importFileData);

    try {
        const response = await fetch(`/invoice-periods/${periodId}/step2-import-preview`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: formData,
        });

        const result = await response.json();

        document.getElementById('previewLoading').classList.add('hidden');
        document.getElementById('previewContent').classList.remove('hidden');

        if (!result.success) {
            alert('Gagal memproses file: ' + result.message);
            backToStep1();
            return;
        }

        importPreviewData = result.preview;
        renderPreview(result.preview);

    } catch (err) {
        alert('Terjadi kesalahan: ' + err.message);
        backToStep1();
    }
}

function renderPreview(preview) {
    const newCount = preview.new.length;
    const changedCount = preview.changed.length;
    const errorCount = preview.errors.length;
    const totalRows = newCount + changedCount + preview.unchanged.length;

    document.getElementById('previewNewCount').textContent = newCount;
    document.getElementById('previewChangedCount').textContent = changedCount;
    document.getElementById('previewErrorCount').textContent = errorCount;

    // Render changed items
    const changesEl = document.getElementById('previewChanges');
    changesEl.innerHTML = '';

    if (changedCount === 0 && newCount === 0) {
        changesEl.innerHTML = '<p class="text-sm text-gray-500 text-center py-4">Tidak ada perubahan data.</p>';
    } else {
        if (newCount > 0) {
            const newDiv = document.createElement('div');
            newDiv.className = 'bg-emerald-50 border border-emerald-200 rounded-lg p-3';
            newDiv.innerHTML = `<p class="text-sm font-medium text-emerald-700 mb-2">${newCount} SSID baru akan ditambahkan</p>`;
            preview.new.forEach(item => {
                newDiv.innerHTML += `<p class="text-xs text-emerald-600 pl-2">• SSID: ${item.ssid} (baris ${item.row})</p>`;
            });
            changesEl.appendChild(newDiv);
        }

        if (changedCount > 0) {
            preview.changed.forEach(item => {
                const changes = item.changes;
                let changeHtml = '';
                for (const [field, change] of Object.entries(changes)) {
                    const fieldLabel = {
                        'type_order': 'Type Order',
                        'effective_date': 'Tgl Perubahan',
                        'existing_bw': 'Existing',
                        'upgrade_bw': 'Upgrade',
                        'downgrade_bw': 'Downgrade',
                    }[field] || field;
                    changeHtml += `<span class="inline-block text-xs"><span class="text-gray-400">${fieldLabel}:</span> <span class="text-red-500 line-through">${change.old}</span> → <span class="text-emerald-600 font-medium">${change.new}</span></span><br>`;
                }

                const div = document.createElement('div');
                div.className = 'bg-amber-50 border border-amber-200 rounded-lg p-3';
                div.innerHTML = `
                    <p class="text-sm font-medium text-amber-700 mb-1">${item.ssid}</p>
                    ${changeHtml}
                `;
                changesEl.appendChild(div);
            });
        }
    }

    // Render errors
    const errorsEl = document.getElementById('previewErrors');
    if (errorCount > 0) {
        errorsEl.classList.remove('hidden');
        errorsEl.innerHTML = `<p class="text-xs font-semibold text-red-600 mb-2">${errorCount} SSID error (tidak akan diimport):</p>`;
        preview.errors.forEach(err => {
            const div = document.createElement('div');
            div.className = 'text-xs text-red-600 py-1 border-b border-red-100';
            div.innerHTML = `<strong>${err.ssid}</strong> (baris ${err.row}): ${err.reason}`;
            errorsEl.appendChild(div);
        });
    } else {
        errorsEl.classList.add('hidden');
    }
}

// ========== Commit Import ==========
async function commitImport() {
    if (!importPreviewData) return;

    document.getElementById('importStep2').classList.add('hidden');
    document.getElementById('importStep3').classList.remove('hidden');
    document.getElementById('resultLoading').classList.remove('hidden');
    document.getElementById('resultContent').classList.add('hidden');
    importResultErrors = [];

    // Build data array from preview (only new + changed, skip errors)
    const dataToImport = [];

    if (importPreviewData.new) {
        importPreviewData.new.forEach(item => {
            dataToImport.push({
                ssid: item.ssid,
                line_id: item.line_id,
                type_order_id: item.type_order_id,
                effective_date: item.effective_date,
                existing_bw_id: item.existing_bw_id,
                upgrade_bw_id: item.upgrade_bw_id,
                downgrade_bw_id: item.downgrade_bw_id,
            });
        });
    }

    if (importPreviewData.changed) {
        importPreviewData.changed.forEach(item => {
            dataToImport.push({
                ssid: item.ssid,
                line_id: item.line_id,
                type_order_id: item.type_order_id,
                effective_date: item.effective_date,
                existing_bw_id: item.existing_bw_id,
                upgrade_bw_id: item.upgrade_bw_id,
                downgrade_bw_id: item.downgrade_bw_id,
            });
        });
    }

    if (importPreviewData.unchanged) {
        importPreviewData.unchanged.forEach(item => {
            dataToImport.push({
                ssid: item.ssid,
                line_id: item.line_id,
                type_order_id: item.type_order_id,
                effective_date: item.effective_date,
                existing_bw_id: item.existing_bw_id,
                upgrade_bw_id: item.upgrade_bw_id,
                downgrade_bw_id: item.downgrade_bw_id,
            });
        });
    }

    try {
        const response = await fetch(`/invoice-periods/${periodId}/step2-import-commit`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ data: dataToImport }),
        });

        const result = await response.json();

        document.getElementById('resultLoading').classList.add('hidden');
        document.getElementById('resultContent').classList.remove('hidden');

        document.getElementById('resultSummary').textContent = result.message;

        if (result.results.error_count > 0) {
            importResultErrors = result.results.errors;
            document.getElementById('resultErrors').classList.remove('hidden');
            document.getElementById('resultErrorCount').textContent = `${result.results.error_count} SSID gagal:`;
            const errorListEl = document.getElementById('resultErrorList');
            errorListEl.innerHTML = '';
            result.results.errors.forEach(err => {
                const div = document.createElement('div');
                div.textContent = `• ${err}`;
                errorListEl.appendChild(div);
            });
        }

    } catch (err) {
        alert('Terjadi kesalahan saat import: ' + err.message);
        closeImportModal();
    }
}

function downloadErrorLog() {
    if (!importResultErrors.length) return;

    const content = importResultErrors.map(e => `[${new Date().toLocaleString('id-ID')}] ${e}`).join('\n');
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `step2_import_error_${periodId}_${Date.now()}.txt`;
    a.click();
    URL.revokeObjectURL(url);
}

// ========== Inline Edit ==========

// Called when Type Order dropdown changes — enables/disables bandwidth columns + saves immediately
async function onTypeOrderChange(el) {
    const lineId = el.dataset.lineId;
    const value = el.value;
    const selectedOption = el.options[el.selectedIndex];
    const typeOrderCode = selectedOption ? selectedOption.text.trim().toUpperCase() : '';
    const row = el.closest('tr');

    const bwExisting = row.querySelector('.bw-existing');
    const bwUpgrade = row.querySelector('.bw-upgrade');
    const bwDowngrade = row.querySelector('.bw-downgrade');

    const isUpgrade = typeOrderCode === 'UPGRADE';
    const isDowngrade = typeOrderCode === 'DOWNGRADE';
    const isPasangBaru = typeOrderCode === 'PASANG_BARU';
    const isExisting = typeOrderCode === 'EXISTING';
    const isRelokasi = typeOrderCode === 'RELOKASI';
    const isPemutusan = typeOrderCode === 'PEMUTUSAN';

    // Reset visual states first
    if (bwExisting) { bwExisting.classList.remove('has-value', 'bw-inactive'); bwExisting.disabled = false; }
    if (bwUpgrade) { bwUpgrade.classList.remove('has-value', 'bw-inactive'); bwUpgrade.disabled = false; bwUpgrade.value = ''; }
    if (bwDowngrade) { bwDowngrade.classList.remove('has-value', 'bw-inactive'); bwDowngrade.disabled = false; bwDowngrade.value = ''; }

    // Apply disable logic based on type order
    if (isUpgrade || isPasangBaru) {
        // Existing + upgrade enabled, downgrade disabled
        if (bwDowngrade) { bwDowngrade.disabled = true; bwDowngrade.classList.add('bw-inactive'); }
    } else if (isDowngrade) {
        // Existing + downgrade enabled, upgrade disabled
        if (bwUpgrade) { bwUpgrade.disabled = true; bwUpgrade.classList.add('bw-inactive'); }
    } else if (isExisting || isRelokasi || isPemutusan) {
        // Only existing enabled
        if (bwUpgrade) { bwUpgrade.disabled = true; bwUpgrade.classList.add('bw-inactive'); }
        if (bwDowngrade) { bwDowngrade.disabled = true; bwDowngrade.classList.add('bw-inactive'); }
    } else {
        // No type order selected: all disabled
        if (bwExisting) { bwExisting.disabled = true; bwExisting.classList.add('bw-inactive'); }
        if (bwUpgrade) { bwUpgrade.disabled = true; bwUpgrade.classList.add('bw-inactive'); }
        if (bwDowngrade) { bwDowngrade.disabled = true; bwDowngrade.classList.add('bw-inactive'); }
    }

    // Flash the type order dropdown
    el.classList.add('has-value');
    el.style.backgroundColor = '#d1fae5';
    setTimeout(() => { el.style.backgroundColor = ''; }, 600);

    // Save to backend immediately
    try {
        await fetch(`/invoice-periods/${periodId}/step2-update/${lineId}`, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ type_order_id: value }),
        });
    } catch (err) {
        console.error('Error saving type order:', err);
    }
}

// No longer needed — disabled state is set server-side via HTML attribute
function initBandwidthDisable() {
    // Already handled by server-side disabled attributes + bw-inactive class
}

async function saveInlineEdit(el) {
    const lineId = el.dataset.lineId;
    const field = el.dataset.field;
    const value = el.value;

    try {
        const response = await fetch(`/invoice-periods/${periodId}/step2-update/${lineId}`, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ [field]: value }),
        });

        const result = await response.json();

        if (result.success) {
            // Update visual state
            if (value) {
                el.classList.add('has-value');
                el.classList.remove('empty');
            } else {
                el.classList.remove('has-value');
                el.classList.add('empty');
            }

            // Flash green to confirm
            el.style.backgroundColor = '#d1fae5';
            setTimeout(() => { el.style.backgroundColor = ''; }, 600);

            // Show success toast (no page reload)
            showToast('Data tersimpan', 'success');
        } else {
            showToast('Gagal menyimpan: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (err) {
        showToast('Terjadi kesalahan: ' + err.message, 'error');
    }
}

// ========== Toast ==========
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 ${
        type === 'success' ? 'bg-emerald-500 text-white' :
        type === 'error' ? 'bg-red-500 text-white' :
        'bg-gray-800 text-white'
    }`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 3000);
}

// Init bandwidth column disable on page load
document.addEventListener('DOMContentLoaded', initBandwidthDisable);
</script>
@endpush
