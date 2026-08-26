<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sub Tahap 1.5: Mengisi data trafik jaringan
     * Kolom: Usage Average (Eksisting/Upgrade) dalam Kbps
     *        Trafik Total (Eksisting/Upgrade) dalam Kbps
     *        (Trafik Total dihitung dari formula Excel)
     */
    public function up(): void
    {
        Schema::create('invoice_traffic_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->onDelete('cascade');

            // Usage Average (Input Manual - Kolom O, P)
            $table->decimal('usage_avg_existing_kbps', 15, 4)->nullable()->comment('Pemakaian average eksisting dalam Kbps');
            $table->decimal('usage_avg_upgrade_kbps', 15, 4)->nullable()->comment('Pemakaian average upgrade dalam Kbps');

            // Trafik Total (Calculated - Kolom Q, R)
            // Formula: =(Usage*24*3600*30/8)/1000000
            $table->decimal('traffic_total_existing_kbps', 18, 4)->nullable()->comment('Trafik total eksisting dalam Kbps');
            $table->decimal('traffic_total_upgrade_kbps', 18, 4)->nullable()->comment('Trafik total upgrade dalam Kbps');

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
        Schema::dropIfExists('invoice_traffic_usages');
    }
};
