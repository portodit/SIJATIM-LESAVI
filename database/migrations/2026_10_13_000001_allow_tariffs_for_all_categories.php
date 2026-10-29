<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tariff without a location category is a general price per service/bandwidth/router
 * ("harga Non Mikrotik"). TariffResolver uses it only when no category-specific tariff matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_masters', function (Blueprint $t) {
            $t->unsignedBigInteger('location_category_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('price_masters', function (Blueprint $t) {
            $t->unsignedBigInteger('location_category_id')->nullable(false)->change();
        });
    }
};
