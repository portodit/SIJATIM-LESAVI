<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sub Tahap 1.8: Menghitung nilai restitusi
     * Kolom: Restitusi Tagihan, Restitusi Over SLA, Total After Restitusi
     * Formula: Restitusi Over SLA = IF(SLA < 99%, ((Availability - Downtime)/720)*HargaEksisting, 0)
     * Formula: Total After = Total + Restitusi
     */
    public function up(): void
    {
        Schema::create('invoice_restitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->onDelete('cascade');

            // Input / Calculated
            $table->decimal('restitusi_tagihan', 15, 2)->nullable()->comment('Nilai restitusi tagihan');
            $table->decimal('restitusi_over_sla', 15, 2)->nullable()->comment('Restitusi Over SLA (jika SLA < 99%)');

            // Calculated
            $table->decimal('total_after_restitusi', 15, 2)->nullable()->comment('Total harga setelah restitusi');

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
        Schema::dropIfExists('invoice_restitutions');
    }
};
