<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_traffic_usages', function (Blueprint $t) {
            foreach (['usage_avg_existing_kbps', 'usage_avg_upgrade_kbps', 'traffic_total_existing_kbps', 'traffic_total_upgrade_kbps'] as $column) {
                $t->decimal($column, 20, 6)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Restore database backup to roll back billing.');
    }
};
