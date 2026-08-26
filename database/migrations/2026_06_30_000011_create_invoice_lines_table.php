<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel invoice_lines: detail baris invoice per periode (setiap SSID per periode)
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_period_id')->constrained('invoice_periods')->cascadeOnDelete()->comment('Periode invoice');
            $table->foreignId('bank_jatim_link_id')->constrained('bank_jatim_links')->comment('Relasi ke master link');
            $table->foreignId('type_order_id')->constrained('type_orders')->comment('Type Order periode ini');
            $table->unsignedInteger('line_number')->comment('Nomor baris dalam invoice (generated)');

            // Tanggal efektif perubahan
            $table->date('effective_date')->nullable()->comment('Tanggal efektif perubahan');

            // Alamat snapshot untuk invoice
            $table->text('address_snapshot')->nullable()->comment('Alamat yang digunakan di invoice');
            $table->text('old_address_snapshot')->nullable()->comment('Alamat lama (untuk relokasi)');
            $table->text('new_address')->nullable()->comment('Alamat baru (untuk relokasi)');

            // Bandwidth
            $table->foreignId('existing_bandwidth_option_id')->nullable()->constrained('bandwidth_options')->comment('Bandwidth eksisting');
            $table->foreignId('upgrade_bandwidth_option_id')->nullable()->constrained('bandwidth_options')->comment('Bandwidth upgrade');
            $table->foreignId('downgrade_bandwidth_option_id')->nullable()->constrained('bandwidth_options')->comment('Bandwidth downgrade');

            // Trafik
            $table->unsignedBigInteger('usage_avg_existing')->nullable()->comment('Pemakaian average eksisting (Kbps)');
            $table->unsignedBigInteger('usage_avg_upgrade')->nullable()->comment('Pemakaian average upgrade (Kbps)');
            $table->unsignedBigInteger('traffic_total_existing')->nullable()->comment('Trafik total eksisting');
            $table->unsignedBigInteger('traffic_total_upgrade')->nullable()->comment('Trafik total upgrade');

            // SLA & Downtime
            $table->unsignedSmallInteger('downtime_days')->default(0)->comment('Downtime hari');
            $table->unsignedSmallInteger('downtime_hours')->default(0)->comment('Downtime jam');
            $table->unsignedSmallInteger('downtime_minutes')->default(0)->comment('Downtime menit');
            $table->text('sla_note')->nullable()->comment('Keterangan SLA');
            $table->decimal('sla_actual', 8, 6)->nullable()->comment('SLA Actual (persentase)');
            $table->decimal('sla_target', 8, 6)->default(0.99)->comment('SLA Target (default 99%)');
            $table->decimal('availability_hours', 10, 2)->nullable()->comment('Availability dalam jam');

            // Harga
            $table->decimal('price_existing', 15, 2)->nullable()->comment('Harga eksisting');
            $table->decimal('price_upgrade', 15, 2)->nullable()->comment('Harga upgrade prorate');
            $table->decimal('price_downgrade', 15, 2)->nullable()->comment('Harga downgrade prorate');
            $table->decimal('price_prorate', 15, 2)->nullable()->comment('Harga prorate');
            $table->decimal('price_psb', 15, 2)->nullable()->comment('Biaya pasang baru');
            $table->decimal('price_relocation', 15, 2)->nullable()->comment('Biaya relokasi');
            $table->decimal('price_total', 15, 2)->nullable()->comment('Total harga sebelum restitusi');

            // Restitusi
            $table->decimal('restitution_manual', 15, 2)->default(0)->comment('Restitusi manual');
            $table->decimal('restitution_over_sla', 15, 2)->default(0)->comment('Restitusi over SLA');
            $table->decimal('restitution_total', 15, 2)->default(0)->comment('Total restitusi');
            $table->decimal('price_after_restitution', 15, 2)->nullable()->comment('Harga setelah restitusi');

            // Validation status
            $table->string('bandwidth_status', 50)->nullable()->comment('Status validasi bandwidth');
            $table->string('price_status', 50)->nullable()->comment('Status validasi harga');
            $table->text('validation_notes')->nullable()->comment('Catatan validasi');

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('line_number');
            $table->index(['invoice_period_id', 'bank_jatim_link_id']);
            $table->index('effective_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
