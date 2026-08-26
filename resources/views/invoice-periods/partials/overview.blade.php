{{-- Step: Overview --}}
<div class="p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">Ringkasan Periode</h3>

    <div class="grid grid-cols-2 gap-6">
        <div class="space-y-4">
            <div>
                <label class="text-sm text-gray-500">Periode</label>
                <p class="text-lg font-medium text-gray-900">{{ $period->period_name }}</p>
            </div>
            <div>
                <label class="text-sm text-gray-500">Tanggal Mulai</label>
                <p class="text-gray-900">{{ $period->start_date->format('d F Y') }}</p>
            </div>
            <div>
                <label class="text-sm text-gray-500">Tanggal Akhir</label>
                <p class="text-gray-900">{{ $period->end_date->format('d F Y') }}</p>
            </div>
            <div>
                <label class="text-sm text-gray-500">Billing Days</label>
                <p class="text-gray-900">{{ $period->billing_days }} hari</p>
            </div>
        </div>
        <div class="space-y-4">
            <div>
                <label class="text-sm text-gray-500">Status</label>
                <p class="text-gray-900">{{ ucfirst($period->status_label) }}</p>
            </div>
            <div>
                <label class="text-sm text-gray-500">Dibuat Oleh</label>
                <p class="text-gray-900">{{ $period->creator?->name ?? 'System' }}</p>
            </div>
            <div>
                <label class="text-sm text-gray-500">Progress</label>
                <div class="flex items-center gap-3">
                    <div class="flex-1 bg-gray-200 rounded-full h-2">
                        <div class="bg-[#E6002D] h-2 rounded-full" style="width: {{ $period->progress_percentage }}%"></div>
                    </div>
                    <span class="text-sm font-medium text-gray-900">{{ $period->progress_percentage }}%</span>
                </div>
            </div>
            <div>
                <label class="text-sm text-gray-500">Catatan</label>
                <p class="text-gray-900">{{ $period->notes ?? '-' }}</p>
            </div>
        </div>
    </div>
</div>
