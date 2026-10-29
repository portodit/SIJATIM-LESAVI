<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('version');
            $t->json('settings');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['name', 'version']);
        });
        $profile = DB::table('billing_profiles')->insertGetId([
            'name' => 'Bank Jatim / Telkom', 'version' => 1, 'is_active' => true,
            'settings' => json_encode([
                'sla_target' => '0.99', 'proration_divisor' => 30, 'tax_rate' => '0.11',
                'credit_basis' => 'gross_before_tax', 'daily_rounding' => 'half_up',
                'invoice_prefix' => '4807060000210', 'due_day' => 20,
                'customer_name' => 'PT BANK PEMBANGUNAN DAERAH JAWA TIMUR TBK',
                'supplier_name' => 'TELKOM Solution', 'npwp' => '01.110.116.9-054.000',
                'customer_id' => '01-0501-505-02913365', 'account_number' => '4807060',
            ]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('invoice_periods', function (Blueprint $t) {
            $t->foreignId('billing_profile_id')->nullable()->constrained();
            $t->json('invoice_metadata')->nullable();
            $t->json('calculation')->nullable();
            $t->json('sla_coverage')->nullable();
            $t->unsignedInteger('input_version')->default(1);
            $t->unsignedInteger('calculated_input_version')->nullable();
            $t->timestamp('calculated_at')->nullable();
        });
        Schema::table('bank_jatim_links', function (Blueprint $t) {
            $t->date('billing_start_date')->nullable();
            $t->date('billing_end_date')->nullable();
            $t->string('physical_router_status')->default('unknown');
            $t->string('billable_router_status')->default('none');
            $t->date('router_billing_from')->nullable();
        });
        Schema::table('price_masters', function (Blueprint $t) {
            $t->foreignId('addendum_contract_id')->nullable()->constrained('addendum_contracts');
            $t->string('router_mode')->default('none');
            $t->string('sid')->nullable();
            $t->decimal('relocation_price', 18, 2)->nullable()->default(0);
        });
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('downtime_seconds')->nullable();
            $t->json('service_snapshot')->nullable();
            $t->json('legacy_inputs')->nullable();
            $t->text('traffic_exception_reason')->nullable();
            foreach (['usage_avg_existing', 'usage_avg_upgrade', 'traffic_total_existing', 'traffic_total_upgrade'] as $column) {
                $t->decimal($column, 20, 6)->nullable()->change();
            }
        });
        Schema::table('invoice_traffic_usages', function (Blueprint $t) {
            $t->decimal('inbound_kbps', 20, 6)->nullable();
            $t->decimal('outbound_kbps', 20, 6)->nullable();
            $t->date('coverage_start')->nullable();
            $t->date('coverage_end')->nullable();
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->json('source')->nullable();
        });
        Schema::table('import_batches', function (Blueprint $t) {
            $t->string('file_path')->nullable();
            $t->string('file_hash', 64)->nullable();
            $t->date('usage_start')->nullable();
            $t->date('usage_end')->nullable();
            $t->unsignedInteger('input_version')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->unique(['invoice_period_id', 'import_type', 'file_hash']);
        });
        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $t->string('sheet');
            $t->unsignedInteger('row_number');
            $t->string('kind');
            $t->string('sid')->nullable();
            $t->json('original');
            $t->json('normalized');
            $t->json('errors')->nullable();
            $t->string('status')->default('pending');
            $t->text('resolution_reason')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamps();
        });
        Schema::create('service_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_period_id')->constrained()->cascadeOnDelete();
            $t->string('sid');
            $t->string('event_type');
            $t->date('effective_date');
            $t->json('attributes');
            $t->json('source')->nullable();
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->timestamps();
            $t->index(['invoice_period_id', 'sid']);
        });
        Schema::create('outage_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_period_id')->constrained()->cascadeOnDelete();
            $t->string('sid');
            $t->string('kind')->default('interval');
            $t->dateTime('opened_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->unsignedBigInteger('duration_seconds')->nullable();
            $t->string('review_status')->default('pending');
            $t->text('reason')->nullable();
            $t->string('ticket')->nullable();
            $t->json('source')->nullable();
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamps();
            $t->index(['invoice_period_id', 'sid']);
        });
        Schema::create('invoice_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_period_id')->constrained()->cascadeOnDelete();
            $t->string('sid');
            $t->string('kind');
            $t->decimal('amount', 20, 2);
            $t->text('reason');
            $t->text('reference');
            $t->json('attributes')->nullable();
            $t->json('source')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->timestamps();
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('snapshot_id')->nullable()->change();
            $t->foreignId('invoice_period_id')->nullable()->unique()->constrained();
        });
        Schema::create('invoice_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_period_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('input_version');
            $t->json('snapshot');
            $t->string('snapshot_hash', 64);
            $t->string('status')->default('approved');
            $t->text('reason')->nullable();
            $t->text('generation_error')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at');
            $t->timestamp('generation_started_at')->nullable();
            $t->timestamps();
            $t->unique(['invoice_id', 'version']);
            $t->unique(['invoice_period_id', 'input_version']);
        });
        Schema::table('invoice_outputs', function (Blueprint $t) {
            $t->foreignId('invoice_revision_id')->nullable()->constrained()->restrictOnDelete();
            $t->unique(['invoice_revision_id', 'output_type']);
        });
        Schema::create('billing_audit_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('invoice_period_id')->nullable();
            $t->string('action');
            $t->json('details');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });
        foreach (DB::table('invoice_periods')->get() as $period) {
            $invoiceDate = Carbon::create($period->year, $period->month, 1)->startOfDay();
            $usage = $invoiceDate->copy()->subMonthNoOverflow();
            DB::table('invoice_periods')->where('id', $period->id)->update([
                'billing_profile_id' => $profile,
                'invoice_metadata' => json_encode(['invoice_number' => '4807060000210-'.$invoiceDate->format('Ym'), 'issue_date' => $invoiceDate->toDateString(), 'due_date' => $invoiceDate->copy()->day(20)->toDateString()]),
            ]);
            if (! $period->is_locked && $period->status !== 'finalized') {
                DB::table('invoice_periods')->where('id', $period->id)->update([
                    'start_date' => $usage->toDateString(), 'end_date' => $usage->copy()->endOfMonth()->toDateString(),
                    'billing_days' => $usage->daysInMonth,
                ]);
                foreach (DB::table('invoice_lines')->where('invoice_period_id', $period->id)->get() as $line) {
                    DB::table('invoice_lines')->where('id', $line->id)->update([
                        'legacy_inputs' => json_encode($line), 'sla_actual' => null,
                        'effective_date' => null, 'sla_target' => '0.99',
                    ]);
                }
            }
        }
        DB::table('type_orders')->updateOrInsert(['type_order_code' => 'multiple_changes'], ['type_order_name' => 'MULTIPLE', 'description' => 'Beberapa perubahan dalam periode', 'sort_order' => 7, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['ssid_list' => 'Data Periode', 'type_order' => 'Rekonsiliasi & Perhitungan', 'preview' => 'Review & Generate Invoice'] as $code => $name) {
            DB::table('invoice_steps')->where('step_code', $code)->update(['step_name' => $name]);
        }
    }

    public function down(): void
    {
        // Removing immutable invoice records requires an explicit archival operation.
        throw new LogicException('Billing migration is forward-only. Restore the database backup to roll back.');
    }
};
