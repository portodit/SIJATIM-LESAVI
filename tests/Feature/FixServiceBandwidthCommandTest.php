<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoiceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FixServiceBandwidthCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $office = OfficeType::create(['office_type_code' => 'UTAMA', 'office_type_name' => 'UTAMA', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'atm', 'location_category_name' => 'ATM']);
        $service = Service::create(['service_code' => 'vpn', 'service_name' => 'TELKOM VPN IP']);
        $bw128 = BandwidthOption::create(['bandwidth_code' => '128', 'bandwidth_name' => '128 Kbps', 'value_kbps' => 128]);
        $bw512 = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        $na = BandwidthOption::create(['bandwidth_code' => 'na', 'bandwidth_name' => 'TIDAK BERLAKU', 'is_not_applicable' => true]);
        $period = InvoicePeriod::create(['month' => 8, 'year' => 2026, 'created_by' => $user->id]);
        $lines = [];
        foreach (['4700020-07944' => $bw512, '790038103' => $bw128] as $sid => $bw) {
            $master = BankJatimLink::create(['ssid' => $sid, 'office_type_id' => $office->id, 'sequence_in_office_type' => count($lines) + 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'address' => 'Alamat '.$sid, 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
            $line = InvoiceLine::create(['invoice_period_id' => $period->id, 'bank_jatim_link_id' => $master->id, 'line_number' => count($lines) + 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address]);
            $line->update(['service_snapshot' => InvoiceCalculator::serviceSnapshot($line)]);
            $lines[$sid] = $line;
        }

        return compact('period', 'lines', 'bw128', 'bw512', 'na');
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bw').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_preview_changes_nothing_and_apply_corrects_period_and_master(): void
    {
        ['period' => $period, 'lines' => $lines, 'bw128' => $bw128] = $this->fixture();
        $file = $this->csv("SSID,Bandwidth\n4700020-07944,128 kbps\n790038103, 128 KbPS \n");

        $this->artisan('billing:fix-bandwidth', ['period' => 'agu26', 'file' => $file])
            ->expectsOutputToContain('1 bandwidth berubah')->expectsOutputToContain('Pratinjau saja')->assertSuccessful();
        $this->assertSame('512 Kbps', BandwidthOption::find($lines['4700020-07944']->fresh()->service_snapshot['bandwidth_option_id'])->bandwidth_name);
        $this->assertSame(1, $period->fresh()->input_version);

        $this->artisan('billing:fix-bandwidth', ['period' => 'AGU26', 'file' => $file, '--apply' => true])->assertSuccessful();
        $line = $lines['4700020-07944']->fresh();
        $this->assertSame($bw128->id, $line->service_snapshot['bandwidth_option_id']);
        $this->assertSame($bw128->id, $line->existing_bandwidth_option_id);
        $this->assertSame($bw128->id, $line->bankJatimLink->current_bandwidth_option_id);
        $this->assertSame($bw128->id, $lines['790038103']->fresh()->bankJatimLink->current_bandwidth_option_id, 'An empty master bandwidth is filled in.');
        $this->assertSame(2, $period->fresh()->input_version);
        $this->assertDatabaseHas('billing_audit_events', ['action' => 'bandwidth_corrected_from_list', 'invoice_period_id' => $period->id]);

        // Running it again finds nothing left to change.
        $this->artisan('billing:fix-bandwidth', ['period' => 'AGU26', 'file' => $file, '--apply' => true])->expectsOutputToContain('0 bandwidth berubah')->assertSuccessful();
        $this->assertSame(2, $period->fresh()->input_version);
    }

    public function test_reads_excel_and_maps_zero_to_not_applicable(): void
    {
        ['lines' => $lines, 'na' => $na] = $this->fixture();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['SSID', 'Bandwidth'], [null, '0']]);
        $sheet->setCellValueExplicit('A2', '790038103', DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'bw').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->artisan('billing:fix-bandwidth', ['period' => 'AGU26', 'file' => $path, '--apply' => true])->assertSuccessful();
        $this->assertSame($na->id, $lines['790038103']->fresh()->service_snapshot['bandwidth_option_id']);
    }

    public function test_any_problem_in_the_file_stops_without_saving(): void
    {
        ['period' => $period, 'lines' => $lines, 'bw512' => $bw512] = $this->fixture();
        $file = $this->csv("4700020-07944;128 Kbps\n4700020-07944;512 Kbps\n999;128 Kbps\n790038103;3 Mbps\n");

        $this->artisan('billing:fix-bandwidth', ['period' => 'AGU26', 'file' => $file, '--apply' => true])
            ->expectsOutputToContain('muncul lebih dari sekali')->expectsOutputToContain('tidak ada di periode')->expectsOutputToContain('"3 Mbps" tidak ada di Master')
            ->assertFailed();
        $this->assertSame($bw512->id, $lines['4700020-07944']->fresh()->existing_bandwidth_option_id);
        $this->assertSame(1, $period->fresh()->input_version);

        $period->update(['is_locked' => true]);
        $this->artisan('billing:fix-bandwidth', ['period' => 'AGU26', 'file' => $this->csv("4700020-07944,128 Kbps\n"), '--apply' => true])
            ->expectsOutputToContain('sudah terkunci')->assertFailed();
    }
}
