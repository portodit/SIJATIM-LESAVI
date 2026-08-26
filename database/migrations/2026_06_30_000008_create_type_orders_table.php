<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel type_orders: master kondisi/perubahan layanan per periode
     */
    public function up(): void
    {
        Schema::create('type_orders', function (Blueprint $table) {
            $table->id();
            $table->string('type_order_code', 50)->unique()->comment('Kode unik Type Order');
            $table->string('type_order_name', 100)->unique()->comment('Nama Type Order');
            $table->text('description')->nullable()->comment('Deskripsi dan aturan Type Order');
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
        Schema::dropIfExists('type_orders');
    }
};
