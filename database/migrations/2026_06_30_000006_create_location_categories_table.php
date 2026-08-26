<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel location_categories: master Kategori Lokasi Bank Jatim
     */
    public function up(): void
    {
        Schema::create('location_categories', function (Blueprint $table) {
            $table->id();
            $table->string('location_category_code', 100)->unique()->comment('Kode unik Kategori Lokasi');
            $table->string('location_category_name', 191)->unique()->comment('Nama Kategori Lokasi');
            $table->text('description')->nullable()->comment('Deskripsi tambahan');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Urutan tampilan');
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
        Schema::dropIfExists('location_categories');
    }
};
