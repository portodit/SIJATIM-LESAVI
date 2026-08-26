<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel invoice_steps: master tahapan pembuatan invoice
     */
    public function up(): void
    {
        Schema::create('invoice_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('step_number')->unique()->comment('Nomor urut tahapan');
            $table->string('step_code', 50)->unique()->comment('Kode teknis tahapan');
            $table->string('step_name', 191)->comment('Nama tahapan untuk UI');
            $table->text('description')->nullable()->comment('Deskripsi tahapan');
            $table->boolean('is_required')->default(true)->comment('Wajib diselesaikan');
            $table->boolean('is_active')->default(true)->comment('Tahapan aktif');
            $table->timestamps();
        });

        // Seed data awal untuk 11 sub-tahap (0-10)
        $steps = [
            ['step_number' => 0, 'step_code' => 'overview', 'step_name' => 'Overview Snapshot', 'description' => 'Ringkasan progres dan validasi keseluruhan'],
            ['step_number' => 1, 'step_code' => 'ssid_list', 'step_name' => 'Mengelola List SSID', 'description' => 'SSID baru, perubahan data, dan duplikasi'],
            ['step_number' => 2, 'step_code' => 'type_order', 'step_name' => 'Type Order & Periode', 'description' => 'Existing, upgrade, downgrade, PSB, pemutusan, relokasi'],
            ['step_number' => 3, 'step_code' => 'bandwidth', 'step_name' => 'Data Bandwidth', 'description' => 'Bandwidth eksisting, upgrade, downgrade'],
            ['step_number' => 4, 'step_code' => 'bandwidth_benchmark', 'step_name' => 'Benchmark Bandwidth EoS', 'description' => 'Validasi silang dengan data titik jaringan'],
            ['step_number' => 5, 'step_code' => 'traffic_usage', 'step_name' => 'Data Trafik Jaringan', 'description' => 'Pemakaian average dari MRTG'],
            ['step_number' => 6, 'step_code' => 'price', 'step_name' => 'Harga Layanan', 'description' => 'Harga eksisting, upgrade, downgrade, PSB, prorate'],
            ['step_number' => 7, 'step_code' => 'sla', 'step_name' => 'Data Gangguan & SLA', 'description' => 'Downtime, SLA actual, availability'],
            ['step_number' => 8, 'step_code' => 'restitution', 'step_name' => 'Nilai Restitusi', 'description' => 'Restitusi tagihan dan restitusi over SLA'],
            ['step_number' => 9, 'step_code' => 'price_benchmark', 'step_name' => 'Benchmark Harga Addendum', 'description' => 'Validasi akhir harga sebelum finalisasi'],
            ['step_number' => 10, 'step_code' => 'finalization', 'step_name' => 'Finalisasi Invoice', 'description' => 'Generate Excel dan PDF invoice final'],
        ];

        foreach ($steps as $step) {
            \DB::table('invoice_steps')->insert($step);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_steps');
    }
};
