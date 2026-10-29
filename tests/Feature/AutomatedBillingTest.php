<?php

namespace Tests\Feature;

use App\Jobs\GenerateInvoiceDocuments;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\InvoiceSla;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\BillingMath;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceDocumentGenerator;
use App\Services\Billing\InvoiceFinalizer;
use App\Services\Billing\SourceImportService;
use App\Services\Billing\SourceValue;
use App\Services\Billing\WorkbookSourceReader;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AutomatedBillingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'TEST', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $master = BankJatimLink::create(['ssid' => '0012-ABC', 'office_type_id' => $office->id, 'sequence_in_office_type' => 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Test alamat yang panjang untuk pengecekan invoice', 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
        $p = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => auth()->id()]);
        $line = InvoiceLine::create(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $master->id, 'line_number' => 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address]);
        $rate = PriceMaster::create(['location_category_id' => $category->id, 'service_id' => $service->id, 'bandwidth_option_id' => $bw->id, 'monthly_price' => '2319819', 'installation_price' => '1981982', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true]);
        $p->update(['sla_coverage' => ['sids' => [$master->ssid]]]);
        $line->update(['traffic_exception_reason' => 'MRTG belum tersedia dari penyedia.']);

        return [$p, $line, $rate, $master, $bw];
    }

    public function test_calendar_usage_month_and_daily_rounding(): void
    {
        foreach ([[8, 2026, '2026-07-01', '2026-07-31'], [9, 2026, '2026-08-01', '2026-08-31'], [3, 2024, '2024-02-01', '2024-02-29'], [3, 2025, '2025-02-01', '2025-02-28'], [1, 2027, '2026-12-01', '2026-12-31']] as [$m,$y,$a,$b]) {
            $this->assertSame($a, InvoicePeriod::generateStartDate($m, $y)->toDateString());
            $this->assertSame($b, InvoicePeriod::generateEndDate($m, $y)->toDateString());
        }
        $this->assertSame('1623867.00', BillingMath::prorate('2319819', 21, 30));
        $this->assertSame('773270.00', BillingMath::prorate('2319819', 10, 30));
        $this->assertSame('433330.00', BillingMath::prorate('1300000', 10, 30));
        $this->assertSame('27341.59', BillingMath::sum([BillingMath::slaCredit('1300000', 66240, 2678400, '0.99'), BillingMath::slaCredit('3671171', 32760, 2678400, '0.99')]));
    }

    public function test_unknown_outages_exclusions_overlap_and_exact_seconds(): void
    {
        $a = Carbon::parse('2026-08-01');
        $b = Carbon::parse('2026-09-01');
        $this->assertNull(InvoiceCalculator::eligibleDowntime([], $a, $b, [], '001')['seconds']);
        $e = fn ($start, $end, $status = 'eligible') => ['kind' => 'interval', 'opened_at' => $start, 'closed_at' => $end, 'review_status' => $status];
        $coverage = ['sids' => ['001']];
        $r = InvoiceCalculator::eligibleDowntime([$e('2026-08-01 00:00:00', '2026-08-01 00:01:01'), $e('2026-08-01 00:00:30', '2026-08-01 00:02:00'), $e('2026-08-02', '2026-08-03', 'excluded')], $a, $b, $coverage, '001');
        $this->assertSame(120, $r['seconds']);
        $this->assertSame(61, InvoiceCalculator::eligibleDowntime([$e('2026-07-31 23:59:00', '2026-08-01 00:01:01')], $a, $b, $coverage, '001')['seconds']);
        $this->assertNull(InvoiceCalculator::eligibleDowntime([$e('2026-08-02', '2026-08-03', 'excluded')], $a, $b, [], '001')['seconds']);
        $this->assertNull(InvoiceCalculator::eligibleDowntime([['kind' => 'evidence', 'review_status' => 'excluded']], $a, $b, [], '001')['seconds']);
        $this->assertSame(0, InvoiceCalculator::eligibleDowntime([], $a, $b, ['sids' => ['001']], '001')['seconds']);
    }

    public function test_fixed_sla_target_preserves_actual_downtime_and_credit_calculation(): void
    {
        [$p, $line, $rate, $master] = $this->fixture();
        $profile = $p->billingProfile;
        $settings = $profile->settings;
        $settings['sla_target'] = '0.995';
        // Simulate an older stored profile without changing its historical version.
        DB::table('billing_profiles')->where('id', $profile->id)->update(['settings' => json_encode($settings)]);
        $line->update(['sla_target' => '0.95']);
        $this->assertSame('0.990000', $line->fresh()->sla_target);
        $sla = InvoiceSla::create(['invoice_line_id' => $line->id, 'sla_pks' => '0.95']);
        $this->assertSame('0.990000', $sla->fresh()->sla_pks);
        $p->outages()->create(['sid' => $master->ssid, 'kind' => 'aggregate', 'duration_seconds' => 53568, 'review_status' => 'eligible', 'reason' => 'Reviewed full usage month']);

        $result = app(InvoiceCalculator::class)->calculate($p->fresh());
        $this->assertSame('0.99', $result['profile']['settings']['sla_target']);
        $this->assertSame('0.99', $result['lines'][0]['sla_target']);
        $this->assertSame('0.98000000', $result['lines'][0]['sla']);
        $this->assertSame('23198.19', $result['lines'][0]['sla_credit']);
        $this->assertSame('0.990000', $line->fresh()->sla->sla_pks);
        $this->assertTrue($line->fresh()->isBelowSla());
        $this->assertTrue($line->fresh()->sla->isBelowThreshold());
        $this->assertSame(23198.19, $line->fresh()->calculateRestitutionOverSla());
        $this->assertSame('0.995', $profile->fresh()->settings['sla_target']);
        $this->get('/invoice-periods/'.$p->id.'/step/2')->assertOk()->assertSee('Target SLA')->assertSee('SLA aktual')->assertSee('99%')->assertSee('98,0000%');
        $result['lines'][0]['sla_target'] = '0.995';
        $p->refresh()->update(['calculation' => $result]);
        $this->assertFalse($p->fresh()->calculationIsCurrent());
        $this->postJson('/invoice-periods/'.$p->id.'/finalize', ['input_version' => $p->input_version])->assertUnprocessable();
    }

    public function test_billing_profile_target_is_fixed_and_rejects_other_percentages(): void
    {
        [$p] = $this->fixture();
        $payload = array_merge($p->billingProfile->settings, ['name' => 'Fixed SLA', 'reason' => 'Set billing defaults', 'sla_target' => '0.995']);
        $this->postJson('/billing-masters/profiles', $payload)->assertUnprocessable()->assertJsonValidationErrors('sla_target');
        unset($payload['sla_target']);
        $this->post('/billing-masters/profiles', $payload)->assertRedirect();
        $this->assertSame('0.99', BillingProfile::latest('id')->first()->settings['sla_target']);
        $this->get('/price-masters?section=profiles')->assertOk()->assertSee('Target SLA (tetap 99%)')->assertSee('readonly', false);
    }

    public function test_flat_month_relocation_and_partial_event_segments(): void
    {
        [$p,$line,$rate,$master,$bw] = $this->fixture();
        ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'event_type' => 'relokasi', 'effective_date' => '2026-08-15', 'attributes' => ['fee' => '0']]);
        $c = app(InvoiceCalculator::class)->calculate($p);
        $this->assertSame('2319819.00', $c['totals']['gross']);
        $this->assertSame('0.00', $c['totals']['sla_credit']);
        $this->assertSame(1, $c['totals']['service_count']);
        $this->assertSame('2319819.00', $c['totals']['components']['existing']);
        $this->assertSame('0.00', $c['totals']['components']['relocation']);
        $p->serviceEvents()->delete();
        ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'event_type' => 'pemutusan', 'effective_date' => '2026-08-10', 'attributes' => []]);
        $p->markInputsChanged('test_event');
        $c = app(InvoiceCalculator::class)->calculate($p);
        $this->assertSame('773270.00', $c['totals']['gross']);
    }

    public function test_missing_and_ambiguous_tariffs_and_credits_block(): void
    {
        [$p,$line,$rate,$master] = $this->fixture();
        $rate->update(['is_active' => false]);
        $c = app(InvoiceCalculator::class)->calculate($p);
        $this->assertNull($c['totals']['grand_total']);
        $this->assertTrue(collect($c['issues'])->contains('code', 'tariff'));
        $rate->update(['is_active' => true]);
        $copy = $rate->replicate();
        $copy->effective_start_date = '2026-08-01';
        $copy->save();
        $c = app(InvoiceCalculator::class)->calculate($p);
        $this->assertTrue(collect($c['issues'])->contains('code', 'tariff'));
        InvoiceAdjustment::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'kind' => 'tariff_override', 'amount' => '100.00', 'reason' => 'Contract exception', 'reference' => 'Ref 1']);
        InvoiceAdjustment::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'kind' => 'manual_credit', 'amount' => '110.50', 'reason' => 'Approved credit', 'reference' => 'Ref 2']);
        $c = app(InvoiceCalculator::class)->calculate($p);
        $this->assertSame('-10.50', $c['lines'][0]['net']);
        $this->assertTrue(collect($c['issues'])->contains('code', 'excess_credit'));
    }

    public function test_import_preserves_text_decimals_zero_and_is_idempotent(): void
    {
        [$p,$line] = $this->fixture();
        Storage::fake('local');
        $imports = app(SourceImportService::class);
        $file = UploadedFile::fake()->createWithContent('mrtg.csv', "sid,inbound_kbps,outbound_kbps,coverage_start,coverage_end\n0012-ABC,12.125,0,2026-08-01,2026-08-31\n");
        $b = $imports->stage($p, $file, 'traffic');
        $r = $b->rows()->first();
        $this->assertSame('0012-ABC', $r->sid);
        $this->assertSame('12.125', $r->normalized['inbound_kbps']);
        $this->assertSame('0', $r->normalized['outbound_kbps']);
        $this->assertSame('valid', $r->status, implode(' ', $r->errors));
        $imports->commit($b);
        $imports->commit($b);
        $this->assertSame($b->id, $imports->stage($p, $file, 'traffic')->id);
        $line->refresh();
        $this->assertSame('12.125000000000', $line->trafficUsage->inbound_kbps);
        $c = app(InvoiceCalculator::class)->calculate($p);
        $this->assertSame('4.059450', $c['lines'][0]['traffic']['volume_gb']);
        $bad = UploadedFile::fake()->createWithContent('wrong.csv', "sid,inbound_kbps,outbound_kbps,coverage_start,coverage_end\n0012-ABC,1,2,2026-07-01,2026-07-31\n");
        $batch = $imports->stage($p, $bad, 'traffic');
        $this->assertSame('error', $batch->rows()->first()->status);
        $this->assertSame('1234.50', SourceValue::number('1.234,50'));
        $this->assertSame('001-02', SourceValue::sid('001-02'));
        $this->expectException(\InvalidArgumentException::class);
        SourceValue::date('07/08/2026');
    }

    public function test_read_views_do_not_recalculate_and_locked_routes_reject_edits(): void
    {
        [$p,$line] = $this->fixture();
        $before = $line->updated_at;
        foreach (['/dashboard?period='.$p->id, '/invoice-periods/'.$p->id.'/step/1', '/invoice-periods/'.$p->id.'/step/2', '/invoice-periods/'.$p->id.'/step/3', '/price-masters', '/price-masters?section=services', '/invoices'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertNull($p->fresh()->calculation);
        $this->assertEquals($before, $line->fresh()->updated_at);
        $p->update(['is_locked' => true]);
        $this->postJson('/invoice-periods/'.$p->id.'/calculate', ['input_version' => $p->input_version])->assertStatus(423);
        $this->postJson(route('billing.service-events', $p), [])->assertStatus(423);
        $this->postJson(route('billing.new-service', $p), [])->assertStatus(423);
        $this->deleteJson('/invoice-periods/'.$p->id)->assertStatus(423);
    }

    public function test_finalization_retry_and_revision_keep_history_and_both_outputs(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$p,$line,$rate,$master] = $this->fixture();
        $c = app(InvoiceCalculator::class)->calculate($p);
        $p->refresh();
        $this->postJson('/invoice-periods/'.$p->id.'/finalize', ['input_version' => $p->input_version - 1])->assertStatus(409);
        $f = app(InvoiceFinalizer::class);
        $r = $f->finalize($p, $p->input_version, auth()->id());
        $again = $f->finalize($p, $p->input_version, auth()->id());
        $this->assertSame($r->id, $again->id);
        $this->assertSame(1, Invoice::count());
        (new GenerateInvoiceDocuments($r->id))->handle(app(InvoiceDocumentGenerator::class));
        $r->refresh();
        $this->assertSame('ready', $r->status);
        $this->assertSame(3, $r->outputs()->count(), 'Excel, PDF and the summary picture.');
        $oldHash = $r->snapshot_hash;
        $book = IOFactory::load(Storage::disk('local')->path($r->outputs()->where('output_type', 'excel_invoice')->first()->file_path));
        $this->assertSame(3, $book->getSheetCount());
        $this->assertSame(['Harga', 'Detail Layanan', 'Summary Invoice'], $book->getSheetNames());
        $detail = $book->getSheetByName('Detail Layanan');
        $this->assertSame('0012-ABC', $detail->getCell('G6')->getValue());
        $this->assertEquals(1, $detail->getCell('T6')->getValue());
        $this->assertEquals(0.99, $detail->getCell('U6')->getValue());
        $this->assertEquals((float) $c['totals']['net_exact'], $detail->getCell('AF'.$detail->getHighestRow())->getValue());
        $summary = $book->getSheetByName('Summary Invoice');
        $grandTotal = collect($summary->toArray(null, true, false))->first(fn ($row) => $row[1] === 'Grand Total Nilai Tagihan');
        $this->assertEquals((float) $c['totals']['grand_total'], $grandTotal[3]);
        $book->disconnectWorksheets();
        (new GenerateInvoiceDocuments($r->id))->handle(app(InvoiceDocumentGenerator::class));
        $this->assertSame(3, $r->outputs()->count(), 'Excel, PDF and the summary picture.');
        $this->get('/invoices')->assertOk();
        $this->get('/invoices/'.$r->invoice_id)->assertOk();
        $f->revise($p, 'Correction to approved billing');
        $p->refresh();
        $line->refresh()->update(['traffic_exception_reason' => 'Updated documented reason']);
        $p->markInputsChanged('updated_exception');
        app(InvoiceCalculator::class)->calculate($p);
        $p->refresh();
        $second = $f->finalize($p, $p->input_version, auth()->id());
        $this->assertSame(2, $second->version);
        $this->assertSame($oldHash, $r->fresh()->snapshot_hash);
        $master->delete();
        $this->assertTrue(InvoiceLine::whereKey($line->id)->exists());
        $this->assertSame($oldHash, $r->fresh()->snapshot_hash);
    }

    public function test_upgrade_multiple_events_router_modes_and_rounding(): void
    {
        [$p,$line,$rate,$master,$bw] = $this->fixture();
        $newBw = BandwidthOption::create(['bandwidth_code' => '1024', 'bandwidth_name' => '1 Mbps', 'value_kbps' => 1024]);
        $new = $rate->replicate();
        $new->bandwidth_option_id = $newBw->id;
        $new->monthly_price = '1300000';
        $new->save();
        ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2026-08-22', 'attributes' => ['bandwidth_option_id' => $newBw->id]]);
        ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'event_type' => 'relokasi', 'effective_date' => '2026-08-26', 'attributes' => ['fee' => '0']]);
        $c = app(InvoiceCalculator::class)->calculate($p);
        $this->assertSame('2013864.00', $c['lines'][0]['gross']);
        $this->assertSame('MULTIPLE', $c['lines'][0]['order']);
        $this->assertSame('1623867.00', $c['lines'][0]['charges']['existing']);
        $this->assertSame('389997.00', $c['lines'][0]['charges']['upgrade']);
        $this->assertSame($bw->bandwidth_name, $c['lines'][0]['bandwidth']);
        $this->assertSame('1 Mbps', $c['lines'][0]['bandwidth_change']);
        $this->assertStringContainsString('Upgrade / Downgrade', view('billing.invoice-pdf', ['snapshot' => $c, 'version' => 1])->render());
        $p->serviceEvents()->delete();
        $managed = $rate->replicate();
        $managed->router_mode = 'managed';
        $managed->monthly_price = '2389819';
        $managed->save();
        $s = InvoiceCalculator::serviceSnapshot($line);
        $s['router_mode'] = 'managed';
        $line->update(['service_snapshot' => $s]);
        InvoiceAdjustment::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'kind' => 'manual_credit', 'amount' => '0.75', 'reason' => 'fractional credit', 'reference' => 'Reference']);
        $c = app(InvoiceCalculator::class)->calculate($p);
        $this->assertSame('2389819.00', $c['totals']['gross']);
        $this->assertSame('2389818.25', $c['totals']['net_exact']);
        $this->assertSame('-0.25', $c['totals']['rounding']);
        $this->assertSame('2389818', $c['totals']['subtotal']);
        $this->assertSame('262880', $c['totals']['tax']);
        $this->assertStringNotContainsString('Upgrade / Downgrade', view('billing.invoice-pdf', ['snapshot' => $c, 'version' => 1])->render());
    }

    public function test_staged_failure_rolls_back_and_resolution_keeps_history(): void
    {
        [$p,$line,$rate,$master] = $this->fixture();
        Storage::fake('local');
        $imports = app(SourceImportService::class);
        $file = UploadedFile::fake()->createWithContent('events.csv', "sid,event_type,effective_date,bandwidth,new_address,fee\n0012-ABC,relokasi,2026-08-15,,,0\nunknown,pemutusan,07/08/2026,,,\n");
        $b = $imports->stage($p, $file, 'events');
        $r = $b->rows()->where('status', 'error')->first();
        $this->assertNotNull($r);
        try {
            $imports->commit($b);
            $this->fail('Expected invalid commit');
        } catch (ValidationException) {
        }
        $this->assertSame(0, $p->serviceEvents()->count());
        $this->assertSame(1, $p->fresh()->input_version);
        $imports->resolve($r, [], true, 'Unknown SID not part of this invoice');
        $r->refresh();
        $this->assertCount(1, $r->resolution_history);
        $imports->commit($b);
        $this->assertSame(1, $p->serviceEvents()->count());
        $this->assertSame(2, $p->fresh()->input_version);
        $imports->commit($b);
        $this->assertSame(1, $p->serviceEvents()->count());
    }

    public function test_generation_failure_retains_snapshot_and_can_retry(): void
    {
        [$p] = $this->fixture();
        Queue::fake();
        Storage::fake('local');
        app(InvoiceCalculator::class)->calculate($p);
        $p->refresh();
        $r = app(InvoiceFinalizer::class)->finalize($p, $p->input_version, auth()->id());
        $hash = $r->snapshot_hash;
        Storage::disk('local')->put('billing/invoices/'.$r->invoice_id.'/v1', 'simulate a directory creation failure');
        try {
            (new GenerateInvoiceDocuments($r->id))->handle(app(InvoiceDocumentGenerator::class));
            $this->fail('Expected failure');
        } catch (\Throwable $e) {
            $this->assertNotNull($e);
        }
        $this->assertSame('failed', $r->fresh()->status);
        $this->assertSame($hash, $r->fresh()->snapshot_hash);
        $this->assertSame(0, $r->outputs()->count());
        Storage::disk('local')->delete('billing/invoices/'.$r->invoice_id.'/v1');
        (new GenerateInvoiceDocuments($r->id))->handle(app(InvoiceDocumentGenerator::class));
        $this->assertSame('ready', $r->fresh()->status);
        $this->assertSame($hash, $r->fresh()->snapshot_hash);
        $this->assertSame(1, Invoice::count());
    }

    public function test_retrying_old_generation_keeps_newer_draft_editable(): void
    {
        [$p] = $this->fixture();
        Queue::fake();
        Storage::fake('local');
        app(InvoiceCalculator::class)->calculate($p);
        $p->refresh();
        $finalizer = app(InvoiceFinalizer::class);
        $revision = $finalizer->finalize($p, $p->input_version, auth()->id());
        $revision->update(['status' => 'failed', 'generation_error' => 'Worker interrupted']);
        $hash = $revision->snapshot_hash;
        $finalizer->revise($p, 'Correct source before issuing next revision');
        $version = $p->fresh()->input_version;
        (new GenerateInvoiceDocuments($revision->id))->handle(app(InvoiceDocumentGenerator::class));
        $this->assertSame('ready', $revision->fresh()->status);
        $this->assertSame($hash, $revision->fresh()->snapshot_hash);
        $this->assertFalse($p->fresh()->isLocked());
        $this->assertSame($version, $p->fresh()->input_version);
        $this->assertSame('revision', $p->fresh()->status);
        $this->assertCount(3, $revision->outputs);
    }

    public function test_xlsx_shifted_tables_headers_and_formula_text(): void
    {
        [$p] = $this->fixture();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Downtime BJTM');
        $sheet->fromArray(['No', 'Nojar', 'Site', 'Duration Downtime Total (Menit)', 'Duration Downtime Total (Detik)'], null, 'A3');
        $sheet->fromArray([1, '0012-ABC', 'Site', 1, 61], null, 'A4');
        $sheet->fromArray(['No', 'Nojar', 'Site', 'Duration Downtime Total (Menit)', 'Duration Downtime Total (Detik)'], null, 'K3');
        $sheet->fromArray([null, 1, '0012-ABC', 'Shifted Site', 1, 61], null, 'K4');
        $path = tempnam(sys_get_temp_dir(), 'sijatim');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $records = (new WorkbookSourceReader)->read($path, 'outages', $p);
        unlink($path);
        $this->assertCount(2, $records);
        $this->assertSame(61, $records[1]['normalized']['duration_seconds']);
        $this->assertSame('0012-ABC', $records[1]['normalized']['sid']);
        $book = new Spreadsheet;
        $s = $book->getActiveSheet();
        $s->fromArray(['sid', 'branch', 'category', 'service', 'bandwidth', 'address'], null, 'A1');
        $s->fromArray(['0012-ABC', 'Cabang Test', 'KK', 'TELKOM VPN IP', '512 Kbps'], null, 'A2');
        $s->setCellValueExplicit('F2', '=HYPERLINK("https://invalid.example","instruction")', DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'sijatim');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $records = (new WorkbookSourceReader)->read($path, 'inventory', $p);
        unlink($path);
        $this->assertStringStartsWith('=HYPERLINK', $records[0]['normalized']['address']);
    }

    public function test_partial_traffic_preserves_precision_without_inventing_volume(): void
    {
        [$p, $line] = $this->fixture();
        $line->update(['traffic_exception_reason' => null]);
        Storage::fake('local');
        $imports = app(SourceImportService::class);
        $file = UploadedFile::fake()->createWithContent('partial.csv', "sid,inbound_kbps,outbound_kbps,coverage_start,coverage_end\n0012-ABC,12.123456789012,0,2026-08-01,2026-08-15\n");
        $batch = $imports->stage($p, $file, 'traffic');
        $imports->commit($batch);
        $result = app(InvoiceCalculator::class)->calculate($p);
        $this->assertSame('12.123456789012', $line->fresh()->trafficUsage->inbound_kbps);
        $this->assertSame('12.123456789012', $result['lines'][0]['traffic']['average_kbps']);
        $this->assertNull($result['lines'][0]['traffic']['volume_gb']);
        $this->assertSame('comparison', collect($result['issues'])->firstWhere('code', 'traffic_coverage')['severity']);
    }

    public function test_withdrawn_sources_restore_dates_and_remove_coverage(): void
    {
        [$p, $line, $rate, $master] = $this->fixture();
        $prior = InvoicePeriod::create(['month' => 8, 'year' => 2026, 'created_by' => auth()->id()]);
        $priorLine = InvoiceLine::create(['invoice_period_id' => $prior->id, 'bank_jatim_link_id' => $master->id, 'line_number' => 1, 'existing_bandwidth_option_id' => $line->existing_bandwidth_option_id]);
        Storage::fake('local');
        $imports = app(SourceImportService::class);
        $file = UploadedFile::fake()->createWithContent('termination.csv', "sid,event_type,effective_date\n0012-ABC,pemutusan,2026-08-10\n");
        $batch = $imports->stage($p, $file, 'events');
        $imports->commit($batch);
        $this->assertSame('2026-08-10', $master->fresh()->billing_end_date->toDateString());
        $this->assertNull($line->fresh()->service_snapshot['billing_end_date']);
        $this->assertNull($priorLine->fresh()->service_snapshot['billing_end_date']);
        $this->assertSame(2, $prior->fresh()->input_version);
        app(InvoiceCalculator::class)->calculate($p);
        $this->post(route('imports.discard', $batch), ['reason' => 'Termination source withdrawn after correction'])->assertRedirect();
        $this->assertNull($master->fresh()->billing_end_date);
        $this->assertSame(0, $p->serviceEvents()->count());
        $this->assertNull($p->fresh()->calculation);
        $result = app(InvoiceCalculator::class)->calculate($p);
        $this->assertSame('2319819.00', $result['totals']['gross']);

        $file = UploadedFile::fake()->createWithContent('zero.csv', "sid,kind,duration_seconds,review_status,reason\n0012-ABC,aggregate,0,eligible,Reviewed full window\n");
        $batch = $imports->stage($p, $file, 'outages');
        $imports->commit($batch);
        $p->update(['sla_coverage' => ['sids' => [$master->ssid], 'declarations' => [['batch_id' => $batch->id, 'sids' => [$master->ssid]]]]]);
        $this->post(route('imports.discard', $batch), ['reason' => 'Incomplete coverage found during review'])->assertRedirect();
        $this->assertSame([], $p->fresh()->sla_coverage['sids']);
        $result = app(InvoiceCalculator::class)->calculate($p);
        $this->assertNull($result['lines'][0]['sla']);
    }

    public function test_live_readiness_and_recalculation_preserve_starting_service(): void
    {
        [$p, $line, $rate, $master] = $this->fixture();
        Storage::fake('local');
        $managed = $rate->replicate();
        $managed->router_mode = 'managed';
        $managed->monthly_price = '1300000';
        $managed->save();
        ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'event_type' => 'relokasi', 'effective_date' => '2026-08-22', 'attributes' => ['new_address' => 'New address', 'router_mode' => 'managed', 'fee' => '0']]);
        $calculator = app(InvoiceCalculator::class);
        $first = $calculator->calculate($p);
        $second = $calculator->calculate($p);
        $this->assertSame('2013864.00', $first['totals']['gross']);
        $this->assertSame($first['totals'], $second['totals']);
        $this->assertSame('none', $line->fresh()->service_snapshot['router_mode']);
        $this->assertSame('managed', $second['lines'][0]['resolved_service_snapshot']['router_mode']);
        $this->assertSame('New address', $second['lines'][0]['address']);
        $file = UploadedFile::fake()->createWithContent('waiting.csv', "sid,inbound_kbps,outbound_kbps,coverage_start,coverage_end\n0012-ABC,12,0,2026-08-01,2026-08-31\n");
        $batch = app(SourceImportService::class)->stage($p, $file, 'traffic');
        $this->getJson(route('billing.readiness', $p))->assertOk()->assertJsonPath('fresh', true)->assertJsonPath('ready', false);
        $this->assertTrue(collect($p->fresh()->readinessIssues())->contains('code', 'uncommitted_import'));
        $this->post(route('imports.discard', $batch), ['reason' => 'Upload cancelled before commitment'])->assertRedirect();
        $this->getJson(route('billing.readiness', $p))->assertOk()->assertJsonPath('ready', true);
    }

    public function test_monthly_workflow_records_manual_downtime_and_service_changes(): void
    {
        [$p, $line, $rate, $master, $bw] = $this->fixture();
        $this->get(route('invoice-periods.step1', $p))->assertOk()->assertSee('Layanan periode')->assertSee('Konfirmasi');

        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 61])->assertRedirect();
        $outage = $p->outages()->firstOrFail();
        $this->assertSame(61, $outage->duration_seconds);
        $this->assertSame('operator_manual', $outage->source['origin']);
        $this->assertSame(auth()->id(), $outage->reviewed_by);
        $this->assertSame('2026-08-01 00:00:00', $outage->coverage_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 00:00:00', $outage->coverage_end->format('Y-m-d H:i:s'));

        $this->post(route('billing.service-events', $p), ['sid' => $master->ssid, 'event_type' => 'upgrade', 'effective_date' => '2026-08-15', 'bandwidth_option_id' => $bw->id])->assertRedirect();
        $event = $p->serviceEvents()->firstOrFail();
        $this->assertSame('upgrade', $event->event_type);
        $this->assertSame(auth()->id(), $event->source['user_id']);
        $this->post(route('billing.service-events.remove', [$p, $event]), ['reason' => 'Input dikoreksi'])->assertRedirect();
        $this->assertDatabaseMissing('service_events', ['id' => $event->id]);
    }

    public function test_missing_mrtg_is_informational_and_does_not_block_finalization(): void
    {
        [$p, $line] = $this->fixture();
        $line->update(['traffic_exception_reason' => null]);
        $result = app(InvoiceCalculator::class)->calculate($p);
        $missing = collect($result['issues'])->firstWhere('code', 'traffic_missing');
        $this->assertSame('comparison', $missing['severity']);
        $this->assertTrue($p->fresh()->calculationIsCurrent());
        $this->assertFalse(collect($p->fresh()->readinessIssues())->contains(fn ($issue) => in_array($issue['severity'], ['blocking', 'exception'])));
    }

    public function test_zero_downtime_confirmation_is_explicit_and_does_not_overwrite_existing_rows(): void
    {
        [$p, $line, $rate, $master] = $this->fixture();
        $this->post(route('billing.downtime.confirm-zero', $p), ['expected_count' => 1])->assertRedirect();
        $zero = $p->outages()->firstOrFail();
        $this->assertSame(0, $zero->duration_seconds);
        $this->assertSame('operator_bulk_zero', $zero->source['origin']);
        $result = app(InvoiceCalculator::class)->calculate($p->fresh());
        $this->assertSame('1.00000000', $result['lines'][0]['sla']);
        $this->assertSame('0.00', $result['lines'][0]['sla_credit']);

        $zero->update(['review_status' => 'pending', 'duration_seconds' => 120, 'reason' => 'Review import diperlukan']);
        $this->post(route('billing.downtime.confirm-zero', $p), ['expected_count' => 0])->assertRedirect();
        $this->assertSame(120, $zero->fresh()->duration_seconds);
        $this->assertSame('pending', $zero->fresh()->review_status);
    }

    public function test_two_column_downtime_xlsx_matches_ssid_and_applies_seconds(): void
    {
        [$p, $line, $rate, $master] = $this->fixture();
        $this->get(route('billing.templates.downtime'))->assertOk()->assertDownload('Template_Downtime_SIJATIM.xlsx');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'SSID');
        $sheet->setCellValue('B1', 'Downtime (detik)');
        $sheet->setCellValueExplicit('A2', $master->ssid, DataType::TYPE_STRING);
        $sheet->setCellValue('B2', 3600);
        ob_start();
        (new Xlsx($book))->save('php://output');
        $xlsx = ob_get_clean();
        $book->disconnectWorksheets();
        $file = UploadedFile::fake()->createWithContent('Downtime.xlsx', $xlsx);

        $imports = app(SourceImportService::class);
        $batch = $imports->stage($p, $file, 'outages');
        $row = $batch->rows()->firstOrFail();
        $this->assertSame($master->ssid, $row->sid);
        $this->assertSame('aggregate', $row->normalized['kind']);
        $this->assertSame('eligible', $row->normalized['review_status']);
        $this->assertSame('3600', $row->normalized['duration_seconds']);
        $imports->commit($batch);

        $outage = $p->outages()->firstOrFail();
        $this->assertSame($master->ssid, $outage->sid);
        $this->assertSame(3600, $outage->duration_seconds);
        $this->assertSame('eligible', $outage->review_status);
        $this->assertSame($batch->id, $outage->import_batch_id);
    }
}
