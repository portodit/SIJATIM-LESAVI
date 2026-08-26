<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sub Tahap 1.7: Mengisi data gangguan layanan dan SLA
     * Kolom: Durasi Downtime, SLA Actual, SLA PKS, Availability
     * Formula: SLA Actual = (Availability - Downtime) / Availability
     */
    public function up(): void
    {
        Schema::create('invoice_slas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->onDelete('cascade');

            // Input Manual
            $table->decimal('downtime_hours', 10, 4)->nullable()->comment('Durasi downtime dalam jam per bulan');
            $table->decimal('sla_pks', 8, 6)->nullable()->comment('SLA PKS (dalam persen)');
            $table->decimal('availability_hours', 10, 4)->nullable()->comment('Availability dalam jam per bulan (720 jam)');

            // Calculated (Formula)
            $table->decimal('sla_actual', 8, 6)->nullable()->comment('SLA Actual = (Availability - Downtime) / Availability');

            $table->timestamps();

            // Indexes
            $table->index('invoice_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_slas');
    }
};
