<?php

namespace Tests\Feature;

use App\Models\InvoicePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function period(int $year, int $month, array $extra = []): InvoicePeriod
    {
        return InvoicePeriod::create(['month' => $month, 'year' => $year, 'created_by' => auth()->id()] + $extra);
    }

    private function number(InvoicePeriod $period): string
    {
        return $period->refresh()->invoice_metadata['invoice_number'];
    }

    public function test_a_new_period_continues_from_the_previous_invoice_number(): void
    {
        $this->actingAs(User::factory()->create());
        $this->period(2026, 8, ['invoice_metadata' => ['invoice_number' => '4807060000210-202608']]);

        $this->assertSame('4807060000211-202609', $this->number($this->period(2026, 9)));
        $this->assertSame('4807060000212-202610', $this->number($this->period(2026, 10)));
    }

    public function test_a_skipped_month_still_gets_the_matching_number(): void
    {
        $this->actingAs(User::factory()->create());
        $this->period(2026, 9, ['invoice_metadata' => ['invoice_number' => '4807060000211-202609']]);

        $this->assertSame('4807060000213-202611', $this->number($this->period(2026, 11)));
        // The gap also counts across a year boundary and keeps the digit width.
        $this->assertSame('4807060000215-202701', $this->number($this->period(2027, 1)));
    }

    public function test_the_number_follows_the_latest_earlier_period_not_a_later_one(): void
    {
        $this->actingAs(User::factory()->create());
        $this->period(2026, 8, ['invoice_metadata' => ['invoice_number' => '4807060000210-202608']]);
        $this->period(2026, 12, ['invoice_metadata' => ['invoice_number' => '4807060000214-202612']]);
        // A hand-typed number that does not fit the pattern is skipped.
        $this->period(2026, 9, ['invoice_metadata' => ['invoice_number' => 'INV-2026-09']]);

        $this->assertSame('4807060000212-202610', $this->number($this->period(2026, 10)));
    }

    public function test_leading_zeros_are_kept(): void
    {
        $this->actingAs(User::factory()->create());
        $this->period(2026, 8, ['invoice_metadata' => ['invoice_number' => '000999-202608']]);

        $this->assertSame('001000-202609', $this->number($this->period(2026, 9)));
    }

    public function test_without_an_earlier_period_it_falls_back_to_the_profile_prefix(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertSame('4807060000210-202608', $this->number($this->period(2026, 8)));

        // A later period alone does not count as earlier.
        InvoicePeriod::query()->delete();
        $this->period(2026, 12, ['invoice_metadata' => ['invoice_number' => '4807060000214-202612']]);
        $this->assertSame('4807060000210-202607', $this->number($this->period(2026, 7)));
    }

    public function test_an_explicit_invoice_metadata_is_kept(): void
    {
        $this->actingAs(User::factory()->create());
        $this->period(2026, 8, ['invoice_metadata' => ['invoice_number' => '4807060000210-202608']]);

        $custom = $this->period(2026, 9, ['invoice_metadata' => ['invoice_number' => 'MANUAL-1']]);
        $this->assertSame('MANUAL-1', $this->number($custom));
    }
}
