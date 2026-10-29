<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['usage_avg_eksisting', 'usage_avg_upgrade', 'traffic_total_eksisting', 'traffic_total_upgrade'] as $column) {
            if (Schema::hasColumn('invoice_traffic_usages', $column) && ! DB::table('invoice_traffic_usages')->whereNotNull($column)->exists()) {
                Schema::table('invoice_traffic_usages', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Restore database backup to roll back billing.');
    }
};
