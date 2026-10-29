<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\ImportBatch;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\OutageEvent;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\DowntimeConflicts;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\PeriodInventory;
use App\Services\Billing\SourceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Service-level behaviour of the "past-month Telkom workbook" import (kind = 'history'),
 * on the JAN26 invoice whose usage month is December 2025 (1-31 Dec, 30-day billing month).
 */
class HistoryImportTest extends TestCase
{
    use RefreshDatabase;

    /** Semantic field => column of the Telkom-style sheet built by telkomSheet(). */
    private const COLUMN = [
        'branch' => 'B', 'address' => 'C', 'category' => 'D', 'service' => 'E', 'type' => 'F', 'sid' => 'G', 'period' => 'H',
        'bw' => 'I', 'upgrade' => 'J', 'downgrade' => 'K', 'ip' => 'L', 'activation' => 'M', 'start' => 'N',
        'downtime' => 'S', 'price' => 'Y', 'psb_fee' => 'AC', 'relocation_fee' => 'AD',
    ];

    private InvoicePeriod $period;

    /** @var array<string, OfficeType> */
    private array $office = [];

    /** @var array<string, LocationCategory> */
    private array $category = [];

    /** @var array<string, Service> */
    private array $service = [];

    /** @var array<string, BandwidthOption> */
    private array $bw = [];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        foreach (['pusat' => 'KANTOR PUSAT', 'sby' => 'KANTOR CABANG SURABAYA', 'mlg' => 'KANTOR CABANG MALANG'] as $code => $name) {
            $this->office[$code] = OfficeType::create(['office_type_code' => $code, 'office_type_name' => $name, 'sort_order' => count($this->office) + 1]);
        }
        foreach (['kk' => 'KANTOR KAS', 'atm' => 'ATM'] as $code => $name) {
            $this->category[$code] = LocationCategory::create(['location_category_code' => $code, 'location_category_name' => $name]);
        }
        foreach (['vpn' => 'TELKOM VPN IP', 'ast' => 'TELKOM ASTINET'] as $code => $name) {
            $this->service[$code] = Service::create(['service_code' => $code, 'service_name' => $name]);
        }
        foreach ([10, 20, 50] as $mbps) {
            $this->bw[(string) $mbps] = BandwidthOption::create(['bandwidth_code' => (string) $mbps, 'bandwidth_name' => $mbps.' Mbps', 'value_kbps' => $mbps * 1024]);
        }
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        // General tariffs (all categories): 3.000.000 divides evenly by the 30-day billing month.
        $this->tariff('vpn', '10', '3000000');
        $this->tariff('ast', '20', '9000000');
        $this->period = InvoicePeriod::create(['month' => 1, 'year' => 2026, 'created_by' => auth()->id()]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- rule 1: master is never changed

    public function test_known_ssid_keeps_its_master_while_the_period_line_takes_the_workbook_values(): void
    {
        $master = $this->master('4700020-1001', [
            'office_type_id' => $this->office['pusat']->id, 'location_category_id' => $this->category['atm']->id, 'service_id' => $this->service['ast']->id,
            'current_bandwidth_option_id' => $this->bw['20']->id, 'address' => 'Jl. Master Lama', 'billing_start_date' => '2025-06-01',
        ]);
        $this->inPeriod($master);
        $before = $this->masterState($master);

        $this->import([$this->row('4700020-1001', [
            'branch' => 'KANTOR CABANG SURABAYA', 'category' => 'KANTOR KAS', 'service' => 'TELKOM VPN IP', 'bw' => '10 Mbps', 'address' => 'Jl. Workbook Baru',
        ])]);

        $after = $this->masterState($master);
        $this->assertSame($before, $after);
        $this->assertSame('Jl. Master Lama', $after['address']);
        $this->assertSame('2025-06-01', $after['billing_start_date']);
        $this->assertSame('active', $after['link_status']);
        $this->assertSame($this->office['pusat']->id, $after['office_type_id']);

        $line = $this->line('4700020-1001');
        $snapshot = $line->service_snapshot;
        $this->assertSame('KANTOR CABANG SURABAYA', $snapshot['branch']);
        $this->assertSame('Jl. Workbook Baru', $snapshot['address']);
        $this->assertSame('KANTOR KAS', $snapshot['category']);
        $this->assertSame('TELKOM VPN IP', $snapshot['service']);
        $this->assertSame($this->bw['10']->id, $snapshot['bandwidth_option_id']);
        $this->assertSame($this->category['kk']->id, $snapshot['location_category_id']);
        $this->assertSame($this->service['vpn']->id, $snapshot['service_id']);
        $this->assertNull($snapshot['billing_start_date'], 'the master billing dates do not leak into a past month');
        $this->assertNull($snapshot['billing_end_date']);
        $this->assertSame($this->bw['10']->id, $line->existing_bandwidth_option_id);
        $this->assertSame('Jl. Workbook Baru', $line->address_snapshot);
    }

    public function test_calculator_prices_the_line_with_the_workbook_category_service_and_bandwidth(): void
    {
        $master = $this->master('4700020-1002', ['location_category_id' => $this->category['atm']->id, 'service_id' => $this->service['ast']->id, 'current_bandwidth_option_id' => $this->bw['20']->id]);
        $this->inPeriod($master);
        // The category tariff beats the general one, so only the workbook's category can produce this price.
        $this->tariff('vpn', '10', '2400000', $this->category['kk']);

        $this->import([$this->row('4700020-1002', ['price' => 2400000])]);
        $result = app(InvoiceCalculator::class)->calculate($this->period->fresh());

        $line = $result['lines'][0];
        $this->assertSame('2400000.00', $line['gross']);
        $this->assertSame('KANTOR KAS', $line['category']);
        $this->assertSame('TELKOM VPN IP', $line['service']);
        $this->assertSame('10 Mbps', $line['bandwidth']);
        $this->assertSame([], $this->issueSids($result, ['tariff', 'tariff_discrepancy']));
    }

    // ---------------------------------------------------------------- rule 2: SSID new to the master

    public function test_ssid_missing_from_the_master_is_created_inactive_with_the_workbook_values(): void
    {
        $this->import([$this->row('4700020-2001', [
            'branch' => 'KANTOR CABANG MALANG', 'category' => 'ATM', 'service' => 'TELKOM ASTINET', 'bw' => '20 Mbps', 'address' => 'Jl. Baru 7', 'activation' => '2024-05-17',
        ])]);

        $link = BankJatimLink::where('ssid', '4700020-2001')->sole();
        $this->assertSame(BankJatimLink::STATUS_INACTIVE, $link->link_status);
        $this->assertSame($this->office['mlg']->id, $link->office_type_id);
        $this->assertSame($this->category['atm']->id, $link->location_category_id);
        $this->assertSame($this->service['ast']->id, $link->service_id);
        $this->assertSame($this->bw['20']->id, $link->current_bandwidth_option_id);
        $this->assertSame('Jl. Baru 7', $link->address);
        $this->assertSame('2024-05-17', $link->service_start_date->toDateString());
        $this->assertSame($link->id, $this->line('4700020-2001')->bank_jatim_link_id);
    }

    public function test_ssid_missing_from_the_master_needs_a_parseable_activation_date_and_an_address(): void
    {
        $batch = $this->stage([
            $this->row('4700020-3001', ['activation' => null]),
            $this->row('4700020-3002', ['activation' => 'bukan tanggal']),
            $this->row('4700020-3003', ['address' => null]),
            $this->row('4700020-3004'),
        ]);

        $this->assertStringContainsString('Tanggal aktivasi diperlukan', $this->rowErrors($batch, '4700020-3001'));
        $this->assertStringContainsString('Tanggal aktivasi:', $this->rowErrors($batch, '4700020-3002'));
        $this->assertStringContainsString('Alamat diperlukan', $this->rowErrors($batch, '4700020-3003'));
        $this->assertSame('', $this->rowErrors($batch, '4700020-3004'));
        $this->assertSame(['error', 'error', 'error', 'valid'], $batch->rows()->orderBy('row_number')->pluck('status')->all());
        $this->assertCommitFails($batch, 'Selesaikan semua kesalahan');
        $this->assertSame(0, BankJatimLink::count());
    }

    // ---------------------------------------------------------------- rule 3: sync with the period

    public function test_line_missing_from_the_file_is_removed_with_its_inputs_in_this_period_only(): void
    {
        $kept = $this->master('4700020-4001');
        $gone = $this->master('4700020-4002');
        $this->inPeriod($kept);
        $this->inPeriod($gone);
        $this->addInputs($this->period, '4700020-4002');
        $this->addInputs($this->period, '4700020-4001', 'kept');
        // The same SSID in the next invoice must survive untouched.
        $feb = InvoicePeriod::create(['month' => 2, 'year' => 2026, 'created_by' => auth()->id()]);
        PeriodInventory::add($feb, $gone);
        $this->addInputs($feb, '4700020-4002');

        $this->import([$this->row('4700020-4001')]);

        $this->assertSame(['4700020-4001'], $this->period->lines()->get()->map->billingSid()->all());
        $this->assertSame(0, ServiceEvent::where('invoice_period_id', $this->period->id)->where('sid', '4700020-4002')->count());
        $this->assertSame(0, OutageEvent::where('invoice_period_id', $this->period->id)->where('sid', '4700020-4002')->count());
        $this->assertSame(0, InvoiceAdjustment::where('invoice_period_id', $this->period->id)->where('sid', '4700020-4002')->count());
        $this->assertSame(1, InvoiceAdjustment::where('invoice_period_id', $this->period->id)->where('sid', '4700020-4001')->count(), 'inputs of SSIDs in the file stay');

        $this->assertSame(1, $feb->lines()->count());
        $this->assertSame(1, ServiceEvent::where('invoice_period_id', $feb->id)->where('sid', '4700020-4002')->count());
        $this->assertSame(1, OutageEvent::where('invoice_period_id', $feb->id)->where('sid', '4700020-4002')->count());
        $this->assertSame(1, InvoiceAdjustment::where('invoice_period_id', $feb->id)->where('sid', '4700020-4002')->count());
        $this->assertNotNull(BankJatimLink::find($gone->id), 'the master keeps the SSID');
    }

    public function test_lines_are_renumbered_in_the_file_row_order(): void
    {
        foreach (['R1', 'S1', 'S2', 'S3'] as $key) {
            $this->inPeriod($this->master('4700020-5'.$key));
        }

        // R1 is not in the file; N1 is new to the period (and to the master); the rest change order.
        $this->import([
            $this->row('4700020-5S3'), $this->row('4700020-5S1'), $this->row('4700020-5N1'), $this->row('4700020-5S2'),
        ]);

        $this->assertSame(['4700020-5S3=1', '4700020-5S1=2', '4700020-5N1=3', '4700020-5S2=4'], $this->lineOrder());
    }

    public function test_file_order_is_kept_across_branches_when_the_file_is_grouped_by_branch(): void
    {
        foreach (['M1', 'P1', 'M2', 'P2'] as $key) {
            $this->inPeriod($this->master('4700020-6'.$key));
        }
        $pusat = ['branch' => 'KANTOR PUSAT'];
        $malang = ['branch' => 'KANTOR CABANG MALANG'];

        $this->import([
            $this->row('4700020-6P2', $pusat), $this->row('4700020-6P1', $pusat), $this->row('4700020-6M2', $malang), $this->row('4700020-6M1', $malang),
        ]);

        $this->assertSame(['4700020-6P2=1', '4700020-6P1=2', '4700020-6M2=3', '4700020-6M1=4'], $this->lineOrder());
    }

    public function test_branch_grouping_wins_when_the_file_alternates_branches(): void
    {
        $pusat = ['branch' => 'KANTOR PUSAT'];
        $malang = ['branch' => 'KANTOR CABANG MALANG'];

        $this->import([$this->row('4700020-7A1', $pusat), $this->row('4700020-7B1', $malang), $this->row('4700020-7A2', $pusat)]);

        // Every invoice is grouped by jenis kantor in order of first appearance; file order holds within a group.
        $this->assertSame(['4700020-7A1=1', '4700020-7A2=2', '4700020-7B1=3'], $this->lineOrder());
    }

    public function test_a_corrected_workbook_replaces_the_earlier_workbooks_changes_and_downtime(): void
    {
        $first = $this->import([
            $this->row('4700020-9R1', ['type' => 'PASANG BARU', 'start' => '2025-12-05', 'downtime' => 600]),
            $this->row('4700020-9R2', ['downtime' => 100]),
        ]);
        $this->period->outages()->create(['sid' => '4700020-9R2', 'kind' => 'aggregate', 'duration_seconds' => 50, 'review_status' => 'eligible', 'reason' => 'manual']);
        $this->period->outages()->where('import_batch_id', $first->id)->where('sid', '4700020-9R2')->update(['review_status' => 'excluded']);

        $corrected = $this->stage([
            $this->row('4700020-9R1', ['type' => 'PASANG BARU', 'start' => '2025-12-08', 'downtime' => 300]),
            $this->row('4700020-9R2', ['downtime' => 200]),
        ]);

        $this->assertSame('valid', $corrected->rows()->where('sid', '4700020-9R1')->sole()->status, 'the earlier workbook\'s downtime is no conflict');
        $this->assertStringStartsWith('Bentrok:', $this->rowErrors($corrected, '4700020-9R2'), 'an operator value still needs a choice');
        $this->imports()->resolve($corrected->rows()->where('sid', '4700020-9R2')->sole(), [], true, 'pakai nilai manual');
        $this->imports()->commit($corrected->fresh());

        $events = ServiceEvent::where('sid', '4700020-9R1')->get();
        $this->assertCount(1, $events);
        $this->assertSame('2025-12-08', $events->sole()->effective_date->toDateString());
        $this->assertSame([300], DowntimeConflicts::active($this->period->fresh(), '4700020-9R1')->pluck('duration_seconds')->all());
        $this->assertSame([50], DowntimeConflicts::active($this->period->fresh(), '4700020-9R2')->pluck('duration_seconds')->all());
    }

    // ---------------------------------------------------------------- rule 4: type orders

    public function test_eksisting_row_creates_no_service_event(): void
    {
        $this->import([$this->row('4700020-8001')]);

        $this->assertSame(0, ServiceEvent::count());
        $this->assertNotNull($this->line('4700020-8001'));
    }

    public function test_garbage_start_date_on_an_eksisting_row_is_ignored(): void
    {
        $batch = $this->stage([$this->row('4700020-8002', ['start' => 'bukan tanggal'])]);

        $row = $batch->rows()->sole();
        $this->assertSame('valid', $row->status);
        $this->assertSame([], $row->errors);
        $this->assertNull($row->normalized['effective_date']);
        $this->imports()->commit($batch);
        $this->assertSame(0, ServiceEvent::count());
    }

    public function test_pasang_baru_creates_a_pasang_baru_event_with_bandwidth_and_the_file_fee(): void
    {
        $batch = $this->import([$this->row('4700020-8003', ['type' => 'PASANG BARU', 'start' => '2025-12-10', 'psb_fee' => 750000, 'bw' => '20 Mbps'])]);

        $event = $this->period->serviceEvents()->where('sid', '4700020-8003')->sole();
        $this->assertSame('pasang_baru', $event->event_type);
        $this->assertSame('2025-12-10', $event->effective_date->toDateString());
        $this->assertSame($this->bw['20']->id, $event->attributes['bandwidth_option_id']);
        $this->assertEquals(750000, $event->attributes['fee']);
        $this->assertSame($batch->id, $event->import_batch_id);
    }

    public function test_pasang_baru_fee_of_zero_is_kept_and_the_calculator_charges_no_installation(): void
    {
        $this->import([$this->row('4700020-8004', ['type' => 'PASANG BARU', 'start' => '2025-12-10', 'psb_fee' => 0])]);

        $event = $this->period->serviceEvents()->sole();
        $this->assertArrayHasKey('fee', $event->attributes);
        $this->assertEquals(0, $event->attributes['fee']);
        $result = app(InvoiceCalculator::class)->calculate($this->period->fresh());
        $this->assertSame('0.00', $result['lines'][0]['charges']['installation']);
        $this->assertSame([], $this->issueSids($result, ['missing_fee']));
    }

    public function test_pasang_baru_without_a_fee_falls_back_to_the_tariff_installation_price(): void
    {
        $this->import([$this->row('4700020-8005', ['type' => 'PASANG BARU', 'start' => '2025-12-10', 'psb_fee' => null])]);

        $this->assertArrayNotHasKey('fee', $this->period->serviceEvents()->sole()->attributes);
        $result = app(InvoiceCalculator::class)->calculate($this->period->fresh());
        $this->assertSame('500000.00', $result['lines'][0]['charges']['installation']);
    }

    public function test_pemutusan_creates_a_pemutusan_event_on_its_date(): void
    {
        $this->import([$this->row('4700020-8006', ['type' => 'PEMUTUSAN', 'start' => '2025-12-20'])]);

        $event = $this->period->serviceEvents()->sole();
        $this->assertSame('pemutusan', $event->event_type);
        $this->assertSame('2025-12-20', $event->effective_date->toDateString());
        $this->assertSame('4700020-8006', $event->sid);
    }

    public function test_relokasi_creates_an_event_on_the_first_day_of_the_period_without_needing_a_date(): void
    {
        $batch = $this->stage([$this->row('4700020-8007', ['type' => 'RELOKASI', 'start' => null, 'relocation_fee' => 250000])]);

        $this->assertSame('valid', $batch->rows()->sole()->status);
        $this->imports()->commit($batch);
        $event = $this->period->serviceEvents()->sole();
        $this->assertSame('relokasi', $event->event_type);
        $this->assertSame('2025-12-01', $event->effective_date->toDateString());
        $this->assertEquals(250000, $event->attributes['fee']);
    }

    public function test_upgrade_and_downgrade_events_carry_the_new_bandwidth_from_their_own_column(): void
    {
        $this->import([
            $this->row('4700020-8008', ['type' => 'UPGRADE', 'start' => '2025-12-15', 'bw' => '10 Mbps', 'upgrade' => '20 Mbps']),
            $this->row('4700020-8009', ['type' => 'DOWNGRADE', 'start' => '2025-12-16', 'bw' => '50 Mbps', 'downgrade' => '10 Mbps']),
        ]);

        $upgrade = $this->period->serviceEvents()->where('sid', '4700020-8008')->sole();
        $this->assertSame('upgrade', $upgrade->event_type);
        $this->assertSame('2025-12-15', $upgrade->effective_date->toDateString());
        $this->assertSame($this->bw['20']->id, $upgrade->attributes['bandwidth_option_id']);
        $downgrade = $this->period->serviceEvents()->where('sid', '4700020-8009')->sole();
        $this->assertSame('downgrade', $downgrade->event_type);
        $this->assertSame($this->bw['10']->id, $downgrade->attributes['bandwidth_option_id']);
        $this->assertSame($this->bw['50']->id, $this->line('4700020-8009')->existing_bandwidth_option_id, 'the existing bandwidth stays the starting one');
    }

    public function test_upgrade_and_downgrade_without_their_bandwidth_column_are_row_errors(): void
    {
        $batch = $this->stage([
            $this->row('4700020-8010', ['type' => 'UPGRADE', 'start' => '2025-12-15']),
            $this->row('4700020-8011', ['type' => 'DOWNGRADE', 'start' => '2025-12-15', 'upgrade' => '20 Mbps']),
        ]);

        $this->assertStringContainsString('Bandwidth upgrade belum cocok', $this->rowErrors($batch, '4700020-8010'));
        $this->assertStringContainsString('Bandwidth downgrade belum cocok', $this->rowErrors($batch, '4700020-8011'), 'the upgrade column does not serve a downgrade');
    }

    public function test_dated_type_orders_without_a_date_are_row_errors(): void
    {
        $batch = $this->stage([
            $this->row('4700020-8012', ['type' => 'PASANG BARU']),
            $this->row('4700020-8013', ['type' => 'PEMUTUSAN']),
            $this->row('4700020-8014', ['type' => 'UPGRADE', 'upgrade' => '20 Mbps']),
        ]);

        foreach (['4700020-8012', '4700020-8013', '4700020-8014'] as $sid) {
            $this->assertStringContainsString('Tanggal mulai wajib diisi', $this->rowErrors($batch, $sid), $sid);
        }
        $this->assertSame(3, $batch->fresh()->failed_rows);
    }

    public function test_start_date_must_fall_inside_the_usage_month(): void
    {
        $batch = $this->stage([
            $this->row('4700020-8015', ['type' => 'PASANG BARU', 'start' => '2026-01-05']),
            $this->row('4700020-8016', ['type' => 'PEMUTUSAN', 'start' => '2025-11-30']),
            $this->row('4700020-8017', ['type' => 'PASANG BARU', 'start' => '2025-12-01']),
            $this->row('4700020-8018', ['type' => 'PEMUTUSAN', 'start' => '2025-12-31']),
        ]);

        $this->assertStringContainsString('tidak berada dalam bulan pemakaian', $this->rowErrors($batch, '4700020-8015'));
        $this->assertStringContainsString('tidak berada dalam bulan pemakaian', $this->rowErrors($batch, '4700020-8016'));
        $this->assertSame('', $this->rowErrors($batch, '4700020-8017'), 'the first day of the month is inside');
        $this->assertSame('', $this->rowErrors($batch, '4700020-8018'), 'the last day of the month is inside');
    }

    public function test_unknown_type_order_is_a_row_error(): void
    {
        $batch = $this->stage([$this->row('4700020-8019', ['type' => 'MANAGE ROUTER']), $this->row('4700020-8020')]);

        $errors = $this->rowErrors($batch, '4700020-8019');
        $this->assertStringContainsString('MANAGE ROUTER', $errors);
        $this->assertStringContainsString('tidak dikenal', $errors);
        $this->assertCommitFails($batch, 'Selesaikan semua kesalahan');
        $this->assertSame(0, InvoiceLine::count());
    }

    // ---------------------------------------------------------------- rule 5: downtime

    /** @return array<string, array{string, ?string, string, string}> */
    public static function downtimeWindows(): array
    {
        return [
            'eksisting covers the whole usage month' => ['EKSISTING', null, '2025-12-01 00:00:00', '2026-01-01 00:00:00'],
            'pasang baru starts on its date' => ['PASANG BARU', '2025-12-10', '2025-12-10 00:00:00', '2026-01-01 00:00:00'],
            'pemutusan ends on its date (stored as the next day)' => ['PEMUTUSAN', '2025-12-20', '2025-12-01 00:00:00', '2025-12-21 00:00:00'],
        ];
    }

    #[DataProvider('downtimeWindows')]
    public function test_downtime_becomes_one_eligible_aggregate_covering_the_active_window(string $type, ?string $date, string $from, string $until): void
    {
        $batch = $this->import([$this->row('4700020-9001', ['type' => $type, 'start' => $date, 'downtime' => 3600])]);

        $outage = $this->period->outages()->sole();
        $this->assertSame('4700020-9001', $outage->sid);
        $this->assertSame('aggregate', $outage->kind);
        $this->assertSame('eligible', $outage->review_status);
        $this->assertSame(3600, $outage->duration_seconds);
        $this->assertSame($from, $outage->coverage_start->toDateTimeString());
        $this->assertSame($until, $outage->coverage_end->toDateTimeString());
        $this->assertSame($batch->id, $outage->import_batch_id);

        // The calculator accepts exactly this coverage for the shortened window.
        $result = app(InvoiceCalculator::class)->calculate($this->period->fresh());
        $this->assertSame(3600, $result['lines'][0]['downtime_seconds']);
        $this->assertSame([], $this->issueSids($result, ['sla_coverage', 'invalid_downtime']));
    }

    public function test_row_without_a_downtime_value_creates_no_outage(): void
    {
        $this->import([$this->row('4700020-9002', ['downtime' => null])]);

        $this->assertSame(0, OutageEvent::count());
    }

    /** @return array<string, array{string, ?string, int}> */
    public static function windowLimits(): array
    {
        return [
            'whole usage month (31 days)' => ['EKSISTING', null, 31 * 86400],
            'pasang baru on 30 Dec (2 days)' => ['PASANG BARU', '2025-12-30', 2 * 86400],
            'pemutusan on 2 Dec (2 days)' => ['PEMUTUSAN', '2025-12-02', 2 * 86400],
        ];
    }

    #[DataProvider('windowLimits')]
    public function test_downtime_longer_than_the_active_window_is_a_row_error(string $type, ?string $date, int $window): void
    {
        $batch = $this->stage([
            $this->row('4700020-9003', ['type' => $type, 'start' => $date, 'downtime' => $window]),
            $this->row('4700020-9004', ['type' => $type, 'start' => $date, 'downtime' => $window + 1]),
        ]);

        $this->assertSame('', $this->rowErrors($batch, '4700020-9003'), 'a downtime equal to the window is accepted');
        $this->assertStringContainsString('Downtime melebihi', $this->rowErrors($batch, '4700020-9004'));
    }

    public function test_existing_downtime_of_the_ssid_is_a_conflict_until_the_operator_chooses_the_file(): void
    {
        $master = $this->master('4700020-9005');
        $this->inPeriod($master);
        $old = $this->period->outages()->create(['sid' => '4700020-9005', 'kind' => 'aggregate', 'duration_seconds' => 120, 'review_status' => 'eligible', 'reason' => 'Diisi operator', 'source' => ['origin' => 'operator_manual']]);

        $batch = $this->stage([$this->row('4700020-9005', ['downtime' => 600])]);

        $row = $batch->rows()->sole();
        $this->assertSame('error', $row->status);
        $this->assertNotEmpty(array_filter($row->errors, fn ($e) => str_starts_with($e, DowntimeConflicts::ERROR_PREFIX)));
        $this->assertCommitFails($batch, 'Selesaikan semua kesalahan');
        $this->assertSame('eligible', $old->fresh()->review_status, 'nothing changes while the conflict is open');

        $this->imports()->resolve($row, ['conflict_choice' => 'use_file'], false, 'x');
        $this->assertSame('valid', $row->fresh()->status);
        $this->imports()->commit($batch);

        $this->assertSame('excluded', $old->fresh()->review_status);
        $active = DowntimeConflicts::active($this->period, '4700020-9005');
        $this->assertCount(1, $active);
        $this->assertSame(600, $active->first()->duration_seconds);
        $this->assertSame('eligible', $active->first()->review_status);
        $this->assertSame($batch->id, $active->first()->import_batch_id);
    }

    // ---------------------------------------------------------------- rule 6 and 7: dates and period column

    public function test_garbage_activation_date_is_tolerated_for_an_ssid_already_in_the_master(): void
    {
        $master = $this->master('4700020-9101');
        $before = $master->fresh()->service_start_date->toDateString();

        $batch = $this->stage([$this->row('4700020-9101', ['activation' => 'belum aktif'])]);

        $row = $batch->rows()->sole();
        $this->assertSame('valid', $row->status);
        $this->assertSame([], $row->errors);
        $this->assertNull($row->normalized['activation_date']);
        $this->imports()->commit($batch);
        $this->assertSame($before, $master->fresh()->service_start_date->toDateString());
    }

    public function test_period_column_must_equal_the_usage_month(): void
    {
        $batch = $this->stage([
            $this->row('4700020-9201', ['period' => 202512]),
            $this->row('4700020-9202', ['period' => 202601]),
            $this->row('4700020-9203', ['period' => 202511]),
        ]);

        $this->assertSame('', $this->rowErrors($batch, '4700020-9201'));
        $this->assertStringContainsString('Period di file (202601)', $this->rowErrors($batch, '4700020-9202'));
        $this->assertStringContainsString('202512', $this->rowErrors($batch, '4700020-9202'), 'the message names the expected month');
        $this->assertStringContainsString('Period di file (202511)', $this->rowErrors($batch, '4700020-9203'));
    }

    // ---------------------------------------------------------------- rule 8: Telkom's Eksisting price

    public function test_reference_price_is_stored_in_the_snapshot_only_for_eksisting_rows(): void
    {
        $this->import([
            $this->row('4700020-9301', ['price' => 3500000]),
            $this->row('4700020-9302', ['type' => 'PASANG BARU', 'start' => '2025-12-10', 'price' => 2000000]),
            $this->row('4700020-9303', ['type' => 'PEMUTUSAN', 'start' => '2025-12-20', 'price' => 2000000]),
        ]);

        $this->assertEquals(3500000, $this->line('4700020-9301')->service_snapshot['reference_price']);
        $this->assertNull($this->line('4700020-9302')->service_snapshot['reference_price']);
        $this->assertNull($this->line('4700020-9303')->service_snapshot['reference_price']);
    }

    public function test_tariff_discrepancy_is_flagged_for_a_differing_eksisting_price_but_not_for_a_prorated_psb_price(): void
    {
        $this->import([
            $this->row('4700020-9304', ['price' => 3000000]),
            $this->row('4700020-9305', ['price' => 3500000]),
            $this->row('4700020-9306', ['type' => 'PASANG BARU', 'start' => '2025-12-10', 'price' => 2000000]),
        ]);

        $result = app(InvoiceCalculator::class)->calculate($this->period->fresh());

        $this->assertSame(['4700020-9305'], $this->issueSids($result, ['tariff_discrepancy']));
        $this->assertSame([], $this->issueSids($result, ['tariff', 'missing_fee']));
    }

    // ---------------------------------------------------------------- rule 9: remapping

    public function test_remapping_a_field_to_nothing_rebuilds_the_rows_without_it(): void
    {
        $batch = $this->stage([$this->row('4700020-9401', ['price' => 3500000]), $this->row('4700020-9402')]);
        $mapping = $batch->metadata['analysis']['mapping'];
        $this->assertSame('Y', $mapping['reference_price']);
        $this->assertSame('3500000', $batch->rows()->orderBy('row_number')->first()->normalized['reference_price']);
        $mapping['reference_price'] = null;

        $this->imports()->remapHistory($batch, 'JAN26', $mapping);

        $batch->refresh();
        $rows = $batch->rows()->orderBy('row_number')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(2, $batch->total_rows);
        foreach ($rows as $row) {
            $this->assertSame('valid', $row->status);
            $this->assertArrayNotHasKey('reference_price', $row->normalized);
            $this->assertArrayHasKey('bandwidth', $row->normalized, 'the other fields are kept');
        }
        $this->assertNull($batch->metadata['analysis']['mapping']['reference_price']);
        $this->assertSame('G', $batch->metadata['analysis']['mapping']['sid']);
        $this->assertSame('0.00', $this->imports()->historySummary($batch)['price_total']);
        $this->imports()->commit($batch);
        $this->assertNull($this->line('4700020-9401')->service_snapshot['reference_price']);
    }

    public function test_remapping_to_another_sheet_re_analyses_that_sheet(): void
    {
        $this->master('4700020-9411');
        $this->master('4700020-9412');
        $book = $this->book();
        $this->telkomSheet($book, [$this->row('4700020-9401'), $this->row('4700020-9402'), $this->row('4700020-9403'), $this->row('4700020-9404')]);
        $other = $book->createSheet();
        $other->setTitle('Data Lain');
        foreach (['A' => 'SSID', 'B' => 'Jenis Kantor', 'C' => 'Alamat', 'D' => 'Kategori Lokasi', 'E' => 'Layanan', 'F' => 'Type Order', 'G' => 'Bandwidth Eksisting'] as $letter => $label) {
            $other->setCellValue($letter.'1', $label);
        }
        foreach (['4700020-9411', '4700020-9412'] as $i => $sid) {
            foreach (['A' => $sid, 'B' => 'KANTOR PUSAT', 'C' => 'Jl. Lain '.$sid, 'D' => 'ATM', 'E' => 'TELKOM ASTINET', 'F' => 'EKSISTING', 'G' => '20 Mbps'] as $letter => $value) {
                $other->setCellValue($letter.($i + 2), $value);
            }
        }
        $batch = $this->imports()->stage($this->period, $this->save($book), 'history');
        $this->assertSame('JAN26', $batch->metadata['analysis']['sheet']);
        $this->assertSame(4, $batch->rows()->count());

        // The mapping passed along belongs to the old sheet and is not used.
        $this->imports()->remapHistory($batch, 'Data Lain', ['sid' => 'Z']);

        $batch->refresh();
        $this->assertSame('Data Lain', $batch->metadata['analysis']['sheet']);
        $this->assertSame('A', $batch->metadata['analysis']['mapping']['sid']);
        $this->assertSame(2, $batch->total_rows);
        $rows = $batch->rows()->orderBy('row_number')->get();
        $this->assertSame(['4700020-9411', '4700020-9412'], $rows->pluck('sid')->all());
        $this->assertSame(['Data Lain', 'Data Lain'], $rows->pluck('sheet')->all());
        $this->assertSame([2, 3], $rows->pluck('row_number')->all());
        $this->assertSame(['valid', 'valid'], $rows->pluck('status')->all());
    }

    public function test_commit_refuses_while_a_required_field_is_unmapped_and_names_it(): void
    {
        $this->master('4700020-9421');
        $batch = $this->stage([$this->row('4700020-9421')]);
        $mapping = $batch->metadata['analysis']['mapping'];
        $mapping['address'] = null;

        $this->imports()->remapHistory($batch, 'JAN26', $mapping);

        $this->assertSame('valid', $batch->rows()->sole()->status, 'the row itself is fine; only the mapping is incomplete');
        $this->assertSame(['address'], $this->imports()->historySummary($batch->fresh())['missing_fields']);
        $this->assertCommitFails($batch, 'Alamat');
        $this->assertNull($batch->fresh()->committed_at);
        $this->assertSame(0, InvoiceLine::count());
    }

    // ---------------------------------------------------------------- rule 10: summary

    public function test_history_summary_counts_what_the_import_would_change(): void
    {
        $a = $this->master('4700020-0101');
        $b = $this->master('4700020-0102');
        $x = $this->master('4700020-0103');
        $y = $this->master('4700020-0104');
        $this->master('4700020-0105');
        foreach ([$a, $b, $x, $y] as $link) {
            $this->inPeriod($link);
        }
        $this->addInputs($this->period, '4700020-0103'); // one event, one downtime and one adjustment

        $batch = $this->stage([
            $this->row('4700020-0101', ['price' => 1000000]),
            $this->row('4700020-0102', ['type' => 'PASANG BARU', 'start' => '2025-12-10', 'price' => 2000000, 'downtime' => 60]),
            $this->row('4700020-0105', ['price' => 1500000, 'downtime' => 0]),
            $this->row('4700020-0106', ['type' => 'RELOKASI', 'price' => null]),
        ]);

        $summary = $this->imports()->historySummary($batch);

        $this->assertSame([], $summary['missing_fields']);
        $this->assertSame(2, $summary['matched'], 'rows whose SSID already has a line in the period');
        $this->assertSame(['4700020-0105', '4700020-0106'], $summary['added'], 'rows without a line yet, in file order');
        $this->assertSame(['4700020-0106'], $summary['new_to_master']);
        $this->assertSame([
            ['sid' => '4700020-0103', 'branch' => 'KANTOR CABANG SURABAYA', 'inputs' => 3],
            ['sid' => '4700020-0104', 'branch' => 'KANTOR CABANG SURABAYA', 'inputs' => 0],
        ], $summary['removed']);
        $this->assertEquals(['existing' => 2, 'pasang_baru' => 1, 'relokasi' => 1], $summary['orders']);
        $this->assertSame('4500000.00', $summary['price_total'], 'a blank price counts as 0');
        $this->assertSame(2, $summary['downtime_rows'], 'a downtime of 0 is still a downtime row');
    }

    // ---------------------------------------------------------------- rule 11 and staging

    public function test_staging_the_identical_file_again_returns_the_same_batch(): void
    {
        $file = $this->workbook([$this->row('4700020-0201'), $this->row('4700020-0202')]);
        $copy = $this->copyOf($file, 'jan-copy.xlsx');

        $first = $this->imports()->stage($this->period, $file, 'history');
        $second = $this->imports()->stage($this->period, $copy, 'history');
        $changed = $this->imports()->stage($this->period, $this->workbook([$this->row('4700020-0201'), $this->row('4700020-0203')]), 'history');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ImportBatch::where('file_hash', $first->file_hash)->count());
        $this->assertSame(2, $first->rows()->count());
        $this->assertNotSame($first->id, $changed->id);
        $this->assertSame(2, ImportBatch::count());
    }

