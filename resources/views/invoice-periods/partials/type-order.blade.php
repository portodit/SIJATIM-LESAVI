{{-- Step 2: Type Order & Bandwidth - Placeholder --}}
<div class="p-6">
    <div class="text-center py-12">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 flex items-center justify-center">
            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
            </svg>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">Type Order & Bandwidth</h3>
        <p class="text-gray-500 mb-6">Sub Tahap 1.2 - Konfigurasi Type Order & Bandwidth untuk setiap SSID</p>

        {{-- Info Card --}}
        <div class="max-w-md mx-auto bg-white border border-gray-200 rounded-lg p-6 text-left">
            <h4 class="font-medium text-gray-900 mb-3">Informasi:</h4>
            <ul class="text-sm text-gray-600 space-y-2">
                <li class="flex items-start gap-2">
                    <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Total <strong class="text-blue-600">{{ $stepData['targetCount'] ?? 0 }}</strong> SSID dari Step 1</span>
                </li>
                <li class="flex items-start gap-2">
                    <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Type Order: EXISTING, UPGRADE, DOWNGRADE, PSB, PEMUTUSAN, RELOKASI</span>
                </li>
                <li class="flex items-start gap-2">
                    <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Input bandwidth untuk setiap SSID</span>
                </li>
            </ul>
        </div>

        <div class="mt-6">
            <a href="/invoice-periods/{{ $period->id }}/step/3" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
                Lanjut ke Sub Tahap 3
            </a>
        </div>
    </div>
</div>
