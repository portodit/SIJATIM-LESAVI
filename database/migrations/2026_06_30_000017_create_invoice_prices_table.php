<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sub Tahap 1.6: Mengisi dan menghitung harga layanan
     * Kolom: Eksisting Harga, Upgrade Harga, Downgrade Harga,
     *        Cabut (Prorate), PSB, Relokasi, TOTAL
     * Formula: TOTAL = SUM(Y:AD)
     */
    public function up(): void
    {
        Schema::create('invoice_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->onDelete('cascade');

            // Harga per komponen (Lookup dari price_master)
            $table->decimal('harga_eksisting', 15, 2)->nullable()->comment('Harga layanan eksisting per bulan');
            $table->decimal('harga_upgrade', 15, 2)->nullable()->comment('Harga upgrade bandwidth');
            $table->decimal('harga_downgrade', 15, 2)->nullable()->comment('Harga downgrade bandwidth');
            $table->decimal('harga_cabut_prorate', 15, 2)->nullable()->comment('Harga prorate saat pemutusan');
            $table->decimal('harga_psb', 15, 2)->nullable()->comment('Harga pasang baru');
            $table->decimal('harga_relokasi', 15, 2)->nullable()->comment('Harga relokasi');

            // Total (Calculated: Formula SUM)
            $table->decimal('total', 15, 2)->nullable()->comment('Total harga sebelum restitusi');

            // Metadata
            $table->timestamps();

            // Indexes
            $table->index('invoice_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_prices');
    }
};