    public function test_stage_reads_every_ssid_row_and_skips_headers_and_footer(): void
    {
        $batch = $this->stage([$this->row('4700020-0301'), $this->row('4700020-0302'), $this->row('4700020-0303')]);

        $this->assertSame('history', $batch->import_type);
        $this->assertSame(3, $batch->total_rows);
        $this->assertSame('JAN26', $batch->metadata['analysis']['sheet']);
        $this->assertSame(6, $batch->metadata['analysis']['first_row']);
        $this->assertSame('pending', $batch->status);
        $rows = $batch->rows()->orderBy('row_number')->get();
        $this->assertSame([6, 7, 8], $rows->pluck('row_number')->all());
        $this->assertSame(['4700020-0301', '4700020-0302', '4700020-0303'], $rows->pluck('sid')->all());
        $this->assertSame(['valid', 'valid', 'valid'], $rows->pluck('status')->all());
        $this->assertSame([1, 2, 3], $rows->map(fn ($r) => $r->normalized['_order'])->all());
    }

    public function test_an_ignored_error_row_does_not_block_the_commit_and_is_not_applied(): void
    {
        $batch = $this->stage([$this->row('4700020-0401'), $this->row('4700020-0402', ['type' => 'MANAGE ROUTER'])]);
        $this->assertCommitFails($batch, 'Selesaikan semua kesalahan');

        $this->imports()->resolve($batch->rows()->where('sid', '4700020-0402')->sole(), [], true, 'Bukan layanan tagihan');
        $this->imports()->commit($batch);

        $this->assertSame('ignored', $batch->rows()->where('sid', '4700020-0402')->sole()->status);
        $this->assertSame(['4700020-0401'], $this->period->lines()->get()->map->billingSid()->all());
        $this->assertNull(BankJatimLink::where('ssid', '4700020-0402')->first());
        $this->assertNotNull($batch->fresh()->committed_at);
    }

