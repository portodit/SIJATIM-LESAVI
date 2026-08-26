<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel invoice_summary: ringkasan agregat untuk sheet SUMMARY per periode
     */
    public function up(): void
    {
        Schema::create('invoice_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_period_id')->unique()->constrained('invoice_periods')->cascadeOnDelete()->comment('Periode invoice');

            // Total lokasi
            $table->unsignedInteger('total_locations')->default(0)->comment('Total titik layanan');

            // Existing
            $table->unsignedInteger('existing_count')->default(0)->comment('Total titik Existing');
            $table->decimal('existing_total', 20, 2)->default(0)->comment('Total nilai Existing');

            // PSB (Pasang Baru)
            $table->unsignedInteger('psb_count')->default(0)->comment('Total titik PSB');
            $table->decimal('psb_total', 20, 2)->default(0)->comment('Total nilai PSB');

            // Relokasi
            $table->unsignedInteger('relocation_count')->default(0)->comment('Total titik Relokasi');
            $table->decimal('relocation_total', 20, 2)->default(0)->comment('Total nilai Relokasi');

            // Upgrade
            $table->unsignedInteger('upgrade_count')->default(0)->comment('Total titik Upgrade');
            $table->decimal('upgrade_total', 20, 2)->default(0)->comment('Total nilai Upgrade');

            // Downgrade
            $table->unsignedInteger('downgrade_count')->default(0)->comment('Total titik Downgrade');
            $table->decimal('downgrade_total', 20, 2)->default(0)->comment('Total nilai Downgrade');

            // Pemutusan
            $table->unsignedInteger('termination_count')->default(0)->comment('Total titik Pemutusan');
            $table->decimal('termination_total', 20, 2)->default(0)->comment('Total nilai Pemutusan/Cabut Prorate');

            // SLA & Downtime
            $table->unsignedInteger('below_sla_count')->default(0)->comment('Total titik di bawah SLA');
            $table->unsignedInteger('high_downtime_count')->default(0)->comment('Total titik downtime di atas batas');

            // Restitusi
            $table->decimal('restitution_manual_total', 20, 2)->default(0)->comment('Total Restitusi Tagihan');
            $table->decimal('restitution_over_sla_total', 20, 2)->default(0)->comment('Total Restitusi Over SLA');
            $table->decimal('restitution_grand_total', 20, 2)->default(0)->comment('Total seluruh restitusi');

            // Tagihan
            $table->decimal('subtotal_before_restitution', 20, 2)->default(0)->comment('Subtotal sebelum restitusi');
            $table->decimal('subtotal_after_restitution', 20, 2)->default(0)->comment('Subtotal setelah restitusi');
            $table->decimal('ppn_amount', 20, 2)->default(0)->comment('PPN 11%');
            $table->decimal('grand_total', 20, 2)->default(0)->comment('Grand Total');

            // Catatan
            $table->text('notes')->nullable()->comment('Catatan validasi');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_summaries');
    }
};
