@props(['sort', 'dir' => 'asc'])
@php
    // $dir is the direction of the first click; clicking the active column flips it.
    $active = request()->query('sort') === $sort;
    $current = in_array(request()->query('dir'), ['asc', 'desc'], true) ? request()->query('dir') : $dir;
    $next = $active ? ($current === 'asc' ? 'desc' : 'asc') : $dir;
@endphp
<th {{ $attributes }} @if ($active) aria-sort="{{ $current === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a href="{{ request()->fullUrlWithQuery(['sort' => $sort, 'dir' => $next, 'page' => null]) }}" class="inline-flex items-center gap-1 uppercase hover:text-gray-800" title="Urutkan menurut kolom ini">
        {{ $slot }}
        <span aria-hidden="true" class="{{ $active ? 'text-[#E6002D]' : 'text-gray-300' }}">{{ $active ? ($current === 'asc' ? '▲' : '▼') : '↕' }}</span>
    </a>
</th>
