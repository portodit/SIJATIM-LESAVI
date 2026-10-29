<?php

namespace Tests\Feature;

use App\Jobs\GenerateInvoiceDocuments;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\InvoiceRevision;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\QueueWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finalizing an invoice must produce its files with only the web server running.
 */
class QueueWorkerLauncherTest extends TestCase
{
    use RefreshDatabase;

    /** A launcher that records launches instead of starting real processes. */
    private function fakeLauncher(): QueueWorker
    {
        $fake = new class extends QueueWorker
        {
            public int $launches = 0;

            public function launch(): void
            {
                $this->launches++;
            }
        };
        $this->app->instance(QueueWorker::class, $fake);
        config(['billing.auto_worker' => true]);
        Cache::forget('billing.queue-worker.launched');

        return $fake;
    }

    private function queueJob(int $reservedAt = 0): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservedAt ?: null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
    }

    public function test_launches_only_when_enabled_a_job_is_waiting_and_not_twice_in_a_row(): void
    {
        $launcher = $this->fakeLauncher();
        $this->assertFalse($launcher->ensureRunning(), 'Nothing is waiting.');

        $this->queueJob();
        config(['billing.auto_worker' => false]);
        $this->assertFalse($launcher->ensureRunning(), 'Disabled by BILLING_AUTO_WORKER=false.');

        config(['billing.auto_worker' => true]);
        $this->assertTrue($launcher->ensureRunning());
        $this->assertFalse($launcher->ensureRunning(), 'A second launch within the interval is skipped.');
        $this->assertSame(1, $launcher->launches);

        Cache::forget('billing.queue-worker.launched');
        DB::table('jobs')->delete();
        $this->queueJob(now()->getTimestamp());
        $this->assertFalse($launcher->ensureRunning(), 'A job already claimed by a running worker needs no new worker.');

        DB::table('jobs')->delete();
        $this->queueJob(now()->subHour()->getTimestamp());
        $this->assertTrue($launcher->ensureRunning(), 'A stale claim from a dead worker is picked up again.');
    }

    public function test_a_failing_launch_never_breaks_the_request(): void
    {
        $this->queueJob();
        config(['billing.auto_worker' => true]);
        Cache::forget('billing.queue-worker.launched');
        $broken = new class extends QueueWorker
        {
            public function launch(): void
            {
                throw new \RuntimeException('php tidak ditemukan');
            }
        };
        $this->assertFalse($broken->ensureRunning());
    }

    public function test_the_job_raises_a_low_memory_limit_and_never_lowers_a_high_one(): void
    {
        $original = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '256M');
            GenerateInvoiceDocuments::ensureMemory();
            $this->assertSame('1024M', ini_get('memory_limit'));

            ini_set('memory_limit', '2G');
            GenerateInvoiceDocuments::ensureMemory();
            $this->assertSame('2G', ini_get('memory_limit'));

            ini_set('memory_limit', '-1');
            GenerateInvoiceDocuments::ensureMemory();
            $this->assertSame('-1', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    public function test_finalizing_and_opening_the_publish_page_start_a_worker_for_waiting_files(): void
    {
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'T', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $master = BankJatimLink::create(['ssid' => '0012-ABC', 'office_type_id' => $office->id, 'sequence_in_office_type' => 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Test', 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
        $period = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => auth()->id()]);
        InvoiceLine::create(['invoice_period_id' => $period->id, 'bank_jatim_link_id' => $master->id, 'line_number' => 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address]);
        PriceMaster::create(['location_category_id' => $category->id, 'service_id' => $service->id, 'bandwidth_option_id' => $bw->id, 'monthly_price' => '2319819', 'installation_price' => '1981982', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true]);
        $this->post(route('billing.downtime', $period), ['sid' => $master->ssid, 'downtime_seconds' => 0])->assertRedirect();
        app(InvoiceCalculator::class)->calculate($period->fresh());

        $launcher = $this->fakeLauncher();
        $this->post(route('billing.finalize', $period), ['input_version' => $period->fresh()->input_version])->assertRedirect(route('invoice-periods.step3', $period));
        $revision = InvoiceRevision::sole();
        $this->assertSame('approved', $revision->status);
        $this->assertSame(1, DB::table('jobs')->count(), 'The document job waits in the database queue.');
        $this->assertSame(1, $launcher->launches, 'Finalizing starts a worker.');

        // The worker never ran (or died): opening the page, or its 5-second status poll, wakes one again.
        Cache::forget('billing.queue-worker.launched');
        $this->get(route('invoice-periods.step3', $period))->assertOk();
        $this->assertSame(2, $launcher->launches);
        Cache::forget('billing.queue-worker.launched');
        $this->getJson(route('billing.readiness', $period))->assertOk();
        $this->assertSame(3, $launcher->launches);

        // Once the files are ready, nothing is launched any more.
        DB::table('jobs')->delete();
        $revision->update(['status' => 'ready']);
        Cache::forget('billing.queue-worker.launched');
        $this->get(route('invoice-periods.step3', $period))->assertOk();
        $this->assertSame(3, $launcher->launches);
    }
}
