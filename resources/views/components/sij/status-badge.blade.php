@props(['status'])
@php
    $styles = [
        'existing' => ['Existing', 'bg-gray-100 text-gray-700'],
        'upgrade' => ['Upgrade', 'bg-blue-100 text-blue-800'],
        'downgrade' => ['Downgrade', 'bg-amber-100 text-amber-800'],
        'relokasi' => ['Relokasi', 'bg-purple-100 text-purple-800'],
        'pemutusan' => ['Cabut', 'bg-red-100 text-red-800'],
        'pasang_baru' => ['PSB', 'bg-green-100 text-green-800'],
        'multiple' => ['Beberapa perubahan', 'bg-indigo-100 text-indigo-800'],
        'scheduled' => ['Terjadwal', 'border border-gray-400 text-gray-700'],
    ];
    [$label, $class] = $styles[strtolower($status)] ?? [$status, 'bg-gray-100 text-gray-700'];
@endphp
<span {{ $attributes->merge(['class' => 'badge '.$class]) }}>{{ $label }}</span>
