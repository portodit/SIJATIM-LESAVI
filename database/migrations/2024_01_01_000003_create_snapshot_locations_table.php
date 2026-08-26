<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshot_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->onDelete('cascade');
            $table->integer('no')->nullable();
            $table->string('jenis_kantor')->nullable();
            $table->text('alamat')->nullable();
            $table->string('kategori_lokasi')->nullable();
            $table->string('layanan')->nullable();
            $table->string('service_id')->nullable();
            $table->string('bandwidth_eksisting')->nullable();
            $table->string('bandwidth_upgrade')->nullable();
            $table->decimal('sla_target', 5, 2)->default(99.00);
            $table->decimal('harga_eksisting', 15, 2)->default(0);
            $table->decimal('harga_upgrade', 15, 2)->default(0);
            $table->decimal('harga_cabut', 15, 2)->default(0);
            $table->timestamps();

            $table->index('service_id');
            $table->index('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_locations');
    }
};