    // ---------------------------------------------------------------- helpers: master data

    private function tariff(string $service, string $bandwidth, string $monthly, ?LocationCategory $category = null): PriceMaster
    {
        return PriceMaster::create([
            'location_category_id' => $category?->id, 'service_id' => $this->service[$service]->id, 'bandwidth_option_id' => $this->bw[$bandwidth]->id, 'router_mode' => 'none',
            'monthly_price' => $monthly, 'installation_price' => '500000', 'relocation_price' => '0', 'effective_start_date' => '2020-01-01', 'is_active' => true,
        ]);
    }

    /** A master service: Surabaya branch, Kantor Kas, VPN, 10 Mbps unless overridden. */
    private function master(string $sid, array $override = []): BankJatimLink
    {
        $data = array_merge([
            'ssid' => $sid, 'office_type_id' => $this->office['sby']->id, 'location_category_id' => $this->category['kk']->id, 'service_id' => $this->service['vpn']->id,
            'current_bandwidth_option_id' => $this->bw['10']->id, 'address' => 'Jl. Master '.$sid, 'service_start_date' => '2020-01-01', 'link_status' => 'active',
        ], $override);
        $data['sequence_in_office_type'] = (BankJatimLink::where('office_type_id', $data['office_type_id'])->max('sequence_in_office_type') ?? 0) + 1;

        return BankJatimLink::create($data);
    }

