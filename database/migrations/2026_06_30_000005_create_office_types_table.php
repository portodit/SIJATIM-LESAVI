<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel office_types: master Jenis Kantor Bank Jatim
     */
    public function up(): void
    {
        Schema::create('office_types', function (Blueprint $table) {
            $table->id();
            $table->string('office_type_code', 50)->unique()->comment('Kode unik Jenis Kantor');
            $table->string('office_type_name', 191)->unique()->comment('Nama Jenis Kantor');
            $table->text('description')->nullable()->comment('Deskripsi tambahan');
            $table->unsignedSmallInteger('sort_order')->unique()->comment('Urutan dalam invoice');
            $table->boolean('is_active')->default(true)->index()->comment('Status aktif');
            $table->timestamps();

            // Index for sorting
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_types');
    }
};
