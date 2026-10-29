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
     * Hasil perbandingan harga invoice vs addendum
     */
    public function up(): void
    {
        Schema::create('price_benchmark_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->onDelete('cascade');
            $table->foreignId('invoice_period_id')->constrained('invoice_periods')->onDelete('cascade');

            // Harga dari invoice
            $table->decimal('invoice_price', 15, 2)->nullable()->comment('Harga dari invoice');
            $table->string('invoice_price_type')->nullable()->comment('Tipe harga: eksisting, upgrade, downgrade, psb');

            // Harga dari addendum
            $table->foreignId('addendum_price_item_id')->nullable()->constrained('addendum_price_items');
            $table->decimal('addendum_price', 15, 2)->nullable()->comment('Harga dari addendum');

            // Hasil comparison
            $table->enum('comparison_result', ['match', 'higher', 'lower', 'not_in_addendum', 'not_in_invoice', 'different'])->nullable()
                ->comment('Hasil perbandingan');
            $table->decimal('difference', 15, 2)->nullable()->comment('Selisih harga');
            $table->decimal('difference_percent', 8, 4)->nullable()->comment('Persentase selisih');
            $table->text('notes')->nullable()->comment('Catatan');

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
        Schema::dropIfExists('price_benchmark_results');
    }
};
