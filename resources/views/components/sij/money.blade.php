@props(['value', 'empty' => '—'])
@if ($value === null)
    <span {{ $attributes->merge(['class' => 'text-gray-400']) }}>{{ $empty }}</span>
@else
    @php($decimals = fmod((float) $value, 1) == 0 ? 0 : 2)
    <span {{ $attributes->merge(['class' => 'whitespace-nowrap']) }}>Rp {{ number_format((float) $value, $decimals, ',', '.') }}</span>
@endif
