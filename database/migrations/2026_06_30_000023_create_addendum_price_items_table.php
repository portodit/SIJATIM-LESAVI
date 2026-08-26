<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sub Tahap 1.9: Benchmarking harga dengan addendum
     * Item harga dari addendum kontrak
     */
    public function up(): void
    {
        Schema::create('addendum_price_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addendum_contract_id')->constrained('addendum_contracts')->onDelete('cascade');

            // Referensi bandwidth
            $table->foreignId('bandwidth_option_id')->nullable()->constrained('bandwidth_options');
            $table->string('bandwidth_description')->nullable()->comment('Deskripsi bandwidth (misal: 20 Mbps)');

            // Harga dari addendum
            $table->decimal('price_monthly', 15, 2)->nullable()->comment('Harga bulanan dari addendum');
            $table->decimal('price_installasi', 15, 2)->nullable()->comment('Biaya installasi');
            $table->decimal('price_nrc', 15, 2)->nullable()->comment('Non Recurring Cost');
            $table->decimal('price_rc', 15, 2)->nullable()->comment('Recurring Cost per bulan');

            // Kategori layanan
            $table->string('service_category')->nullable()->comment('Kategori: INTERNET, VPN, DEDICATED, dll');
            $table->string('tier')->nullable()->comment('Tier: GOLD, SILVER, BRONZE, dll');

            // Metadata
            $table->timestamps();

            // Indexes
            $table->index('addendum_contract_id');
            $table->index('bandwidth_option_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addendum_price_items');
    }
};
