<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_service_changes', function (Blueprint $t) {
            $t->id();
            $t->string('sid');
            $t->string('event_type');
            $t->date('effective_date');
            $t->json('attributes');
            $t->string('status')->default('scheduled'); // scheduled | applied | cancelled
            $t->foreignId('applied_period_id')->nullable()->constrained('invoice_periods')->nullOnDelete();
            $t->unsignedBigInteger('applied_event_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->index(['status', 'effective_date']);
            $t->index('sid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_service_changes');
    }
};
