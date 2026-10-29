<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel import_batches: tracking riwayat import file per periode
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_period_id')->constrained('invoice_periods')->cascadeOnDelete()->comment('Periode invoice');
            $table->string('import_type', 50)->comment('Tipe import: ssid, bandwidth, traffic, sla, eos, addendum');
            $table->string('file_name')->comment('Nama file asli yang diupload');
            $table->string('original_filename')->nullable()->comment('Nama file original');
            $table->unsignedInteger('total_rows')->default(0)->comment('Total baris dalam file');
            $table->unsignedInteger('success_rows')->default(0)->comment('Baris berhasil diproses');
            $table->unsignedInteger('failed_rows')->default(0)->comment('Baris gagal diproses');
            $table->text('error_log')->nullable()->comment('Log error jika ada');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->comment('Status proses import');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete()->comment('User yang upload');
            $table->json('metadata')->nullable()->comment('Metadata tambahan (column mapping, dll)');
            $table->timestamps();

            $table->index(['invoice_period_id', 'import_type']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
