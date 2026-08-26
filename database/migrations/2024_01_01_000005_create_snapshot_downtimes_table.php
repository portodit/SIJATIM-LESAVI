<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshot_downtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->onDelete('cascade');
            $table->string('service_id')->nullable();
            $table->decimal('durasi_downtime', 8, 2)->default(0);
            $table->decimal('availability', 5, 4)->nullable();
            $table->decimal('restitusi_tagihan', 15, 2)->default(0);
            $table->decimal('restitusi_over_sla', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('service_id');
            $table->index('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_downtimes');
    }
};
