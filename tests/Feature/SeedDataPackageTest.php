<?php

namespace Tests\Feature;

use App\Services\SeedDataPackage;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InvoiceProgressSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The "data package" (database/seed-data): export the working database + generated files, import them elsewhere.
 * Every test uses its own temp folder and never touches the real database/seed-data.
 */
class SeedDataPackageTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-01 08:00:00';

    private string $dir;

    /** @var array<string, string> disk path => content of the files created by the fixture */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = storage_path('framework/testing/seed-data-'.uniqid());
        SeedDataPackage::useDirectory($this->dir);
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        SeedDataPackage::useDirectory(null);
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------- round trip

    public function test_round_trip_restores_identical_rows_ids_and_files(): void
    {
        $before = $this->snapshot();
        $summary = (new SeedDataPackage)->export($this->dir);

        $this->assertSame(count($before), $summary['tables']);
        $this->assertSame(array_sum(array_map('count', $before)), $summary['rows']);
        $this->assertSame(3, $summary['files']);

        $this->wipeDatabase();
        Storage::disk('local')->deleteDirectory('billing');
        $this->assertSame(0, DB::table('invoice_periods')->count());

        $result = (new SeedDataPackage)->import($this->dir);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($summary['rows'], $result['rows']);
        $this->assertSame(2, $result['tables']['invoice_lines']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([['period_code' => 'JAN26', 'status' => 'validation', 'lines' => 2, 'grand_total' => '1110000.00']], $result['periods']);

        $disk = Storage::disk('local');
        $this->assertSame(3, $result['files']);
        foreach (['billing/invoices/JAN26/invoice.xlsx', 'billing/invoices/JAN26/invoice.pdf', 'billing/sources/JAN26/source.xlsx'] as $path) {
            $this->assertTrue($disk->exists($path), $path);
            $this->assertSame($this->files[$path], $disk->get($path), $path);
        }
        $this->assertFalse($disk->exists('billing/previews/JAN26/preview.png'), 'previews are dev-only and never packaged');
    }

    public function test_import_replaces_existing_rows_in_a_populated_database(): void
    {
        $before = $this->snapshot();
        (new SeedDataPackage)->export($this->dir);

        // Drift away from the package: edit, add children (restrict / cascade foreign keys) and a row that is not in the package.
        $period = DB::table('invoice_periods')->value('id');
        DB::table('invoice_periods')->update(['notes' => 'diubah setelah ekspor']);
        DB::table('invoice_lines')->insert(['invoice_period_id' => $period, 'line_number' => 3, 'sla_target' => '0.99']);
        DB::table('invoice_outputs')->insert(['invoice_period_id' => $period, 'output_type' => 'pdf', 'file_name' => 'x.pdf', 'file_path' => 'billing/invoices/JAN26/x.pdf', 'invoice_revision_id' => DB::table('invoice_revisions')->value('id')]);
        $this->assertNotSame($before, $this->snapshot());

        (new SeedDataPackage)->import($this->dir);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_large_tables_are_inserted_in_chunks(): void
    {
        $rows = [];
        for ($i = 1; $i <= 450; $i++) {
            $rows[] = ['location_category_code' => 'BULK'.$i, 'location_category_name' => 'Massal '.$i, 'sort_order' => $i, 'created_at' => self::NOW, 'updated_at' => self::NOW];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('location_categories')->insert($chunk);
        }
        $before = $this->snapshot();
        (new SeedDataPackage)->export($this->dir);

        $this->wipeDatabase();
        $result = (new SeedDataPackage)->import($this->dir);

        $this->assertSame(451, $result['tables']['location_categories']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_numbers_keep_their_exact_value(): void
    {
        // 15 significant digits: would be rounded if the value travelled through PHP's 14-digit float-to-string cast.
        $this->assertEquals(123456789.123456, DB::table('invoice_lines')->where('line_number', 1)->value('usage_avg_existing'));
        (new SeedDataPackage)->export($this->dir);
        $this->wipeDatabase();
        (new SeedDataPackage)->import($this->dir);

        $this->assertSame(123456789.123456, DB::table('invoice_lines')->where('line_number', 1)->value('usage_avg_existing'));
    }

    // ---------------------------------------------------------------------------------------- package contents

    public function test_manifest_describes_tables_files_and_migrations_and_skips_previews(): void
    {
        (new SeedDataPackage)->export($this->dir);

        $this->assertTrue(SeedDataPackage::exists($this->dir));
        $manifest = json_decode(file_get_contents($this->dir.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['format', 'exported_at', 'app', 'migrations', 'tables', 'files'], array_keys($manifest));
        $this->assertSame(1, $manifest['format']);
        $sorted = $manifest['migrations'];
        sort($sorted);
        $this->assertSame($sorted, $manifest['migrations']);
        $this->assertContains('2026_10_09_000001_add_automated_billing', $manifest['migrations']);

        $this->assertSame(array_keys($manifest['tables']), SeedDataPackage::packagedTables());
        foreach (SeedDataPackage::EXCLUDED_TABLES as $excluded) {
            $this->assertArrayNotHasKey($excluded, $manifest['tables']);
        }
        $this->assertSame(2, $manifest['tables']['invoice_lines']['rows']);
        foreach ($manifest['tables'] as $table => $info) {
            $this->assertSame(hash_file('sha256', $this->dir."/tables/{$table}.json.gz"), $info['sha256']);
        }

        $this->assertSame(['billing/invoices/JAN26/invoice.pdf', 'billing/invoices/JAN26/invoice.xlsx', 'billing/sources/JAN26/source.xlsx'], array_keys($manifest['files']));
        $this->assertSame(strlen($this->files['billing/sources/JAN26/source.xlsx']), $manifest['files']['billing/sources/JAN26/source.xlsx']['size']);
        $this->assertSame(hash('sha256', $this->files['billing/sources/JAN26/source.xlsx']), $manifest['files']['billing/sources/JAN26/source.xlsx']['sha256']);
        $this->assertFileDoesNotExist($this->dir.'/files/billing/previews/JAN26/preview.png');
    }

    public function test_export_replaces_a_previous_package(): void
    {
        File::ensureDirectoryExists($this->dir.'/tables');
        File::ensureDirectoryExists($this->dir.'/files/billing/invoices');
        file_put_contents($this->dir.'/tables/removed_table.json.gz', 'old');
        file_put_contents($this->dir.'/files/billing/invoices/removed.xlsx', 'old');

        (new SeedDataPackage)->export($this->dir);

        $this->assertFileDoesNotExist($this->dir.'/tables/removed_table.json.gz');
        $this->assertFileDoesNotExist($this->dir.'/files/billing/invoices/removed.xlsx');
        $this->assertFileExists($this->dir.'/files/billing/invoices/JAN26/invoice.xlsx');
    }

    public function test_users_are_exported_without_secrets_and_imported_with_the_default_password(): void
    {
        $this->assertSame('tokenrahasia', DB::table('users')->value('remember_token'));
        (new SeedDataPackage)->export($this->dir);

        $raw = gzdecode(file_get_contents($this->dir.'/tables/users.json.gz'));
        $users = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $users);
        $this->assertSame(['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'], array_keys($users[0]));
        $this->assertSame('test@example.com', $users[0]['email']);
        foreach (['password', 'remember', 'two_factor', 'secret', 'tokenrahasia'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }

        $this->wipeDatabase();
        (new SeedDataPackage)->import($this->dir);

        $user = DB::table('users')->first();
        $this->assertSame('Paket User', $user->name);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNull($user->remember_token);
        $this->assertNull($user->two_factor_secret);
    }

    // ---------------------------------------------------------------------------------------- refusing bad packages

    public function test_tampered_table_file_is_rejected_before_anything_changes(): void
    {
        (new SeedDataPackage)->export($this->dir);
        file_put_contents($this->dir.'/tables/users.json.gz', 'x', FILE_APPEND);
        DB::table('invoice_periods')->update(['notes' => 'jangan hilang']);

        try {
            (new SeedDataPackage)->import($this->dir);
            $this->fail('A tampered table file must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('users.json.gz', $e->getMessage());
        }
        $this->assertSame('jangan hilang', DB::table('invoice_periods')->value('notes'));
    }

    public function test_tampered_or_missing_packaged_file_is_rejected(): void
    {
        (new SeedDataPackage)->export($this->dir);
        file_put_contents($this->dir.'/files/billing/sources/JAN26/source.xlsx', 'diganti');
        try {
            (new SeedDataPackage)->import($this->dir);
            $this->fail('A tampered file must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('billing/sources/JAN26/source.xlsx', $e->getMessage());
        }

        unlink($this->dir.'/files/billing/sources/JAN26/source.xlsx');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('billing/sources/JAN26/source.xlsx');
        (new SeedDataPackage)->import($this->dir);
    }

    public function test_manifest_cannot_point_outside_the_packaged_folders(): void
    {
        (new SeedDataPackage)->export($this->dir);
        $this->editManifest(function (array $manifest) {
            $manifest['files']['billing/invoices/../../../.env'] = ['size' => 1, 'sha256' => str_repeat('0', 64)];

            return $manifest;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Path berkas tidak diizinkan');
        (new SeedDataPackage)->import($this->dir);
    }

    public function test_package_made_with_a_newer_schema_asks_for_migrate(): void
    {
        (new SeedDataPackage)->export($this->dir);
        $this->editManifest(function (array $manifest) {
            $manifest['migrations'][] = '2099_01_01_000000_future_migration';

            return $manifest;
        });
        DB::table('invoice_periods')->update(['notes' => 'jangan hilang']);

        try {
            (new SeedDataPackage)->import($this->dir);
            $this->fail('A package from a newer schema must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('php artisan migrate', $e->getMessage());
            $this->assertStringContainsString('2099_01_01_000000_future_migration', $e->getMessage());
        }
        $this->assertSame('jangan hilang', DB::table('invoice_periods')->value('notes'));
    }

    public function test_foreign_key_violations_roll_the_whole_import_back(): void
    {
        $before = $this->snapshot();
        (new SeedDataPackage)->export($this->dir);
        $this->rewriteTable('invoice_lines', function (array $rows) {
            $rows[0]['invoice_period_id'] = 987654;

            return $rows;
        });

        try {
            (new SeedDataPackage)->import($this->dir);
            $this->fail('A dangling foreign key must abort the import.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('foreign key', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unknown_tables_and_columns_are_skipped_with_warnings(): void
    {
        (new SeedDataPackage)->export($this->dir);
        $this->rewriteTable('office_types', function (array $rows) {
            $rows[0]['legacy_column'] = 'lama';

            return $rows;
        });
        $this->addTableFile('ghost_table', [['id' => 1]]);

        $this->wipeDatabase();
        $result = (new SeedDataPackage)->import($this->dir);

        $this->assertArrayNotHasKey('ghost_table', $result['tables']);
        $this->assertSame(1, $result['tables']['office_types']);
        $warnings = implode("\n", $result['warnings']);
        $this->assertStringContainsString('ghost_table', $warnings);
        $this->assertStringContainsString('legacy_column', $warnings);
    }

    // ---------------------------------------------------------------------------------------- seeders and command

    public function test_invoice_progress_seeder_does_not_touch_a_database_that_already_has_periods(): void
    {
        (new SeedDataPackage)->export($this->dir);
        DB::table('invoice_periods')->update(['notes' => 'pekerjaan lokal']);
        $before = $this->snapshot();

        Artisan::call('db:seed', ['--class' => InvoiceProgressSeeder::class, '--no-interaction' => true]);

        $this->assertStringContainsString('migrate:fresh --seed', Artisan::output());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('pekerjaan lokal', DB::table('invoice_periods')->value('notes'));
    }

    public function test_invoice_progress_seeder_loads_the_package_into_an_empty_database(): void
    {
        $before = $this->snapshot();
        (new SeedDataPackage)->export($this->dir);
        $this->wipeDatabase();
        Storage::disk('local')->deleteDirectory('billing');

        Artisan::call('db:seed', ['--class' => InvoiceProgressSeeder::class, '--no-interaction' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('JAN26', $output);
        $this->assertStringContainsString('1.110.000,00', $output);
        $this->assertStringContainsString('Login: test@example.com / password', $output);
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue(Storage::disk('local')->exists('billing/invoices/JAN26/invoice.xlsx'));
    }

    public function test_database_seeder_uses_the_package_when_the_manifest_exists(): void
    {
        $emptyDir = $this->dir.'-kosong';
        $this->assertFalse(SeedDataPackage::exists($emptyDir));
        $this->assertFalse(SeedDataPackage::exists(), 'no manifest in the configured folder yet');

        (new SeedDataPackage)->export($this->dir);
        $this->assertTrue(SeedDataPackage::exists());
        $before = $this->snapshot();
        $this->wipeDatabase();

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--no-interaction' => true]);

        // Only the package ran: the generic master seeders would have added far more than the one fixture row per master.
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('Paket User', DB::table('users')->where('email', 'test@example.com')->value('name'));
        $this->assertSame(1, DB::table('office_types')->count());
    }

    public function test_export_command_writes_the_package(): void
    {
        $this->artisan('sijatim:export-seed-data', ['--path' => $this->dir])
            ->expectsOutputToContain('Tabel:')
            ->expectsOutputToContain('bukan folder bawaan')
            ->assertSuccessful();

        $this->assertTrue(SeedDataPackage::exists($this->dir));
        $this->assertFileExists($this->dir.'/tables/invoice_periods.json.gz');
    }

    // ---------------------------------------------------------------------------------------- helpers

    private function seedFixture(): void
    {
        $now = self::NOW;
        $user = DB::table('users')->insertGetId([
            'name' => 'Paket User', 'email' => 'test@example.com', 'email_verified_at' => $now, 'password' => Hash::make('rahasia'),
            'remember_token' => 'tokenrahasia', 'two_factor_secret' => 'secret', 'two_factor_recovery_codes' => '["kode"]',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $office = DB::table('office_types')->insertGetId(['office_type_code' => 'KP', 'office_type_name' => 'Kantor Pusat', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $category = DB::table('location_categories')->insertGetId(['location_category_code' => 'KK', 'location_category_name' => 'Kantor Kas', 'created_at' => $now, 'updated_at' => $now]);
        $service = DB::table('services')->insertGetId(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('type_orders')->insert(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $bandwidth = DB::table('bandwidth_options')->insertGetId(['bandwidth_code' => '10', 'bandwidth_name' => '10 Mbps', 'value_kbps' => 10240, 'created_at' => $now, 'updated_at' => $now]);
        $link = DB::table('bank_jatim_links')->insertGetId([
            'ssid' => '4700020-1001', 'office_type_id' => $office, 'sequence_in_office_type' => 1, 'location_category_id' => $category, 'service_id' => $service,
            'current_bandwidth_option_id' => $bandwidth, 'address' => 'Jl. Pahlawan / Ünïcode ✓ "kutip" \\ garis', 'service_start_date' => '2020-01-01',
            'link_status' => 'active', 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $calculation = json_encode([
            'schema_version' => 1,
            'totals' => ['subtotal' => '1000000.00', 'tax' => '110000.00', 'grand_total' => '1110000.00'],
            'lines' => [['sid' => '4700020-1001', 'address' => 'Jl. Pahlawan / Ünïcode ✓', 'net' => '1000000.00']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $period = DB::table('invoice_periods')->insertGetId([
            'period_code' => 'JAN26', 'month' => 1, 'year' => 2026, 'start_date' => '2025-12-01', 'end_date' => '2025-12-31', 'billing_days' => 30,
            'status' => 'validation', 'created_by' => $user, 'billing_profile_id' => DB::table('billing_profiles')->value('id'),
            'calculation' => $calculation, 'input_version' => 1, 'calculated_input_version' => 1, 'calculated_at' => $now,
            'invoice_metadata' => '{"invoice_number":"4807060000210-202601"}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('invoice_lines')->insert(['invoice_period_id' => $period, 'bank_jatim_link_id' => $link, 'line_number' => 1, 'existing_bandwidth_option_id' => $bandwidth, 'address_snapshot' => 'Jl. Pahlawan',
            'usage_avg_existing' => '123456789.123456', 'price_total' => '1110000.00', 'sla_target' => '0.99', 'service_snapshot' => '{"sid":"4700020-1001"}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('invoice_lines')->insert(['invoice_period_id' => $period, 'bank_jatim_link_id' => null, 'line_number' => 2, 'ssid_temp' => 'SID-BARU', 'is_new_ssid' => 1, 'sla_target' => '0.99', 'created_at' => $now, 'updated_at' => $now]);
        $invoice = DB::table('invoices')->insertGetId([
            'invoice_number' => '4807060000210-202601', 'total_amount' => '1110000.00', 'total_locations' => 2, 'invoice_period_id' => $period,
            'generated_by' => $user, 'generated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $revision = DB::table('invoice_revisions')->insertGetId([
            'invoice_id' => $invoice, 'invoice_period_id' => $period, 'version' => 1, 'input_version' => 1, 'snapshot' => $calculation,
            'snapshot_hash' => hash('sha256', $calculation), 'status' => 'approved', 'approved_by' => $user, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->putFile('billing/invoices/JAN26/invoice.xlsx', "PK\x03\x04\r\n\x00\xff\xfe\r\nbiner\n");
        $this->putFile('billing/invoices/JAN26/invoice.pdf', "%PDF-1.4\r\n\x00\x01\x02\r\n%%EOF");
        $this->putFile('billing/sources/JAN26/source.xlsx', "PK\x03\x04sumber\r\n\x00");
        $this->putFile('billing/previews/JAN26/preview.png', "\x89PNG\r\n\x1a\n");
        DB::table('invoice_outputs')->insert([
            'invoice_period_id' => $period, 'output_type' => 'excel', 'file_name' => 'invoice.xlsx', 'file_path' => 'billing/invoices/JAN26/invoice.xlsx',
            'file_hash' => hash('sha256', $this->files['billing/invoices/JAN26/invoice.xlsx']), 'file_size' => strlen($this->files['billing/invoices/JAN26/invoice.xlsx']),
            'status' => 'generated', 'version' => 1, 'generated_by' => $user, 'invoice_revision_id' => $revision, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $batch = DB::table('import_batches')->insertGetId([
            'invoice_period_id' => $period, 'import_type' => 'history', 'file_name' => 'source.xlsx', 'original_filename' => 'Sumber Januari.xlsx',
            'file_path' => 'billing/sources/JAN26/source.xlsx', 'file_hash' => hash('sha256', $this->files['billing/sources/JAN26/source.xlsx']),
            'status' => 'committed', 'uploaded_by' => $user, 'committed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('import_rows')->insert([
            'import_batch_id' => $batch, 'sheet' => 'Sheet1', 'row_number' => 5, 'kind' => 'service', 'sid' => '4700020-1001',
            'original' => '{"A":"4700020-1001"}', 'normalized' => '{"sid":"4700020-1001"}', 'status' => 'valid', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function putFile(string $path, string $content): void
    {
        Storage::disk('local')->put($path, $content);
        $this->files[$path] = $content;
    }

    /** Every packaged table's rows (users reduced to the exported columns), sorted so row order does not matter. */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (SeedDataPackage::packagedTables() as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            if ($table === 'users') {
                $rows = array_map(fn (array $row) => array_intersect_key($row, array_flip(['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'])), $rows);
            }
            usort($rows, fn (array $a, array $b) => strcmp(json_encode($a), json_encode($b)));
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    /** Empties every packaged table (children first for the RESTRICT keys; the rest is covered by deferred checks). */
    private function wipeDatabase(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        foreach (['invoice_outputs', 'invoice_revisions'] as $table) {
            DB::table($table)->delete();
        }
        foreach (SeedDataPackage::packagedTables() as $table) {
            DB::table($table)->delete();
        }
    }

    private function editManifest(callable $edit): void
    {
        $file = $this->dir.'/manifest.json';
        $manifest = $edit(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
        file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function rewriteTable(string $table, callable $edit): void
    {
        $file = $this->dir."/tables/{$table}.json.gz";
        $this->addTableFile($table, $edit(json_decode(gzdecode(file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR)));
    }

    /** Writes a table file and records it (rows + sha256) in the manifest. */
    private function addTableFile(string $table, array $rows): void
    {
        $gz = gzencode(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 9);
        file_put_contents($this->dir."/tables/{$table}.json.gz", $gz);
        $this->editManifest(function (array $manifest) use ($table, $rows, $gz) {
            $manifest['tables'][$table] = ['rows' => count($rows), 'sha256' => hash('sha256', $gz)];

            return $manifest;
        });
    }
}
