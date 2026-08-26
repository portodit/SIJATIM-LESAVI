<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel invoice_outputs: menyimpan file Excel/PDF yang sudah di-generate
     */
    public function up(): void
    {
        Schema::create('invoice_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_period_id')->constrained('invoice_periods')->cascadeOnDelete()->comment('Periode invoice');
            $table->string('output_type', 50)->comment('Tipe output: excel_invoice, pdf_cetak, lampiran');
            $table->string('file_name', 255)->comment('Nama file');
            $table->string('file_path', 500)->comment('Path/lokasi file');
            $table->string('file_hash', 64)->nullable()->comment('SHA256 hash untuk validasi');
            $table->unsignedBigInteger('file_size')->nullable()->comment('Ukuran file dalam bytes');
            $table->enum('status', ['draft', 'final', 'archived'])->default('draft')->comment('Status file');
            $table->unsignedInteger('version')->default(1)->comment('Versi file');
            $table->foreignId('generated_by')->nullable()->constrained('users')->comment('User yang generate');
            $table->text('notes')->nullable()->comment('Catatan tambahan');
            $table->timestamps();

            // Indexes
            $table->index(['invoice_period_id', 'output_type']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_outputs');
    }
};
