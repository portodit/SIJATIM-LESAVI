<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_bandwidths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->onDelete('cascade');
            $table->foreignId('existing_bw_id')->nullable()->constrained('bandwidth_options');
            $table->foreignId('upgrade_bw_id')->nullable()->constrained('bandwidth_options');
            $table->foreignId('downgrade_bw_id')->nullable()->constrained('bandwidth_options');
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
        Schema::dropIfExists('invoice_bandwidths');
    }
};
