<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel price_masters: master harga bulanan resmi layanan konektivitas Bank Jatim
     */
    public function up(): void
    {
        Schema::create('price_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_category_id')->constrained('location_categories')->comment('Kategori Lokasi');
            $table->foreignId('service_id')->constrained('services')->comment('Jenis Layanan');
            $table->foreignId('bandwidth_option_id')->nullable()->constrained('bandwidth_options')->comment('Bandwidth (NULL = tidak berlaku)');
            $table->decimal('monthly_price', 15, 2)->comment('Harga bulanan sebelum PPN');
            $table->decimal('monthly_price_with_ppn', 15, 2)->nullable()->comment('Harga bulanan setelah PPN');
            $table->decimal('installation_price', 15, 2)->nullable()->comment('Biaya pasang baru/PSB sebelum PPN');
            $table->decimal('installation_price_with_ppn', 15, 2)->nullable()->comment('Biaya pasang baru setelah PPN');
            $table->string('addendum_reference', 191)->nullable()->comment('Referensi dokumen addendum');
            $table->date('effective_start_date')->comment('Tanggal mulai berlaku');
            $table->date('effective_end_date')->nullable()->comment('Tanggal akhir berlaku');
            $table->text('notes')->nullable()->comment('Catatan tambahan');
            $table->boolean('is_active')->default(true)->index()->comment('Status aktif');
            $table->timestamps();

            // Unique constraint untuk kombinasi
            $table->unique(
                ['location_category_id', 'service_id', 'bandwidth_option_id', 'effective_start_date'],
                'price_masters_unique_combo'
            );

            // Indexes
            $table->index('effective_start_date');
            $table->index('effective_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_masters');
    }
};