    private function inPeriod(BankJatimLink $link): InvoiceLine
    {
        return PeriodInventory::add($this->period, $link);
    }

    /** One service event, one downtime and one adjustment of the SSID in the given period. */
    private function addInputs(InvoicePeriod $period, string $sid, string $tag = 'x'): void
    {
        $date = $period->start_date->copy()->addDays(19)->toDateString();
        ServiceEvent::create(['invoice_period_id' => $period->id, 'sid' => $sid, 'event_type' => 'pemutusan', 'effective_date' => $date, 'attributes' => []]);
        $period->outages()->create(['sid' => $sid, 'kind' => 'aggregate', 'duration_seconds' => 100, 'review_status' => 'eligible', 'reason' => $tag]);
        $period->adjustments()->create(['sid' => $sid, 'kind' => 'manual_credit', 'amount' => 1000, 'reason' => $tag, 'reference' => 'ref-'.$tag]);
    }

    private function masterState(BankJatimLink $link): array
    {
        $fresh = $link->fresh();

        return [
            'office_type_id' => $fresh->office_type_id, 'location_category_id' => $fresh->location_category_id, 'service_id' => $fresh->service_id,
            'current_bandwidth_option_id' => $fresh->current_bandwidth_option_id, 'address' => $fresh->address,
            'billing_start_date' => $fresh->billing_start_date?->toDateString(), 'link_status' => $fresh->link_status,
        ];
    }

