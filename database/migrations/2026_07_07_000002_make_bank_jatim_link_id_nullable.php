<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make bank_jatim_link_id nullable to support pending new SSIDs
     */
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_jatim_link_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_jatim_link_id')->nullable(false)->change();
        });
    }
};
