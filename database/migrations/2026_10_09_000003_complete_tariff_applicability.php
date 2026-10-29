<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_masters', function (Blueprint $t) {
            $t->dropUnique('price_masters_unique_combo');
            $t->index(['location_category_id', 'service_id', 'bandwidth_option_id', 'router_mode', 'effective_start_date'], 'billing_tariff_lookup');
        });
        Schema::table('bank_jatim_links', function (Blueprint $t) {
            $t->foreignId('addendum_contract_id')->nullable()->constrained('addendum_contracts');
        });
        Schema::table('outage_events', function (Blueprint $t) {
            $t->dateTime('coverage_start')->nullable();
            $t->dateTime('coverage_end')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Restore database backup to roll back billing.');
    }
};
