<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->onDelete('cascade');
            $table->string('invoice_number')->unique();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->integer('total_locations')->default(0);
            $table->string('file_path')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('generated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('invoice_number');
            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
