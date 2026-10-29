<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\Service;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\PeriodInventory;
use App\Services\Billing\SourceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceLineGroupingTest extends TestCase
{
    use RefreshDatabase;

    private array $offices = [];

    private InvoicePeriod $period;

    private LocationCategory $category;

    private Service $service;

    private BandwidthOption $bw;

    private function fixture(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['A' => 'KANTOR PUSAT', 'B' => 'UTAMA', 'C' => 'BANYUWANGI'] as $code => $name) {
            $this->offices[$code] = OfficeType::create(['office_type_code' => $code, 'office_type_name' => $name, 'sort_order' => count($this->offices) + 1]);
        }
        $this->category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KANTOR KAS']);
        $this->service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $this->bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $this->period = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => auth()->id()]);
    }

    private function master(string $sid, string $office): BankJatimLink
    {
        $id = $this->offices[$office]->id;

        return BankJatimLink::create(['ssid' => $sid, 'office_type_id' => $id, 'sequence_in_office_type' => (BankJatimLink::where('office_type_id', $id)->max('sequence_in_office_type') ?? 0) + 1, 'location_category_id' => $this->category->id, 'service_id' => $this->service->id, 'current_bandwidth_option_id' => $this->bw->id, 'address' => 'Jl. '.$sid, 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
    }

    /**
     * Adds the SIDs to the period in the given order; the first letter of each SID is its branch.
     */
    private function addLines(string ...$sids): void
    {
        foreach ($sids as $sid) {
            PeriodInventory::add($this->period, $this->master($sid, $sid[0]));
        }
    }

    private function order(): array
    {
        return $this->period->lines()->orderBy('line_number')->get()->map(fn ($line) => $line->billingSid().'='.$line->line_number)->all();
    }

    public function test_new_line_lands_at_the_end_of_its_branch_and_numbers_are_sequential(): void
    {
        $this->fixture();
        $this->addLines('A1', 'A2', 'B1', 'B2', 'C1');
        $this->addLines('B3');
        $this->assertSame(6, InvoiceLine::where('invoice_period_id', $this->period->id)->where('bank_jatim_link_id', BankJatimLink::where('ssid', 'B3')->value('id'))->value('line_number'));

        $this->assertSame(2, InvoiceLine::regroup($this->period));

        $this->assertSame(['A1=1', 'A2=2', 'B1=3', 'B2=4', 'B3=5', 'C1=6'], $this->order());
        $this->assertSame(0, InvoiceLine::regroup($this->period->id), 'An already grouped period stays untouched.');
    }

    public function test_branches_keep_first_appearance_order_and_gaps_are_closed(): void
    {
        $this->fixture();
        foreach ([[4, 'C1'], [7, 'A1'], [9, 'C2'], [12, 'B1'], [15, 'A2']] as [$number, $sid]) {
            InvoiceLine::create(['invoice_period_id' => $this->period->id, 'bank_jatim_link_id' => $this->master($sid, $sid[0])->id, 'line_number' => $number, 'existing_bandwidth_option_id' => $this->bw->id]);
        }

        InvoiceLine::regroup($this->period);

        $this->assertSame(['C1=1', 'C2=2', 'A1=3', 'A2=4', 'B1=5'], $this->order());
    }

    public function test_snapshot_branch_wins_over_the_master_office_type(): void
    {
        $this->fixture();
        $this->addLines('A1', 'B1', 'A2');
        $line = PeriodInventory::findLine($this->period, 'A2');
        $line->update(['service_snapshot' => array_merge($line->service_snapshot, ['branch' => 'UTAMA'])]);

        // Master says KANTOR PUSAT, but the period snapshot says UTAMA, so the order stays as is.
        $this->assertSame(0, InvoiceLine::regroup($this->period));
        $this->assertSame(['A1=1', 'B1=2', 'A2=3'], $this->order());
    }

    public function test_locked_or_finalized_period_is_never_renumbered(): void
    {
        $this->fixture();
        $this->addLines('A1', 'B1', 'A2');

        InvoicePeriod::whereKey($this->period->id)->update(['is_locked' => true]);
        $this->assertSame(0, InvoiceLine::regroup($this->period));
        $this->assertSame(['A1=1', 'B1=2', 'A2=3'], $this->order());

        InvoicePeriod::whereKey($this->period->id)->update(['is_locked' => false, 'status' => InvoicePeriod::STATUS_FINALIZED]);
        $this->assertSame(0, InvoiceLine::regroup($this->period->id));
        $this->assertSame(['A1=1', 'B1=2', 'A2=3'], $this->order());

        InvoicePeriod::whereKey($this->period->id)->update(['status' => InvoicePeriod::STATUS_IN_PROGRESS]);
        $this->assertSame(2, InvoiceLine::regroup($this->period));
        $this->assertSame(['A1=1', 'A2=2', 'B1=3'], $this->order());
    }

    public function test_psb_form_inserts_the_new_service_after_its_branch(): void
    {
        $this->fixture();
        $this->addLines('A1', 'B1', 'C1');
        $payload = ['ssid' => 'B2', 'effective_date' => '2026-08-10', 'bandwidth_option_id' => $this->bw->id, 'office_type_id' => $this->offices['B']->id, 'location_category_id' => $this->category->id, 'service_id' => $this->service->id, 'address' => 'Jl. Baru'];

        $this->post(route('billing.new-service', $this->period), $payload)->assertRedirect();

        $this->assertSame(['A1=1', 'B1=2', 'B2=3', 'C1=4'], $this->order());
    }

    public function test_calculation_regroups_a_reopened_period_but_not_a_preview(): void
    {
        $this->fixture();
        $this->addLines('A1', 'B1', 'C1');
        // A PSB stored at the end before grouping existed, like SEP26's 2104103811 (no. 776 instead of 70).
        InvoiceLine::create(['invoice_period_id' => $this->period->id, 'bank_jatim_link_id' => $this->master('B2', 'B')->id, 'line_number' => 4, 'existing_bandwidth_option_id' => $this->bw->id]);
        InvoicePeriod::whereKey($this->period->id)->update(['is_locked' => false, 'status' => InvoicePeriod::STATUS_REVISION]);
        $calculator = app(InvoiceCalculator::class);

        $preview = $calculator->calculate($this->period, false);
        $this->assertSame(['A1=1', 'B1=2', 'C1=3', 'B2=4'], $this->order(), 'A preview does not write.');
        $this->assertSame('B2', end($preview['lines'])['sid']);

        $result = $calculator->calculate($this->period);

        $this->assertSame(['A1=1', 'B1=2', 'B2=3', 'C1=4'], $this->order());
        $this->assertSame(['A1' => 1, 'B1' => 2, 'B2' => 3, 'C1' => 4], array_column($result['lines'], 'number', 'sid'));
    }

    public function test_inventory_import_regroups_new_lines_after_the_batch(): void
    {
        $this->fixture();
        $this->addLines('A1', 'B1', 'C1');
        Storage::fake('local');
        $imports = app(SourceImportService::class);
        $csv = "sid,branch,category,service,bandwidth,address,activation_date\nA2,KANTOR PUSAT,KANTOR KAS,TELKOM VPN IP,512 Kbps,Jl. A2,2026-01-01\nB2,UTAMA,KANTOR KAS,TELKOM VPN IP,512 Kbps,Jl. B2,2026-01-01\n";

        $imports->commit($imports->stage($this->period, UploadedFile::fake()->createWithContent('inventory.csv', $csv), 'inventory'));

        $this->assertSame(['A1=1', 'A2=2', 'B1=3', 'B2=4', 'C1=5'], $this->order());
    }
}
