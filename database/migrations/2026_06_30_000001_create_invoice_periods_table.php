<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel invoice_periods: menyimpan folder kerja periode tagihan bulanan Bank Jatim
     */
    public function up(): void
    {
        Schema::create('invoice_periods', function (Blueprint $table) {
            $table->id();

            // Kode periode unik, contoh: JUN26, SEP25
            $table->string('period_code', 10)->unique()->comment('Kode periode tagihan');

            // Bulan dan tahun tagihan
            $table->unsignedTinyInteger('month')->index()->comment('Bulan: 1-12');
            $table->unsignedSmallInteger('year')->index()->comment('Tahun: 2025, 2026');

            // Tanggal periode (auto generate)
            $table->date('start_date')->comment('Tanggal awal periode');
            $table->date('end_date')->comment('Tanggal akhir periode');
            $table->unsignedTinyInteger('billing_days')->default(30)->comment('Hari dasar prorate');

            // Status dan progres
            $table->enum('status', ['draft', 'in_progress', 'validation', 'finalized', 'revision', 'archived'])
                ->default('draft')
                ->index()
                ->comment('Status folder kerja');
            $table->string('current_step_code', 50)->nullable()->index()->comment('Step terakhir dikerjakan');
            $table->unsignedTinyInteger('progress_percentage')->default(0)->comment('Progres 0-100%');

            // Lock dan finalisasi
            $table->boolean('is_locked')->default(false)->index()->comment('Dokumen terkunci');
            $table->timestamp('finalized_at')->nullable()->comment('Waktu finalisasi');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->onDelete('set null')->comment('User finalisasi');

            // Audit
            $table->foreignId('created_by')->constrained('users')->comment('User pembuat');
            $table->text('notes')->nullable()->comment('Catatan periode');
            $table->timestamps();
            $table->softDeletes();

            // Constraints
            $table->unique(['month', 'year'], 'invoice_periods_month_year_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_periods');
    }
};