    // ---------------------------------------------------------------- helpers: import and results

    private function imports(): SourceImportService
    {
        return app(SourceImportService::class);
    }

    /** @param list<array<string, mixed>> $rows */
    private function stage(array $rows): ImportBatch
    {
        return $this->imports()->stage($this->period, $this->workbook($rows), 'history');
    }

    /** Stages and commits the rows; returns the committed batch. */
    private function import(array $rows): ImportBatch
    {
        $batch = $this->stage($rows);
        $this->imports()->commit($batch);

        return $batch->fresh();
    }

    private function assertCommitFails(ImportBatch $batch, string $message): void
    {
        try {
            $this->imports()->commit($batch);
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, implode(' ', $e->errors()['import']));

            return;
        }
        $this->fail('The commit should have been refused.');
    }

    private function rowErrors(ImportBatch $batch, string $sid): string
    {
        return implode(' | ', $batch->rows()->where('sid', $sid)->firstOrFail()->errors ?? []);
    }

    private function line(string $sid): ?InvoiceLine
    {
        return PeriodInventory::findLine($this->period, $sid);
    }

    /** @return list<string> "sid=number" in line-number order */
    private function lineOrder(): array
    {
        return $this->period->lines()->orderBy('line_number')->get()->map(fn ($line) => $line->billingSid().'='.$line->line_number)->all();
    }

    /** @return list<string> SIDs that carry one of the issue codes */
    private function issueSids(array $result, array $codes): array
    {
        return array_values(array_map(fn ($issue) => $issue['sid'], array_filter($result['issues'], fn ($issue) => in_array($issue['code'], $codes, true))));
    }

    // ---------------------------------------------------------------- helpers: workbook

    /**
     * One data row, keyed by the semantic names of COLUMN. A null value leaves the cell empty.
     *
     * @param  array<string, mixed>  $cells
     * @return array<string, mixed>
     */
    private function row(string $sid, array $cells = []): array
    {
        return ['sid' => $sid] + $cells + [
            'branch' => 'KANTOR CABANG SURABAYA', 'address' => 'Jl. Workbook '.$sid, 'category' => 'KANTOR KAS', 'service' => 'TELKOM VPN IP',
            'type' => 'EKSISTING', 'period' => 202512, 'bw' => '10 Mbps', 'activation' => '2020-01-01', 'price' => 3000000,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function workbook(array $rows, string $name = 'jan.xlsx'): UploadedFile
    {
        $book = $this->book();
        $this->telkomSheet($book, $rows);

        return $this->save($book, $name);
    }

    private function book(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        return $book;
    }

    /** Telkom invoice layout: title row 2, group headers row 3, sub-headers row 4, letter row 5, data from row 6, then a footer row. */
    private function telkomSheet(Spreadsheet $book, array $rows, string $title = 'JAN26'): Worksheet
    {
        $sheet = $book->createSheet();
        $sheet->setTitle($title);
        $letters = array_map([Coordinate::class, 'stringFromColumnIndex'], range(1, 30));
        $header = [
            2 => ['B' => '• TELKOM - MONTHLY RECURRING CHARGES'],
            3 => [
                'A' => 'NO.', 'B' => 'JENIS KANTOR', 'C' => 'ALAMAT', 'D' => 'KATEGORI LOKASI', 'E' => 'LAYANAN', 'F' => 'TYPE ORDER', 'G' => 'ID', 'H' => 'PERIOD',
                'I' => 'Bandwidth', 'L' => 'IP Host Telkom', 'M' => 'TANGGAL AKTIVASI', 'N' => "TANGGAL MULAI AKTIF LAYANAN \n (UPGRADE/DOWNGRADE)",
                'S' => 'DURASI DOWNTIME', 'Y' => 'HARGA (Rp)',
            ],
            4 => [
                'G' => 'SID JAN 2025', 'I' => 'Eksisting BW', 'J' => 'Upgrade BW', 'K' => 'Downgrade BW', 'S' => '(Detik)', 'Y' => 'Eksisting',
                'Z' => 'Upgrade (Prorate)', 'AA' => 'Downgrade (Prorate)', 'AB' => 'Cabut (Prorate)', 'AC' => 'PSB (Prorate)', 'AD' => 'Relokasi',
            ],
            5 => array_combine($letters, $letters),
        ];
        foreach ($header as $number => $cells) {
            foreach ($cells as $letter => $value) {
                $sheet->setCellValue($letter.$number, $value);
            }
        }
        $sheet->mergeCells('I3:K3');
        $sheet->mergeCells('Y3:AD3');
        $number = 6;
        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$number, $number - 5);
            foreach ($row as $field => $value) {
                if ($value !== null) {
                    $sheet->setCellValue(self::COLUMN[$field].$number, $value);
                }
            }
            $number++;
        }
        $sheet->setCellValue('A'.$number, count($rows));
        $sheet->setCellValue('B'.$number, 'TOTAL');
        $sheet->setCellValue('Y'.$number, 123456789);

        return $sheet;
    }

    private function save(Spreadsheet $book, string $name = 'jan.xlsx'): UploadedFile
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'history_import_'.bin2hex(random_bytes(6)).'.xlsx';
        $this->files[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function copyOf(UploadedFile $file, string $name): UploadedFile
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'history_import_'.bin2hex(random_bytes(6)).'.xlsx';
        $this->files[] = $path;
        copy($file->getRealPath(), $path);

        return new UploadedFile($path, $name, null, null, true);
    }
}
