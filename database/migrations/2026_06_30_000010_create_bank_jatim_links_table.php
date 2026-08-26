<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel bank_jatim_links: master seluruh link layanan konektivitas Bank Jatim
     */
    public function up(): void
    {
        Schema::create('bank_jatim_links', function (Blueprint $table) {
            $table->id();
            $table->string('ssid', 191)->unique()->comment('Identitas SID/SSID sebagai kunci bisnis');
            $table->foreignId('office_type_id')->constrained('office_types')->comment('Relasi ke Jenis Kantor');
            $table->unsignedInteger('sequence_in_office_type')->comment('Urutan dalam kelompok Jenis Kantor');
            $table->foreignId('location_category_id')->constrained('location_categories')->comment('Relasi ke Kategori Lokasi');
            $table->foreignId('service_id')->constrained('services')->comment('Relasi ke jenis layanan');
            $table->foreignId('current_bandwidth_option_id')->nullable()->constrained('bandwidth_options')->comment('Bandwidth aktif terkini');
            $table->text('address')->comment('Alamat layanan terkini');
            $table->string('ip_host_telkom', 191)->nullable()->comment('IP Host Telkom');
            $table->date('service_start_date')->comment('Tanggal mulai aktif layanan');
            $table->date('service_end_date')->nullable()->comment('Tanggal berakhir layanan');
            $table->string('link_status', 30)->default('active')->comment('Status: active, inactive, terminated');
            $table->foreignId('last_import_batch_id')->nullable()->constrained('import_batches')->comment('Import terakhir');
            $table->foreignId('created_by')->nullable()->constrained('users')->comment('User pembuat');
            $table->foreignId('updated_by')->nullable()->constrained('users')->comment('User terakhir update');
            $table->text('notes')->nullable()->comment('Catatan tambahan');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('ssid');
            $table->index('link_status');
            $table->index('service_start_date');
            $table->index('service_end_date');
            $table->index(['office_type_id', 'sequence_in_office_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_jatim_links');
    }
};
