<!DOCTYPE html>
<html lang="id" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'SIJATIM' }} - Sistem Invoice Bank Jatim</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

        @php($sidebarGroup = request()->routeIs('invoice-periods.step*', 'invoice-periods.show', 'invoice-periods.advanced', 'imports.*', 'price-masters.*') ? 'work' : 'other')
        <script>
            // Data-entry screens start with a collapsed sidebar; the choice is remembered per screen type.
            (function () {
                var group = '{{ $sidebarGroup }}';
                try {
                    var saved = localStorage.getItem('sijatim.sidebar.' + group);
                    if (saved ? saved === 'collapsed' : group === 'work') document.documentElement.classList.add('sb-collapsed');
                } catch (e) {
                    if (group === 'work') document.documentElement.classList.add('sb-collapsed');
                }
                window.sijToggleSidebar = function () {
                    var collapsed = document.documentElement.classList.toggle('sb-collapsed');
                    try { localStorage.setItem('sijatim.sidebar.' + group, collapsed ? 'collapsed' : 'expanded'); } catch (e) {}
                };
            })();
        </script>
        <style>
            .sij-aside { width: 14rem; transition: width .15s ease; }
            .sij-main { margin-left: 14rem; transition: margin-left .15s ease; }
            .sij-toggle-icon { transition: transform .15s ease; }
            html.sb-collapsed .sij-aside { width: 4rem; }
            html.sb-collapsed .sij-main { margin-left: 4rem; }
            html.sb-collapsed .sij-aside .nav-text { display: none; }
            html.sb-collapsed .sij-aside .sij-center { justify-content: center; padding-left: 0; padding-right: 0; }
            html.sb-collapsed .sij-toggle-icon { transform: rotate(180deg); }
        </style>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @stack('styles')
    </head>
    <body class="h-full font-sans antialiased bg-gray-50 text-gray-900">
        <x-banner />

        <div class="min-h-screen flex">
            <!-- Sidebar -->
            <aside class="sij-aside fixed left-0 top-0 h-screen bg-[#E6002D] text-white z-40 flex flex-col">
                <!-- Logo -->
                <div class="sij-center h-14 flex items-center px-4 border-b border-white/20 shrink-0">
                    <img src="{{ asset('images/logo-telkom-white.png') }}" alt="Telkom" class="w-8 h-8 object-contain shrink-0">
                    <span class="nav-text ml-3 font-bold whitespace-nowrap">SIJATIM</span>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 py-3 space-y-0.5 px-2 overflow-hidden">
                    <a href="{{ route('dashboard') }}" title="Dashboard"
                       class="sij-center flex items-center h-12 px-3 rounded-lg text-sm font-medium transition-colors shrink-0
                              {{ request()->routeIs('dashboard') ? 'bg-white/20' : 'hover:bg-white/10' }}">
                        <svg class="w-6 h-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
                        </svg>
                        <span class="nav-text ml-3 whitespace-nowrap">Dashboard</span>
                    </a>

                    <a href="{{ route('invoice-periods.index') }}" title="Periode Tagihan"
                       class="sij-center flex items-center h-12 px-3 rounded-lg text-sm font-medium transition-colors shrink-0
                              {{ request()->routeIs('invoice-periods.*', 'imports.*') ? 'bg-white/20' : 'hover:bg-white/10' }}">
                        <svg class="w-6 h-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                        </svg>
                        <span class="nav-text ml-3 whitespace-nowrap">Periode Tagihan</span>
                    </a>

                    <a href="{{ route('invoices.index') }}" title="Invoice"
                       class="sij-center flex items-center h-12 px-3 rounded-lg text-sm font-medium transition-colors shrink-0
                              {{ request()->routeIs('invoices.*') ? 'bg-white/20' : 'hover:bg-white/10' }}">
                        <svg class="w-6 h-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                        </svg>
                        <span class="nav-text ml-3 whitespace-nowrap">Invoice</span>
                    </a>

                    <a href="{{ route('price-masters.index') }}" title="Master Harga"
                       class="sij-center flex items-center h-12 px-3 rounded-lg text-sm font-medium transition-colors shrink-0
                              {{ request()->routeIs('price-masters.*') ? 'bg-white/20' : 'hover:bg-white/10' }}">
                        <svg class="w-6 h-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span class="nav-text ml-3 whitespace-nowrap">Master Harga</span>
                    </a>

                </nav>

                <!-- Collapse / expand -->
                <button type="button" onclick="sijToggleSidebar()" title="Ciutkan atau perluas menu" aria-label="Ciutkan atau perluas menu"
                        class="sij-center mx-2 mb-2 flex items-center h-10 px-3 rounded-lg text-sm text-white/80 hover:bg-white/10 transition-colors shrink-0">
                    <svg class="sij-toggle-icon w-6 h-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                    <span class="nav-text ml-3 whitespace-nowrap">Ciutkan menu</span>
                </button>

                <!-- User -->
                <div class="p-3 border-t border-white/20 shrink-0">
                    @if (Auth::check())
                        <div class="sij-center flex items-center" title="{{ Auth::user()->name }}">
                            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center text-white font-semibold shrink-0">
                                {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                            </div>
                            <span class="nav-text ml-3 text-sm font-medium text-white truncate">{{ Auth::user()->name }}</span>
                        </div>
                    @endif
                </div>
            </aside>

            <!-- Main -->
            <main class="sij-main flex-1 min-w-0">
                <header class="h-14 bg-white border-b border-gray-200 flex items-center justify-between px-8">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">{{ $title ?? 'Dashboard' }}</h2>
                        @if(isset($subtitle))
                            <p class="text-sm text-gray-500">{{ $subtitle }}</p>
                        @endif
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ \Carbon\Carbon::now()->format('d F Y') }}
                    </div>
                </header>

                <div class="p-8">
                    @if (session('success'))
                        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">{{ session('success') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ session('error') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                            <p class="font-semibold">Periksa kembali isian Anda:</p>
                            <ul class="list-disc ml-5 mt-1">
                                @foreach ($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @yield('content')
                </div>
            </main>
        </div>

        @stack('modals')
        @livewireScripts
        @stack('scripts')
    </body>
</html>
