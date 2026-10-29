<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel invoice_step_statuses: tracking progres setiap step per periode
     */
    public function up(): void
    {
        Schema::create('invoice_step_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_period_id')->constrained('invoice_periods')->cascadeOnDelete()->comment('Periode invoice');
            $table->foreignId('invoice_step_id')->constrained('invoice_steps')->restrictOnDelete()->comment('Master step');
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'error', 'skipped'])
                ->default('not_started')
                ->index()
                ->comment('Status pengerjaan');
            $table->timestamp('started_at')->nullable()->comment('Waktu mulai');
            $table->timestamp('completed_at')->nullable()->comment('Waktu selesai');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete()->comment('User yang selesaikan');
            $table->unsignedInteger('error_count')->default(0)->comment('Jumlah error');
            $table->unsignedInteger('warning_count')->default(0)->comment('Jumlah warning');
            $table->foreignId('last_import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete()->comment('Import terakhir');
            $table->text('notes')->nullable()->comment('Catatan');
            $table->timestamps();

            // Unique: 1 periode hanya punya 1 record per step
            $table->unique(['invoice_period_id', 'invoice_step_id'], 'invoice_step_statuses_period_step_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_step_statuses');
    }
};
