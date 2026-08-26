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
     * Dokumen addendum kontrak
     */
    public function up(): void
    {
        Schema::create('addendum_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number')->unique()->comment('Nomor kontrak addendum');
            $table->string('contract_title')->nullable()->comment('Judul kontrak');
            $table->date('effective_date')->nullable()->comment('Tanggal efektif kontrak');
            $table->date('end_date')->nullable()->comment('Tanggal akhir kontrak');
            $table->enum('status', ['active', 'expired', 'terminated'])->default('active')->comment('Status kontrak');

            // Metadata
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();

            // Indexes
            $table->index('contract_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addendum_contracts');
    }
};
