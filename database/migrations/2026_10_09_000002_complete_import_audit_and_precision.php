<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $t) {
            $t->json('resolution_history')->nullable();
        });
        Schema::table('import_batches', function (Blueprint $t) {
            $t->string('status', 30)->default('pending')->change();
        });
        Schema::table('invoice_traffic_usages', function (Blueprint $t) {
            foreach (['usage_avg_eksisting', 'usage_avg_upgrade', 'traffic_total_eksisting', 'traffic_total_upgrade'] as $c) {
                $t->decimal($c, 20, 6)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Restore the database backup to roll back billing.');
    }
};
