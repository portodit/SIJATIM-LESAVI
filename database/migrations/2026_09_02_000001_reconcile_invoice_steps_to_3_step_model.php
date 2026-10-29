<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Collapse the invoice wizard from 11 defined steps down to 3 real ones
     * (ssid_list, type_order, preview) plus the non-routed overview row.
     */
    public function up(): void
    {
        DB::table('invoice_steps')->where('step_code', 'ssid_list')->update([
            'step_name' => 'Filling New SSID Data',
            'description' => 'Mengisi data SSID baru untuk periode ini',
            'updated_at' => now(),
        ]);

        DB::table('invoice_steps')->where('step_code', 'type_order')->update([
            'step_name' => 'Input Perubahan Data SSID',
            'description' => 'Bandwidth, pemakaian average, downtime, dan harga',
            'updated_at' => now(),
        ]);

        $deadCodes = ['bandwidth', 'bandwidth_benchmark', 'traffic_usage', 'price', 'sla', 'restitution', 'price_benchmark', 'finalization'];
        $deadIds = DB::table('invoice_steps')->whereIn('step_code', $deadCodes)->pluck('id');
        DB::table('invoice_step_statuses')->whereIn('invoice_step_id', $deadIds)->delete();
        DB::table('invoice_steps')->whereIn('step_code', $deadCodes)->delete();

        if (! DB::table('invoice_steps')->where('step_code', 'preview')->exists()) {
            DB::table('invoice_steps')->insert([
                'step_number' => 3,
                'step_code' => 'preview',
                'step_name' => 'Preview Invoice Bulan Ini',
                'description' => 'Preview data invoice periode berjalan (read-only)',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $previewStepId = DB::table('invoice_steps')->where('step_code', 'preview')->value('id');
        $periodIds = DB::table('invoice_periods')->pluck('id');
        $existing = DB::table('invoice_step_statuses')->where('invoice_step_id', $previewStepId)->pluck('invoice_period_id')->all();
        $missing = array_diff($periodIds->all(), $existing);
        foreach ($missing as $periodId) {
            DB::table('invoice_step_statuses')->insert([
                'invoice_period_id' => $periodId,
                'invoice_step_id' => $previewStepId,
                'status' => 'not_started',
                'error_count' => 0,
                'warning_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Best-effort reverse — cannot restore original ids of deleted step rows.
     */
    public function down(): void
    {
        DB::table('invoice_steps')->where('step_code', 'preview')->delete();

        DB::table('invoice_steps')->where('step_code', 'ssid_list')->update([
            'step_name' => 'Mengelola List SSID',
            'description' => 'SSID baru, perubahan data, dan duplikasi',
            'updated_at' => now(),
        ]);

        DB::table('invoice_steps')->where('step_code', 'type_order')->update([
            'step_name' => 'Type Order & Periode',
            'description' => 'Existing, upgrade, downgrade, PSB, pemutusan, relokasi',
            'updated_at' => now(),
        ]);
    }
};
