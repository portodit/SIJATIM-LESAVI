<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_traffic_usages', function (Blueprint $t) {
            foreach (['inbound_kbps', 'outbound_kbps', 'usage_avg_existing_kbps', 'usage_avg_upgrade_kbps'] as $c) {
                $t->decimal($c, 28, 12)->nullable()->change();
            }
        });
        Schema::table('invoice_lines', function (Blueprint $t) {
            foreach (['usage_avg_existing', 'usage_avg_upgrade'] as $c) {
                $t->decimal($c, 28, 12)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Restore database backup to roll back billing.');
    }
};
