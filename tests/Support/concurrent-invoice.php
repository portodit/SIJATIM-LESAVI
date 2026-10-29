<?php

use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceFinalizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Runs only against a temporary database supplied by ConcurrentBillingTest.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script,$operation,$database] = $argv;
if (! str_contains(str_replace('\\', '/', $database), '/storage/framework/testing/')) {
    throw new RuntimeException('Test database path required.');
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'database.connections.sqlite.busy_timeout' => 10000, 'queue.default' => 'database', 'hashing.bcrypt.rounds' => 4]);
DB::purge('sqlite');
if ($operation === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    $user = User::factory()->create();
    auth()->setUser($user);
    $office = OfficeType::create(['office_type_code' => 'TEST', 'office_type_name' => 'Concurrency fixture', 'sort_order' => 1]);
    $category = LocationCategory::create(['location_category_code' => 'TEST', 'location_category_name' => 'TEST']);
    $service = Service::create(['service_code' => 'VPN', 'service_name' => 'VPN']);
    $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
    $master = BankJatimLink::create(['ssid' => '001-CONCURRENT', 'office_type_id' => $office->id, 'sequence_in_office_type' => 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Concurrency test only', 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
    $p = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => $user->id]);
    InvoiceLine::create(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $master->id, 'line_number' => 1, 'existing_bandwidth_option_id' => $bw->id, 'traffic_exception_reason' => 'Documented test exception']);
    InvoiceAdjustment::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'kind' => 'tariff_override', 'amount' => '2319819', 'reason' => 'Test tariff', 'reference' => 'Test fixture']);
    $p->update(['sla_coverage' => ['sids' => [$master->ssid]]]);
    (new InvoiceCalculator)->calculate($p);
    $p->refresh();
    echo json_encode(['period' => $p->id, 'version' => $p->input_version]);
} else {
    $user = User::firstOrFail();
    auth()->setUser($user);
    $p = InvoicePeriod::findOrFail((int) $argv[3]);
    $revision = (new InvoiceFinalizer)->finalize($p, (int) $argv[4], $user->id);
    echo json_encode(['revision' => $revision->id, 'invoice' => $revision->invoice_id]);
}
