<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel services: master jenis layanan konektivitas Telkom
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('service_code', 100)->unique()->comment('Kode unik layanan');
            $table->string('service_name', 191)->unique()->comment('Nama resmi layanan');
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
        Schema::dropIfExists('services');
    }
};
