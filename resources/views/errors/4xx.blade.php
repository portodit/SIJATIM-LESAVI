@php
    $status = $exception->getStatusCode();
    $titles = [
        403 => 'Akses ditolak',
        404 => 'Halaman tidak ditemukan',
        409 => 'Data sudah berubah',
        419 => 'Sesi sudah habis',
        422 => 'Data belum bisa disimpan',
        423 => 'Periode terkunci',
        429 => 'Terlalu banyak permintaan',
    ];
    $hints = [
        404 => 'Alamat yang dibuka tidak tersedia.',
        409 => 'Muat ulang halaman lalu ulangi langkah Anda.',
        419 => 'Muat ulang halaman lalu ulangi langkah Anda.',
        423 => 'Invoice periode ini sudah difinalisasi. Buat revisi di langkah Terbitkan bila perlu mengubah data.',
        429 => 'Tunggu sebentar lalu coba lagi.',
    ];
    // Messages from abort() in billing controllers are written for operators; show them.
    $message = in_array($status, [409, 422, 423]) && $exception->getMessage() ? $exception->getMessage() : ($hints[$status] ?? 'Terjadi kesalahan pada permintaan.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titles[$status] ?? 'Kesalahan' }} - SIJATIM</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 font-sans flex items-center justify-center p-6">
    <div class="card rounded-xl max-w-lg w-full p-8 text-center">
        <p class="text-sm font-semibold text-[#E6002D]">Kode {{ $status }}</p>
        <h1 class="text-2xl font-bold mt-2">{{ $titles[$status] ?? 'Kesalahan' }}</h1>
        <p class="text-gray-600 mt-4">{{ $message }}</p>
        <div class="flex flex-wrap justify-center gap-3 mt-8">
            <a href="javascript:history.back()" class="btn btn-primary">Kembali</a>
            <a href="{{ url('/dashboard') }}" class="btn btn-secondary">Ke Dashboard</a>
        </div>
    </div>
</body>
</html>
