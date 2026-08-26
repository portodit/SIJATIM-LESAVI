<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshot_traffics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->onDelete('cascade');
            $table->string('service_id')->nullable();
            $table->decimal('pemakaian_average_eksisting', 15, 4)->default(0);
            $table->decimal('pemakaian_average_upgrade', 15, 4)->default(0);
            $table->decimal('trafik_total_eksisting', 15, 4)->default(0);
            $table->decimal('trafik_total_upgrade', 15, 4)->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('service_id');
            $table->index('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_traffics');
    }
};
