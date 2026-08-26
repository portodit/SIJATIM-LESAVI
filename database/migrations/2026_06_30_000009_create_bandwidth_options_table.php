<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel bandwidth_options: master pilihan bandwidth dengan konversi ke Kbps
     */
    public function up(): void
    {
        Schema::create('bandwidth_options', function (Blueprint $table) {
            $table->id();
            $table->string('bandwidth_code', 50)->unique()->comment('Kode unik bandwidth');
            $table->string('bandwidth_name', 100)->unique()->comment('Nama standar bandwidth');
            $table->unsignedInteger('value_kbps')->nullable()->unique()->comment('Nilai dalam Kbps (NULL = tidak berlaku)');
            $table->text('description')->nullable()->comment('Deskripsi tambahan');
            $table->boolean('is_not_applicable')->default(false)->comment('1 = bukan bandwidth normal');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Urutan tampilan');
            $table->boolean('is_active')->default(true)->index()->comment('Status aktif');
            $table->timestamps();

            // Indexes
            $table->index('sort_order');
            $table->index('value_kbps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bandwidth_options');
    }
};
