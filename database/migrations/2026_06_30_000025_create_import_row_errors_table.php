<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Support table: Error per baris saat import
     */
    public function up(): void
    {
        Schema::create('import_row_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->onDelete('cascade');

            // Row info
            $table->integer('row_number')->comment('Nomor baris di file yang error');
            $table->string('ssid_value')->nullable()->comment('Nilai SSID yang error');

            // Error details
            $table->string('error_type')->comment('Tipe error: validation, duplicate, not_found, invalid_format');
            $table->text('error_message')->comment('Pesan error lengkap');
            $table->text('raw_data')->nullable()->comment('Data mentah dari baris tersebut');

            // Resolution
            $table->enum('status', ['pending', 'resolved', 'ignored'])->default('pending')->comment('Status error');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable()->comment('Catatan resolusi');

            $table->timestamps();

            // Indexes
            $table->index('import_batch_id');
            $table->index('error_type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_row_errors');
    }
};
