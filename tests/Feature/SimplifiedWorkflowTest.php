<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\OutageEvent;
use App\Models\PriceMaster;
use App\Models\ScheduledServiceChange;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\DowntimeConflicts;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\ScheduledChangeApplier;
use App\Services\Billing\SourceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SimplifiedWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SEP26 invoice (usage August 2026) with one active master service.
     */
    private function fixture(): array
    {
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'TEST', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        $bw2 = BandwidthOption::create(['bandwidth_code' => '1024', 'bandwidth_name' => '1 Mbps', 'value_kbps' => 1024]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $master = BankJatimLink::create(['ssid' => '0012-ABC', 'office_type_id' => $office->id, 'sequence_in_office_type' => 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Test', 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
        $p = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => auth()->id()]);
        $line = InvoiceLine::create(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $master->id, 'line_number' => 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address]);
        PriceMaster::create(['location_category_id' => $category->id, 'service_id' => $service->id, 'bandwidth_option_id' => $bw->id, 'monthly_price' => '2319819', 'installation_price' => '1981982', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true]);

        return compact('p', 'line', 'master', 'bw', 'bw2', 'office', 'category', 'service');
    }

    public function test_future_dated_change_is_scheduled_and_applied_once_when_its_month_is_prepared(): void
    {
        ['p' => $p, 'master' => $master, 'bw2' => $bw2] = $this->fixture();

        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2026-09-10', 'bandwidth_option_id' => $bw2->id])
            ->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, 'Oktober 2026'));
        $this->assertSame(0, ServiceEvent::count());
        $change = ScheduledServiceChange::sole();
        $this->assertSame('scheduled', $change->status);
        $this->assertSame(1, $p->fresh()->input_version, 'Scheduling must not invalidate the current period.');

        // Creating OKT26 applies nothing yet: the SSID is not in the new period.
        $this->post(route('invoice-periods.store'), ['period_month' => 10, 'period_year' => 2026])->assertRedirect();
        $oct = InvoicePeriod::where('month', 10)->where('year', 2026)->sole();
        $this->assertSame('scheduled', $change->fresh()->status);

        // Adding the services applies the change to its own usage month.
        $this->post(route('billing.inventory-all', $oct))->assertRedirect();
        $event = $oct->serviceEvents()->sole();
        $this->assertSame('upgrade', $event->event_type);
        $this->assertSame('2026-09-10', $event->effective_date->toDateString());
        $this->assertSame($bw2->id, $event->attributes['bandwidth_option_id']);
        $this->assertSame('scheduled', $event->source['origin']);
        $this->assertSame('applied', $change->fresh()->status);
        $this->assertSame($oct->id, $change->fresh()->applied_period_id);

        app(ScheduledChangeApplier::class)->apply($oct->fresh());
        $this->assertSame(1, $oct->serviceEvents()->count());
    }

    public function test_scheduled_change_applies_immediately_to_an_existing_period_and_can_be_cancelled(): void
    {
        ['p' => $p, 'master' => $master, 'bw2' => $bw2] = $this->fixture();
        $oct = InvoicePeriod::create(['month' => 10, 'year' => 2026, 'created_by' => auth()->id()]);
        $this->post(route('billing.inventory-all', $oct))->assertRedirect();

        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'downgrade', 'effective_date' => '2026-09-05', 'bandwidth_option_id' => $bw2->id])->assertRedirect();
        $applied = $oct->serviceEvents()->sole();
        $this->assertSame('downgrade', $applied->event_type);

        // Cancelling the applied event also cancels its schedule.
        $this->post(route('billing.service-events.remove', [$oct, $applied]), ['reason' => 'Batal dari pelanggan'])->assertRedirect();
        $this->assertSame('cancelled', ScheduledServiceChange::find($applied->source['scheduled_change_id'])->status);

        // A change for a month without a period stays scheduled until cancelled.
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'pemutusan', 'effective_date' => '2026-10-05'])->assertRedirect();
        $pending = ScheduledServiceChange::where('status', 'scheduled')->sole();
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'scheduled']))->assertOk()->assertSee($master->ssid)->assertSee('Terjadwal');
        $this->post(route('billing.scheduled.cancel', $pending), ['reason' => 'Salah input'])->assertRedirect();
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->post(route('billing.scheduled.cancel', $pending), ['reason' => 'Salah input'])->assertStatus(422);
    }

    public function test_change_cannot_be_scheduled_into_a_locked_period_or_too_far_ahead(): void
    {
        ['p' => $p, 'master' => $master, 'bw2' => $bw2] = $this->fixture();
        InvoicePeriod::create(['month' => 10, 'year' => 2026, 'created_by' => auth()->id(), 'is_locked' => true]);

        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2026-09-10', 'bandwidth_option_id' => $bw2->id])->assertStatus(422);
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2027-12-01', 'bandwidth_option_id' => $bw2->id])->assertSessionHasErrors('effective_date');
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2026-07-31', 'bandwidth_option_id' => $bw2->id])->assertSessionHasErrors('effective_date');
        $this->assertSame(0, ScheduledServiceChange::count());
        $this->assertSame(0, ServiceEvent::count());
    }

    public function test_new_service_psb_registers_master_adds_line_and_records_installation(): void
    {
        ['p' => $p, 'bw' => $bw, 'office' => $office, 'category' => $category, 'service' => $service] = $this->fixture();
        $payload = ['ssid' => 'NEW-001', 'effective_date' => '2026-08-10', 'bandwidth_option_id' => $bw->id, 'office_type_id' => $office->id, 'location_category_id' => $category->id, 'service_id' => $service->id, 'address' => 'Jl. Baru 1'];

        $this->post(route('billing.new-service', $p), array_merge($payload, ['address' => '']))->assertSessionHasErrors('address');
        $this->assertNull(BankJatimLink::where('ssid', 'NEW-001')->first());

        $this->post(route('billing.new-service', $p), $payload)->assertRedirect(route('invoice-periods.step1', [$p, 'search' => 'NEW-001']));
        $master = BankJatimLink::where('ssid', 'NEW-001')->sole();
        $this->assertSame('2026-08-10', $master->billing_start_date->toDateString());
        $this->assertTrue($p->lines()->where('bank_jatim_link_id', $master->id)->exists());
        $event = $p->serviceEvents()->where('sid', 'NEW-001')->sole();
        $this->assertSame('pasang_baru', $event->event_type);
        $this->post(route('billing.new-service', $p), $payload)->assertStatus(422);
        $this->assertSame(1, $p->serviceEvents()->where('sid', 'NEW-001')->count());

        // New SSIDs default to no router; a managed router is chosen on the form and stored on the master.
        $this->assertSame('none', $master->billable_router_status);
        $this->post(route('billing.new-service', $p), array_merge($payload, ['ssid' => 'NEW-003', 'billable_router_status' => 'managed']))->assertRedirect();
        $this->assertSame('managed', BankJatimLink::where('ssid', 'NEW-003')->sole()->billable_router_status);
        $this->post(route('billing.new-service', $p), array_merge($payload, ['ssid' => 'NEW-004', 'billable_router_status' => 'wifi']))->assertSessionHasErrors('billable_router_status');

        // A future PSB is scheduled and lands in its own month when that period is created.
        $this->post(route('billing.new-service', $p), array_merge($payload, ['ssid' => 'NEW-002', 'effective_date' => '2026-09-03']))->assertRedirect();
        $this->assertFalse($p->lines()->whereHas('bankJatimLink', fn ($q) => $q->where('ssid', 'NEW-002'))->exists());
        $this->post(route('invoice-periods.store'), ['period_month' => 10, 'period_year' => 2026])->assertRedirect();
        $oct = InvoicePeriod::where('month', 10)->sole();
        $this->assertTrue($oct->lines()->whereHas('bankJatimLink', fn ($q) => $q->where('ssid', 'NEW-002'))->exists());
        $this->assertSame('pasang_baru', $oct->serviceEvents()->where('sid', 'NEW-002')->sole()->event_type);
    }

    public function test_master_save_without_reason_is_allowed_and_audited(): void
    {
        $this->fixture();
        $this->post('/billing-masters/contracts', ['contract_number' => 'ADD-01', 'contract_title' => 'Addendum 1', 'effective_date' => '2026-01-01', 'status' => 'active'])->assertRedirect();
        $audit = DB::table('billing_audit_events')->where('action', 'admin_contracts_saved')->latest('id')->first();
        $this->assertSame('Diperbarui melalui Master Harga', json_decode($audit->details, true)['reason']);
    }

    public function test_invoice_info_only_needs_number_and_dates_when_profile_has_customer_data(): void
    {
        ['p' => $p] = $this->fixture();
        $payload = ['billing_profile_id' => $p->billing_profile_id, 'metadata' => ['invoice_number' => 'INV-2026-09', 'issue_date' => '2026-09-01', 'due_date' => '2026-09-20']];
        $this->assertNotEmpty($p->billingProfile->settings['customer_name']);
        $this->post(route('billing.metadata', $p), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('INV-2026-09', $p->fresh()->invoice_metadata['invoice_number']);
        $this->assertArrayNotHasKey('customer_name', $p->fresh()->invoice_metadata);
    }

    public function test_step1_filters_follow_downtime_and_changes(): void
    {
        ['p' => $p, 'master' => $master, 'bw2' => $bw2] = $this->fixture();
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'unconfirmed']))->assertOk()->assertSee($master->ssid);

        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 99999999])->assertSessionHasErrors('downtime_seconds');
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 3000000])->assertStatus(422);
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 3600])->assertRedirect();
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'unconfirmed']))->assertOk()->assertDontSee('0012-ABC</span>', false);
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'downtime']))->assertOk()->assertSee($master->ssid)->assertSee('1 jam 0 menit');

        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'changed']))->assertOk()->assertSee('Tidak ada SSID yang cocok');
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2026-08-15', 'bandwidth_option_id' => $bw2->id])->assertRedirect();
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'changed']))->assertOk()->assertSee($master->ssid)->assertSee('Upgrade');
    }

    public function test_duplicate_downtime_needs_review_until_one_record_is_excluded(): void
    {
        ['p' => $p, 'master' => $master] = $this->fixture();
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 8640])->assertRedirect();
        // Same value arrives again from an uploaded file.
        $fromFile = $p->outages()->create(['sid' => $master->ssid, 'kind' => 'aggregate', 'duration_seconds' => 8640, 'review_status' => 'eligible', 'reason' => 'Dari file', 'import_batch_id' => null, 'source' => ['sheet' => 'Downtime', 'row' => 2]]);

        $this->get(route('invoice-periods.step1', $p))->assertOk()->assertSee('>Pilih nilai</button>', false)->assertSee('1 SSID punya lebih dari satu catatan downtime');
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 100])->assertSessionHasErrors('downtime_seconds');
        // The engine itself refuses two competing aggregates, so the invoice cannot be issued as is.
        $issues = collect(app(InvoiceCalculator::class)->calculate($p, false)['issues']);
        $this->assertTrue($issues->contains(fn ($i) => $i['code'] === 'sla_coverage' && $i['sid'] === $master->ssid));

        $this->post(route('billing.outages.review', $p), ['outage_ids' => [$fromFile->id], 'decision' => 'excluded', 'reason' => 'Duplikat dari file'])->assertRedirect();
        $page = $this->get(route('invoice-periods.step1', $p))->assertOk();
        $page->assertDontSee('>Pilih nilai</button>', false)->assertDontSee('SSID punya lebih dari satu catatan downtime')->assertSee('8.640 detik');
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'unconfirmed']))->assertOk()->assertSee('Tidak ada SSID yang cocok');
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 100])->assertRedirect();
        $this->assertSame(100, $p->outages()->where('review_status', 'eligible')->sole()->duration_seconds);
    }

    public function test_confirmed_zero_downtime_follows_the_window_when_a_cabut_shortens_it(): void
    {
        ['p' => $p, 'master' => $master] = $this->fixture();
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 0])->assertRedirect();
        $coverage = fn () => $p->outages()->where('review_status', 'eligible')->sole()->coverage_end->toDateString();
        $this->assertSame('2026-09-01', $coverage());

        // Changing the type order to Cabut must not leave a "downtime outside the window" error behind.
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'pemutusan', 'effective_date' => '2026-08-28'])->assertRedirect();
        $this->assertSame('2026-08-29', $coverage());
        $this->assertSame(0, $p->outages()->where('review_status', 'eligible')->sole()->duration_seconds);
        $this->assertNotEmpty($p->outages()->sole()->source['realigned']);
        $issues = collect(app(InvoiceCalculator::class)->calculate($p->fresh(), false)['issues']);
        $this->assertFalse($issues->contains(fn ($i) => $i['code'] === 'sla_coverage'), 'Zero downtime must stay valid after a Cabut.');
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'unconfirmed']))->assertOk()->assertSee('Tidak ada SSID yang cocok');

        // Cancelling the Cabut widens the window again; the shorter coverage is still accepted.
        $this->post(route('billing.service-events.remove', [$p, $p->serviceEvents()->sole()]), ['reason' => 'Salah input'])->assertRedirect();
        $issues = collect(app(InvoiceCalculator::class)->calculate($p->fresh(), false)['issues']);
        $this->assertFalse($issues->contains(fn ($i) => $i['code'] === 'sla_coverage'));
    }

    public function test_downtime_with_seconds_must_be_entered_again_after_the_window_changes(): void
    {
        ['p' => $p, 'master' => $master] = $this->fixture();
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 3600])->assertRedirect();
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'pemutusan', 'effective_date' => '2026-08-28'])->assertRedirect();

        // The seconds are kept, but the operator is told to enter them again instead of seeing a technical error.
        $this->assertSame(3600, $p->outages()->sole()->duration_seconds);
        $this->assertSame('2026-09-01', $p->outages()->sole()->coverage_end->toDateString());
        $issue = collect(app(InvoiceCalculator::class)->calculate($p->fresh(), false)['issues'])->firstWhere('code', 'sla_coverage');
        $this->assertStringContainsString('Isi ulang downtime', $issue['message']);
        $page = $this->get(route('invoice-periods.step1', $p))->assertOk()->assertSee('Isi ulang')->assertSee('masa aktif berubah');
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'unconfirmed']))->assertOk()->assertSee($master->ssid);

        // Saving it again (the max is now the 28 shortened days) clears the warning.
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 3600])->assertRedirect();
        $this->assertSame('2026-08-29', $p->outages()->sole()->coverage_end->toDateString());
        $this->assertFalse(collect(app(InvoiceCalculator::class)->calculate($p->fresh(), false)['issues'])->contains(fn ($i) => $i['code'] === 'sla_coverage'));
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'unconfirmed']))->assertOk()->assertSee('Tidak ada SSID yang cocok');
    }

    /**
     * Two-column downtime workbook (SSID, Downtime (detik)).
     */
    private function downtimeFile(string $sid, int $seconds): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'SSID');
        $sheet->setCellValue('B1', 'Downtime (detik)');
        $sheet->setCellValueExplicit('A2', $sid, DataType::TYPE_STRING);
        $sheet->setCellValue('B2', $seconds);
        ob_start();
        (new Xlsx($book))->save('php://output');
        $xlsx = ob_get_clean();
        $book->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent('Downtime-'.$seconds.'.xlsx', $xlsx);
    }

    public function test_uploaded_downtime_that_collides_with_a_manual_entry_requires_an_explicit_choice(): void
    {
        Storage::fake('local');
        ['p' => $p, 'master' => $master] = $this->fixture();
        $imports = app(SourceImportService::class);
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 3600])->assertRedirect();

        $batch = $imports->stage($p, $this->downtimeFile($master->ssid, 7200), 'outages');
        $row = $batch->rows()->sole();
        $this->assertSame('error', $row->status);
        $this->assertStringStartsWith('Bentrok:', $row->errors[0]);
        $this->assertStringContainsString('3.600 detik', $row->errors[0]);

        // Nothing can be applied while the collision is undecided.
        $this->post(route('imports.commit', $batch))->assertSessionHasErrors('import');
        $this->assertSame(1, $p->outages()->count());
        $this->get(route('imports.show', $batch))->assertOk()
            ->assertSee('Bentrok: 1 SSID sudah punya downtime')->assertSee('Pakai nilai dari file')->assertSee('Pertahankan yang sudah ada')->assertSee('7.200 detik')->assertSee('3.600 detik');
        $this->post(route('imports.conflicts', $batch), [])->assertSessionHasErrors('choices');

        // Use the file: the manual value is kept in history but no longer counts.
        $this->post(route('imports.conflicts', $batch), ['choices' => [$row->id => 'use_file']])->assertRedirect();
        $this->assertSame('valid', $row->fresh()->status);
        $this->post(route('imports.commit', $batch))->assertRedirect(route('invoice-periods.step1', $p));
        $active = DowntimeConflicts::active($p->fresh(), $master->ssid);
        $this->assertSame(7200, $active->sole()->duration_seconds);
        $old = $p->outages()->where('review_status', 'excluded')->sole();
        $this->assertSame(3600, $old->duration_seconds);
        $this->assertSame($batch->id, $old->source['superseded']['batch_id']);
        $this->get(route('invoice-periods.step1', $p))->assertOk()->assertSee('7.200 detik');

        // Withdrawing the file brings the earlier value back.
        $this->post(route('imports.discard', $batch), ['reason' => 'File salah'])->assertRedirect();
        $this->assertSame(3600, DowntimeConflicts::active($p->fresh(), $master->ssid)->sole()->duration_seconds);
        $this->assertSame(0, $p->outages()->where('import_batch_id', $batch->id)->count());
    }

    public function test_choosing_to_keep_the_existing_downtime_ignores_the_file_row(): void
    {
        Storage::fake('local');
        ['p' => $p, 'master' => $master] = $this->fixture();
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 3600])->assertRedirect();
        $batch = app(SourceImportService::class)->stage($p, $this->downtimeFile($master->ssid, 7200), 'outages');
        $row = $batch->rows()->sole();

        $this->post(route('imports.conflicts', $batch), ['choices' => [$row->id => 'keep_existing']])->assertRedirect();
        $this->assertSame('ignored', $row->fresh()->status);
        $this->post(route('imports.commit', $batch))->assertRedirect();
        $this->assertSame(1, $p->outages()->count());
        $this->assertSame(3600, $p->outages()->sole()->duration_seconds);
        $this->assertSame('eligible', $p->outages()->sole()->review_status);
    }

    /**
     * Same layout as downtimeFile(), with one row per SSID.
     */
    private function downtimeFileForMany(array $sids, int $seconds): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'SSID');
        $sheet->setCellValue('B1', 'Downtime (detik)');
        foreach (array_values($sids) as $i => $sid) {
            $sheet->setCellValueExplicit('A'.($i + 2), $sid, DataType::TYPE_STRING);
            $sheet->setCellValue('B'.($i + 2), $seconds);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');
        $xlsx = ob_get_clean();
        $book->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent('Downtime-many-'.$seconds.'.xlsx', $xlsx);
    }

    public function test_resolving_many_downtime_conflicts_loads_the_period_once(): void
    {
        Storage::fake('local');
        ['p' => $p, 'master' => $master, 'bw' => $bw, 'office' => $office, 'category' => $category, 'service' => $service] = $this->fixture();
        $sids = [$master->ssid];
        foreach (range(2, 5) as $n) {
            $extra = BankJatimLink::create(['ssid' => '0012-AB'.$n, 'office_type_id' => $office->id, 'sequence_in_office_type' => $n, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Test', 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
            InvoiceLine::create(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $extra->id, 'line_number' => $n, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $extra->address]);
            $sids[] = $extra->ssid;
        }
        foreach ($sids as $sid) {
            $this->post(route('billing.downtime', $p), ['sid' => $sid, 'downtime_seconds' => 3600])->assertRedirect();
        }
        $batch = app(SourceImportService::class)->stage($p, $this->downtimeFileForMany($sids, 7200), 'outages');
        $rows = $batch->rows()->orderBy('id')->get();
        $this->assertCount(count($sids), $rows);
        $this->assertSame(['error'], $rows->pluck('status')->unique()->all());

        $choices = $rows->mapWithKeys(fn ($row, $i) => [$row->id => $i < 2 ? 'keep_existing' : 'use_file'])->all();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->post(route('imports.conflicts', $batch), ['choices' => $choices])->assertRedirect()->assertSessionHasNoErrors();

        // The period middleware reads the period 3 times by itself; resolving rows must add none, whatever the row count.
        $reads = fn (string $table) => count(array_filter($queries, fn ($sql) => preg_match('/^\s*select\b/i', $sql) && preg_match('/\bfrom\s+["`\[]?'.$table.'\b/i', $sql)));
        $this->assertLessThanOrEqual(3, $reads('invoice_periods'), 'invoice_periods was read once per row.');
        $this->assertLessThanOrEqual(2, $reads('import_batches'), 'import_batches was read once per row.');
        $this->assertSame(['ignored', 'ignored', 'valid', 'valid', 'valid'], $rows->map(fn ($row) => $row->fresh()->status)->all());
    }

    public function test_uploaded_downtime_without_a_collision_needs_no_choice(): void
    {
        Storage::fake('local');
        ['p' => $p, 'master' => $master] = $this->fixture();
        $batch = app(SourceImportService::class)->stage($p, $this->downtimeFile($master->ssid, 7200), 'outages');
        $this->assertSame('valid', $batch->rows()->sole()->status);
        $this->post(route('imports.commit', $batch))->assertRedirect();
        $this->assertSame(7200, $p->outages()->sole()->duration_seconds);
    }

    public function test_replacing_a_value_that_came_from_a_file_needs_confirmation(): void
    {
        Storage::fake('local');
        ['p' => $p, 'master' => $master] = $this->fixture();
        $batch = app(SourceImportService::class)->stage($p, $this->downtimeFile($master->ssid, 7200), 'outages');
        $this->post(route('imports.commit', $batch))->assertRedirect();

        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 100])
            ->assertSessionHasErrors('downtime_seconds')->assertSessionHasInput('sid', $master->ssid);
        $this->assertSame(7200, DowntimeConflictsProbe::sole($p, $master->ssid)->duration_seconds);
        $this->get(route('invoice-periods.step1', $p))->assertOk()->assertSee('Ganti nilai dari file');

        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 100, 'replace_existing' => 1])->assertRedirect();
        $this->assertSame(100, DowntimeConflictsProbe::sole($p, $master->ssid)->duration_seconds);
        $this->assertSame('operator_manual', DowntimeConflictsProbe::sole($p, $master->ssid)->source['origin']);
        $this->assertSame('excluded', $p->outages()->where('import_batch_id', $batch->id)->sole()->review_status);
    }

    public function test_operator_can_pick_one_of_several_downtime_records_or_type_a_new_number(): void
    {
        ['p' => $p, 'master' => $master] = $this->fixture();
        $make = fn (int $seconds, string $origin) => $p->outages()->create(['sid' => $master->ssid, 'kind' => 'aggregate', 'duration_seconds' => $seconds, 'review_status' => 'eligible', 'reason' => 'x', 'source' => ['origin' => $origin]]);
        $a = $make(8640, 'operator_manual');
        $b = $make(9000, 'other');

        $this->post(route('billing.downtime.choose', $p), ['sid' => $master->ssid])->assertSessionHasErrors('choice');
        $this->post(route('billing.downtime.choose', $p), ['sid' => $master->ssid, 'choice' => 'new'])->assertSessionHasErrors('downtime_seconds');
        $this->post(route('billing.downtime.choose', $p), ['sid' => $master->ssid, 'choice' => '999999'])->assertStatus(422);

        $this->post(route('billing.downtime.choose', $p), ['sid' => $master->ssid, 'choice' => (string) $b->id])->assertRedirect();
        $this->assertSame('excluded', $a->fresh()->review_status);
        $this->assertSame('eligible', $b->fresh()->review_status);
        $this->assertSame(9000, DowntimeConflictsProbe::sole($p, $master->ssid)->duration_seconds);
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'conflict']))->assertOk()->assertSee('Tidak ada SSID yang cocok');

        // A new conflict, resolved by typing a number that replaces both records.
        $c = $make(500, 'operator_manual');
        $this->post(route('billing.downtime.choose', $p), ['sid' => $master->ssid, 'choice' => 'new', 'downtime_seconds' => 120])->assertRedirect();
        $this->assertSame(120, DowntimeConflictsProbe::sole($p, $master->ssid)->duration_seconds);
        $this->assertSame('excluded', $c->fresh()->review_status);
        $this->assertSame('excluded', $b->fresh()->review_status);
        $this->assertSame(4, OutageEvent::where('invoice_period_id', $p->id)->count(), 'No record is ever deleted.');
    }

    public function test_conflicting_records_show_as_a_plain_checklist_item_after_calculation(): void
    {
        ['p' => $p, 'master' => $master] = $this->fixture();
        foreach ([100, 200] as $seconds) {
            $p->outages()->create(['sid' => $master->ssid, 'kind' => 'aggregate', 'duration_seconds' => $seconds, 'review_status' => 'eligible', 'reason' => 'x', 'source' => ['origin' => 'operator_manual']]);
        }
        $p->markInputsChanged('test');
        app(InvoiceCalculator::class)->calculate($p->fresh());
        $this->get(route('invoice-periods.step2', $p))->assertOk()->assertSee('Downtime bentrok')->assertSee('Pilih nilai yang benar');
    }

    public function test_tables_show_the_location_category_and_label_the_branch(): void
    {
        ['p' => $p, 'master' => $master] = $this->fixture();
        // Fixture: branch "Cabang Test", category "KK". The branch must never stand alone where a category is expected.
        $this->get(route('invoice-periods.step1', $p))->assertOk()
            ->assertSeeInOrder(['sort=category', 'Kategori', 'sort=branch', 'Cabang · Alamat'], false)
            ->assertSee('<td class="whitespace-nowrap">KK</td>', false)
            ->assertSee('Cabang Cabang Test');

        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 0])->assertRedirect();
        app(InvoiceCalculator::class)->calculate($p->fresh());
        $this->get(route('invoice-periods.step2', $p))->assertOk()
            ->assertSee('Kategori · Layanan')->assertSee('Cabang Cabang Test')->assertSee('TELKOM VPN IP · 512 Kbps');
        $this->get(route('invoice-periods.step2', [$p, 'search' => 'kk']))->assertOk()->assertSee($master->ssid);
    }

    public function test_old_draft_type_order_without_a_date_can_be_marked_existing_from_step_one(): void
    {
        ['p' => $p, 'line' => $line, 'master' => $master, 'bw2' => $bw2] = $this->fixture();
        $relokasi = TypeOrder::create(['type_order_code' => 'relokasi', 'type_order_name' => 'Relokasi', 'sort_order' => 4]);
        $line->update(['type_order_id' => $relokasi->id]);
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 0])->assertRedirect();

        $issues = collect(app(InvoiceCalculator::class)->calculate($p->fresh(), false)['issues']);
        $this->assertTrue($issues->contains('code', 'missing_event'));
        $this->get(route('invoice-periods.step1', $p))->assertOk()->assertSee('Draf lama:')->assertSee('Tandai Existing')->assertSee('Status draf lama');
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'draft_order']))->assertOk()->assertSee($master->ssid);
        app(InvoiceCalculator::class)->calculate($p->fresh());
        $this->get(route('invoice-periods.step2', $p))->assertOk()->assertSee('Status order dari draf lama tanpa tanggal')->assertSee('Perbaiki di langkah 1');

        // Not allowed while a recorded change exists for the SSID.
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2026-08-10', 'bandwidth_option_id' => $bw2->id])->assertRedirect();
        $this->post(route('billing.mark-existing', $p), ['sid' => $master->ssid])->assertStatus(422);
        $this->post(route('billing.service-events.remove', [$p, $p->serviceEvents()->sole()]), ['reason' => 'Salah input'])->assertRedirect();

        $this->post(route('billing.mark-existing', $p), ['sid' => $master->ssid])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('existing', $line->fresh()->typeOrder->type_order_code);
        $this->get(route('invoice-periods.step1', [$p, 'filter' => 'draft_order']))->assertOk()->assertSee('Tidak ada SSID yang cocok');
        $this->assertFalse(collect(app(InvoiceCalculator::class)->calculate($p->fresh(), false)['issues'])->contains('code', 'missing_event'));
    }

    public function test_relocation_is_flagged_without_a_date_and_the_next_month_requires_the_new_address(): void
    {
        ['p' => $p, 'master' => $master] = $this->fixture();

        // SEP26 (usage August): flag only, no date and no address.
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'relokasi'])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'Oktober 2026'));
        $event = $p->serviceEvents()->sole();
        $this->assertSame('2026-08-01', $event->effective_date->toDateString());
        $this->assertArrayNotHasKey('new_address', $event->attributes);
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'relokasi'])->assertStatus(422);
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade'])->assertSessionHasErrors('effective_date');
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 0])->assertRedirect();
        $result = app(InvoiceCalculator::class)->calculate($p->fresh(), false);
        $this->assertSame('2319819.00', $result['totals']['gross'], 'A relocation flag keeps the full month price.');
        $this->assertSame('0.00', $result['totals']['components']['relocation']);
        $this->assertSame('Jl. Test', $result['lines'][0]['address']);
        $this->get(route('invoice-periods.step1', $p))->assertOk()->assertSee('Alamat baru diisi di tagihan Oktober 2026')->assertSee('tanpa tanggal');

        // OKT26: the same SSID must get its new address before issuing.
        $oct = InvoicePeriod::create(['month' => 10, 'year' => 2026, 'created_by' => auth()->id()]);
        $this->post(route('billing.inventory-all', $oct))->assertRedirect();
        $this->assertTrue(collect($oct->fresh()->readinessIssues())->contains(fn ($i) => $i['code'] === 'relocation_address' && $i['sid'] === $master->ssid));
        $this->get(route('invoice-periods.step1', $oct))->assertOk()->assertSee('1 SSID ditandai relokasi di tagihan September 2026')->assertSee('Isi alamat baru');
        $this->get(route('invoice-periods.step1', [$oct, 'filter' => 'relocation']))->assertOk()->assertSee($master->ssid);

        $this->post(route('billing.relocation-address', $oct), ['sid' => $master->ssid, 'new_address' => ''])->assertSessionHasErrors('new_address');
        $this->post(route('billing.relocation-address', $oct), ['sid' => $master->ssid, 'new_address' => 'Jl. Baru No. 1, Surabaya'])->assertRedirect();
        $line = $oct->lines()->sole();
        $this->assertSame('Jl. Baru No. 1, Surabaya', $line->service_snapshot['address']);
        $this->assertSame('Jl. Test', $line->old_address_snapshot);
        $this->assertSame('Jl. Baru No. 1, Surabaya', $master->fresh()->address);
        $this->assertFalse(collect($oct->fresh()->readinessIssues())->contains('code', 'relocation_address'));
        $this->get(route('invoice-periods.step1', $oct))->assertOk()->assertSee('Alamat baru (relokasi) sudah diisi')->assertSee('Jl. Baru No. 1, Surabaya');

        // Only SSIDs flagged last month accept a relocation address (SEP26 has no previous period here).
        $this->post(route('billing.relocation-address', $p), ['sid' => $master->ssid, 'new_address' => 'Jl. Lain 2'])->assertStatus(422);

        // Imported relocation rows may also come without a date.
        [$data, $errors] = app(SourceImportService::class)->normalize('events', ['sid' => $master->ssid, 'event_type' => 'relokasi'], $p);
        $this->assertSame([], $errors);
        $this->assertSame('2026-08-01', $data['effective_date']);
    }

    public function test_service_table_shows_the_effective_date_of_every_change(): void
    {
        ['p' => $p, 'master' => $master, 'bw2' => $bw2] = $this->fixture();
        $this->get(route('invoice-periods.step1', $p))->assertOk()->assertSeeInOrder(['sort=status', '<th>Tanggal efektif</th>', 'sort=downtime'], false);

        // Existing service: no date.
        $this->get(route('invoice-periods.step1', $p))->assertSee('<span class="text-gray-300">—</span>', false);

        // One change this month shows its date; a relocation flag has none.
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2026-08-15', 'bandwidth_option_id' => $bw2->id])->assertRedirect();
        $page = $this->get(route('invoice-periods.step1', $p))->assertOk();
        $page->assertSee('15/08/2026')->assertDontSee('<span class="text-gray-300">—</span>', false);

        // Several changes list each date with its type, including one scheduled for a later month.
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'relokasi'])->assertRedirect();
        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'pemutusan', 'effective_date' => '2026-10-05'])->assertRedirect();
        $page = $this->get(route('invoice-periods.step1', $p))->assertOk();
        $page->assertSee('15/08/2026')->assertSee('tanpa tanggal')->assertSee('05/10/2026')->assertSee('(terjadwal)');
        // Listed by date: the relocation flag counts as the first of the month, then the upgrade, then the scheduled cut.
        $page->assertSeeInOrder(['tanpa tanggal', '15/08/2026', '05/10/2026'], false);
    }

    public function test_sidebar_can_be_collapsed_and_data_entry_screens_start_collapsed(): void
    {
        ['p' => $p] = $this->fixture();
        $work = $this->get(route('invoice-periods.step1', $p))->assertOk();
        $work->assertSee('class="sij-aside ', false)->assertSee('class="sij-main ', false)->assertSee('onclick="sijToggleSidebar()"', false)->assertSee('Ciutkan menu');
        $work->assertSee("var group = 'work';", false);
        foreach ([route('invoice-periods.step2', $p), route('invoice-periods.step3', $p), route('invoice-periods.advanced', $p), route('price-masters.index')] as $url) {
            $this->get($url)->assertOk()->assertSee("var group = 'work';", false);
        }
        foreach ([route('dashboard'), route('invoice-periods.index'), route('invoices.index')] as $url) {
            $this->get($url)->assertOk()->assertSee("var group = 'other';", false);
        }
        // Menu entries keep a tooltip so the icons stay understandable when collapsed.
        $work->assertSee('title="Master Harga"', false)->assertSee('title="Periode Tagihan"', false);
    }

    public function test_legacy_routes_are_removed_and_pages_render_without_recalculating(): void
    {
        ['p' => $p, 'line' => $line] = $this->fixture();
        foreach (['ssid-data', 'ssid-format', 'check-lines', 'bandwidth-format', 'step2-template', 'ssid-add-form'] as $legacy) {
            $this->get('/invoice-periods/'.$p->id.'/'.$legacy)->assertNotFound();
        }
        $before = $line->fresh()->updated_at;
        foreach ([
            route('dashboard'), route('invoice-periods.index'), route('invoice-periods.create'), route('invoice-periods.edit', $p),
            route('invoice-periods.show', $p), route('invoice-periods.step1', $p), route('invoice-periods.step2', $p),
            route('invoice-periods.step3', $p), route('invoice-periods.advanced', $p),
            route('price-masters.index', ['section' => 'tariffs']), route('price-masters.index', ['section' => 'services', 'new' => 1]),
            route('price-masters.index', ['section' => 'contracts']), route('price-masters.index', ['section' => 'profiles']),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertNull($p->fresh()->calculation);
        $this->assertSame(1, $p->fresh()->input_version);
        $this->assertEquals($before, $line->fresh()->updated_at);
    }
}

/**
 * Test helper: the single downtime record that currently counts for a SID.
 */
final class DowntimeConflictsProbe
{
    public static function sole(InvoicePeriod $period, string $sid): OutageEvent
    {
        return DowntimeConflicts::active($period->fresh(), $sid)->sole();
    }
}
