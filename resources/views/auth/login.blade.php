<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - SIJATIM</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-100">
    <div class="min-h-screen flex">
        <!-- Left -->
        <div class="hidden lg:flex lg:w-1/2 bg-[#0D1B2A] items-center justify-center p-12">
            <div class="max-w-md">
                <div class="flex items-center gap-3 mb-8">
                    <div class="w-12 h-12 rounded-lg overflow-hidden bg-white p-1">
                        <img src="{{ asset('images/logo-telkom.png') }}" alt="Telkom" class="w-full h-full object-contain">
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">SIJATIM</h1>
                        <p class="text-xs text-gray-400">Sistem Invoice Bank Jatim</p>
                    </div>
                </div>
                <h2 class="text-3xl font-bold text-white mb-4">
                    Otomasi Invoice<br>
                    <span class="text-[#E6002D]">Layanan Konektivitas</span>
                </h2>
                <p class="text-gray-400">
                    Sistem informasi untuk mengelola dan mengotomasi proses administrasi invoice layanan konektivitas Bank Jatim.
                </p>
            </div>
        </div>

        <!-- Right -->
        <div class="flex-1 flex items-center justify-center p-8">
            <div class="w-full max-w-sm">
                <div class="lg:hidden flex items-center gap-3 mb-8">
                    <div class="w-10 h-10 rounded-lg overflow-hidden bg-white p-1">
                        <img src="{{ asset('images/logo-telkom.png') }}" alt="Telkom" class="w-full h-full object-contain">
                    </div>
                    <h1 class="text-lg font-bold text-gray-900">SIJATIM</h1>
                </div>

                <h2 class="text-xl font-bold text-gray-900 mb-2">Masuk</h2>
                <p class="text-gray-500 mb-6">Gunakan kredensial akun Anda</p>

                @if ($errors->any())
                    <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                               class="input" placeholder="email@telkom.co.id">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                        <input type="password" id="password" name="password" required
                               class="input" placeholder="••••••••">
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="remember" class="rounded border-gray-300">
                            <span class="text-sm text-gray-600">Ingat saya</span>
                        </label>
                    </div>

                    <button type="submit" class="w-full btn btn-primary py-2.5">
                        Masuk
                    </button>
                </form>

                <div class="mt-6 p-3 bg-gray-50 rounded-lg text-sm">
                    <p class="font-medium text-gray-700 mb-1">Demo Account:</p>
                    <p class="text-gray-500">portodit@gmail.com / admin123</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
