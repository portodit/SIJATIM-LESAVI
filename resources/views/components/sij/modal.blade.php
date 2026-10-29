{{-- Opens when the parent Alpine scope sets `modal` to this name. --}}
@props(['name', 'title' => '', 'titleExpr' => null, 'width' => 'max-w-lg'])
<div x-cloak x-show="modal === '{{ $name }}'" x-transition.opacity
     class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-16"
     @click.self="modal = null" @keydown.escape.window="modal = null">
    <div class="card rounded-xl w-full {{ $width }} shadow-xl" role="dialog" aria-modal="true">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <h2 class="font-semibold text-lg" @if ($titleExpr) x-text="{{ $titleExpr }}" @endif>{{ $title }}</h2>
            <button type="button" class="btn btn-ghost px-2" @click="modal = null" aria-label="Tutup">&times;</button>
        </div>
        <div class="p-6">
            {{ $slot }}
        </div>
    </div>
</div>
