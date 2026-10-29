<?php

namespace Tests\Feature;

use App\Models\BankJatimLink;
use Database\Seeders\BandwidthOptionsSeeder;
use Database\Seeders\BankJatimLinksSeeder;
use Database\Seeders\InvoiceStepsSeeder;
use Database\Seeders\LocationCategoriesSeeder;
use Database\Seeders\OfficeTypesSeeder;
use Database\Seeders\PriceMastersSeeder;
use Database\Seeders\ServicesSeeder;
use Database\Seeders\TypeOrdersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A fresh `migrate --seed` must end up with the master data the app looks up by code
 * (for example the type order 'existing'), even though migrations already insert some
 * rows into type_orders and invoice_steps. The master seeders are called directly so
 * this test does not depend on the committed data package in database/seed-data.
 */
class FreshSeedTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_SEEDERS = [
        OfficeTypesSeeder::class,
        LocationCategoriesSeeder::class,
        ServicesSeeder::class,
        TypeOrdersSeeder::class,
        BandwidthOptionsSeeder::class,
        PriceMastersSeeder::class,
        InvoiceStepsSeeder::class,
    ];

    private const TYPE_ORDER_CODES = ['existing', 'upgrade', 'pasang_baru', 'relokasi', 'downgrade', 'pemutusan', 'multiple_changes'];

    private const STEP_CODES = ['overview', 'ssid_list', 'type_order', 'preview'];

    public function test_fresh_install_has_the_type_orders_and_invoice_steps_the_app_needs(): void
    {
        $this->seed(self::MASTER_SEEDERS);

        $typeOrders = DB::table('type_orders')->pluck('type_order_code')->all();
        $this->assertContains('existing', $typeOrders);
        $this->assertContains('multiple_changes', $typeOrders);
        $this->assertEqualsCanonicalizing(self::TYPE_ORDER_CODES, $typeOrders);

        $steps = DB::table('invoice_steps')->pluck('step_code')->all();
        $this->assertContains('preview', $steps);
        $this->assertEqualsCanonicalizing(self::STEP_CODES, $steps);
    }

    public function test_seeders_fill_empty_tables_too(): void
    {
        DB::table('type_orders')->delete();
        DB::table('invoice_steps')->delete();

        $this->seed([TypeOrdersSeeder::class, InvoiceStepsSeeder::class]);

        $this->assertEqualsCanonicalizing(
            array_diff(self::TYPE_ORDER_CODES, ['multiple_changes']),
            DB::table('type_orders')->pluck('type_order_code')->all()
        );
        $this->assertEqualsCanonicalizing(self::STEP_CODES, DB::table('invoice_steps')->pluck('step_code')->all());
    }

    public function test_running_the_seeders_twice_does_not_duplicate_rows(): void
    {
        $this->seed([TypeOrdersSeeder::class, InvoiceStepsSeeder::class]);
        $typeOrders = DB::table('type_orders')->count();
        $steps = DB::table('invoice_steps')->count();

        $this->seed([TypeOrdersSeeder::class, InvoiceStepsSeeder::class]);

        $this->assertSame($typeOrders, DB::table('type_orders')->count());
        $this->assertSame($steps, DB::table('invoice_steps')->count());
        $this->assertSame(1, DB::table('type_orders')->where('type_order_code', 'existing')->count());
        $this->assertSame(1, DB::table('invoice_steps')->where('step_code', 'preview')->count());
    }

    public function test_step_names_set_by_the_migrations_are_not_overwritten(): void
    {
        $before = DB::table('invoice_steps')->where('step_code', 'ssid_list')->value('step_name');

        $this->seed(InvoiceStepsSeeder::class);

        $this->assertSame($before, DB::table('invoice_steps')->where('step_code', 'ssid_list')->value('step_name'));
        $this->assertSame('Data Periode', $before);
    }

    public function test_links_seeder_skips_quietly_when_the_csv_is_not_available(): void
    {
        $this->withLinksCsv('/this/path/does/not/exist.csv', function () {
            $this->artisan('db:seed', ['--class' => BankJatimLinksSeeder::class, '--force' => true])
                ->expectsOutputToContain('SIJATIM_LINKS_CSV')
                ->assertExitCode(0);
        });

        $this->assertSame(0, BankJatimLink::count());
    }

    /**
     * Point SIJATIM_LINKS_CSV at $path for the duration of $callback, then restore it.
     */
    private function withLinksCsv(string $path, callable $callback): void
    {
        $key = 'SIJATIM_LINKS_CSV';
        $previous = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

        $_ENV[$key] = $_SERVER[$key] = $path;
        putenv("{$key}={$path}");

        try {
            $callback();
        } finally {
            foreach (['_ENV' => $previous[0], '_SERVER' => $previous[1]] as $bag => $value) {
                if ($value === null) {
                    unset($GLOBALS[$bag][$key]);
                } else {
                    $GLOBALS[$bag][$key] = $value;
                }
            }
            putenv($previous[2] === false ? $key : "{$key}={$previous[2]}");
        }
    }
}
