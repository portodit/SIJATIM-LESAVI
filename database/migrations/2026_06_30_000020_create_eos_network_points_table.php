<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sub Tahap 1.4: Benchmarking bandwidth dengan EoS
     * Data mentah upload EoS (End of Service) untuk dibandingkan dengan bandwidth invoice
     */
    public function up(): void
    {
        Schema::create('eos_network_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_period_id')->constrained('invoice_periods')->onDelete('cascade');

            // Data upload EoS
            $table->string('point_name')->comment('Nama titik jaringan EoS');
            $table->string('location')->nullable()->comment('Lokasi titik');
            $table->string('ssid')->nullable()->comment('SSID terkait');
            $table->string('ip_address')->nullable()->comment('IP Address');

            // Bandwidth info dari EoS
            $table->string('eos_bandwidth')->nullable()->comment('Bandwidth dari EoS (sebelum upgrade/downgrade)');

            // Metadata upload
            $table->foreignId('upload_batch_id')->nullable()->constrained('import_batches');
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['invoice_period_id', 'ssid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eos_network_points');
    }
};
