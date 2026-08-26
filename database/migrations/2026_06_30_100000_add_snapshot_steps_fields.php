<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->tinyInteger('current_step')->default(0)->after('status');
            $table->json('step_statuses')->nullable()->after('current_step');
            $table->string('snapshot_code')->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropColumn(['current_step', 'step_statuses', 'snapshot_code']);
        });
    }
};
