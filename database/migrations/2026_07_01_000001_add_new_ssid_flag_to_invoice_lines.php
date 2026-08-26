<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add flag for newly added SSIDs in this period
     */
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->boolean('is_new_ssid')->default(false)->after('effective_date');
            $table->string('new_ssid_note', 255)->nullable()->after('is_new_ssid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['is_new_ssid', 'new_ssid_note']);
        });
    }
};
