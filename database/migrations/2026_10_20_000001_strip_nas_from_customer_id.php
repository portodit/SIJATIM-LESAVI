<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->stripNasFrom('billing_profiles', 'settings');
        $this->stripNasFrom('invoice_periods', 'invoice_metadata');
    }

    public function down(): void {}

    private function stripNasFrom(string $table, string $column): void
    {
        DB::table($table)
            ->select(['id', $column])
            ->orderBy('id')
            ->get()
            ->each(function ($row) use ($table, $column) {
                $data = json_decode($row->{$column} ?? 'null', true);

                if (! is_array($data) || ! isset($data['customer_id']) || ! is_string($data['customer_id'])) {
                    return;
                }

                if (! str_starts_with($data['customer_id'], 'NAS')) {
                    return;
                }

                $data['customer_id'] = substr($data['customer_id'], strlen('NAS'));

                DB::table($table)
                    ->where('id', $row->id)
                    ->update([$column => json_encode($data)]);
            });
    }
};
