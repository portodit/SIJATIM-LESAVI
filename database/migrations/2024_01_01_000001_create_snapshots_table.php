<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->tinyInteger('period_month');
            $table->year('period_year');
            $table->enum('status', ['draft', 'input', 'validation', 'calculation', 'completed'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['period_year', 'period_month']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
