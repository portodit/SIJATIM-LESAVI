<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add ssid_temp field for storing temporary SSID before resolution
     */
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('ssid_temp', 100)->nullable()->after('is_new_ssid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('ssid_temp');
        });
    }
};
