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
     * Hasil perbandingan bandwidth invoice vs bandwidth EoS
     */
    public function up(): void
    {
        Schema::create('bandwidth_benchmark_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->onDelete('cascade');
            $table->foreignId('invoice_period_id')->constrained('invoice_periods')->onDelete('cascade');

            // Bandwidth dari invoice
            $table->string('invoice_bandwidth')->nullable()->comment('Bandwidth dari invoice');
            $table->foreignId('invoice_bandwidth_option_id')->nullable()->constrained('bandwidth_options');

            // Bandwidth dari EoS
            $table->string('eos_bandwidth')->nullable()->comment('Bandwidth dari EoS');
            $table->foreignId('eos_bandwidth_option_id')->nullable()->constrained('bandwidth_options');

            // Hasil comparison
            $table->enum('comparison_result', ['match', 'upgrade', 'downgrade', 'not_in_eos', 'not_in_invoice', 'different'])->nullable()
                  ->comment('Hasil perbandingan');
            $table->text('notes')->nullable()->comment('Catatan jika ada perbedaan');

            $table->timestamps();

            // Indexes
            $table->index('invoice_line_id');
            $table->index('invoice_period_id');
            $table->index('comparison_result');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bandwidth_benchmark_results');
    }
};
