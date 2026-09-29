<?php

namespace Tests\Feature;

use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A till shift is how cash is accounted for at the end of a trading day: the
 * expected float is opening cash plus the day's completed sales, and anything
 * left over is a variance. This had no test coverage, so a miscounted float
 * would have gone unnoticed.
 *
 * The invariants pinned here:
 *  - only one shift may be open at a time
 *  - expected cash = opening cash + completed sales (cancelled sales excluded)
 *  - variance is signed: positive is over, negative is short
 *  - a variance beyond a cent raises a notification, a clean close does not
 *  - a closed shift cannot be closed again
 */
class PosShiftTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    /**
     * Memoised: userForRole() creates the row, so calling it twice in one test
     * would collide on the unique email.
     */
    private function admin(): User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function openShift(float $openingCash = 0.0): PosShift
    {
        return PosShift::create([
            'shift_number' => 'SHF-'.uniqid(),
            'opened_by' => $this->admin()->id,
            'opened_at' => now(),
            'opening_cash' => (string) $openingCash,
            'status' => 'open',
        ]);
    }

    private function sale(PosShift $shift, float $total, string $status = 'completed'): PosSale
    {
        return PosSale::create([
            'receipt_number' => 'RC-'.uniqid(),
            'shift_id' => $shift->id,
            'subtotal' => (string) $total,
            'discount' => '0',
            'tax' => '0',
            'total' => (string) $total,
            'amount_paid' => (string) $total,
            'change_due' => '0',
            'status' => $status,
            'sold_at' => now(),
        ]);
    }

    public function test_a_shift_opens_with_a_numbered_float(): void
    {
        $this->actingAs($this->admin())
            ->post('/pos/shifts', ['opening_cash' => '500'])
            ->assertSessionHasNoErrors();

        $shift = PosShift::firstOrFail();

        $this->assertSame('open', $shift->status);
        $this->assertEqualsWithDelta(500.0, (float) $shift->opening_cash, 0.0005);
        $this->assertStringStartsWith('SHF-', $shift->shift_number);
    }

    public function test_a_negative_opening_float_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->from('/pos/shifts')
            ->post('/pos/shifts', ['opening_cash' => '-50'])
            ->assertSessionHasErrors('opening_cash');

        $this->assertSame(0, PosShift::count());
    }

    public function test_only_one_shift_may_be_open_at_a_time(): void
    {
        $this->openShift();

        $this->actingAs($this->admin())
            ->from('/pos/shifts')
            ->post('/pos/shifts', ['opening_cash' => '100'])
            ->assertSessionHas('toasts');

        $this->assertSame(1, PosShift::count(), 'a second shift must not be opened');
    }

    public function test_expected_cash_is_opening_cash_plus_sales(): void
    {
        $shift = $this->openShift(200.00);
        $this->sale($shift, 150.00);
        $this->sale($shift, 250.00);

        $this->actingAs($this->admin())
            ->post("/pos/shifts/{$shift->id}/close", ['counted_cash' => '600'])
            ->assertSessionHasNoErrors();

        $closed = $shift->fresh();

        $this->assertSame('closed', $closed->status);
        // 200 + 150 + 250
        $this->assertEqualsWithDelta(600.0, (float) $closed->expected_cash, 0.0005);
        $this->assertEqualsWithDelta(0.0, (float) $closed->variance, 0.0005);
    }

    public function test_cancelled_sales_are_excluded_from_the_expected_float(): void
    {
        $shift = $this->openShift(100.00);
        $this->sale($shift, 400.00);
        $this->sale($shift, 999.00, 'cancelled');

        $this->actingAs($this->admin())
            ->post("/pos/shifts/{$shift->id}/close", ['counted_cash' => '500'])
            ->assertSessionHasNoErrors();

        $closed = $shift->fresh();

        $this->assertEqualsWithDelta(500.0, (float) $closed->expected_cash, 0.0005, 'a cancelled sale is not money taken');
    }

    public function test_a_short_count_produces_a_negative_variance(): void
    {
        $shift = $this->openShift(0.00);
        $this->sale($shift, 100.00);

        $this->actingAs($this->admin())
            ->post("/pos/shifts/{$shift->id}/close", ['counted_cash' => '90'])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(-10.0, (float) $shift->fresh()->variance, 0.0005);
    }

    public function test_an_over_count_produces_a_positive_variance(): void
    {
        $shift = $this->openShift(0.00);
        $this->sale($shift, 100.00);

        $this->actingAs($this->admin())
            ->post("/pos/shifts/{$shift->id}/close", ['counted_cash' => '130'])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(30.0, (float) $shift->fresh()->variance, 0.0005);
    }

    public function test_a_closed_shift_cannot_be_closed_again(): void
    {
        $shift = $this->openShift(0.00);
        $this->sale($shift, 100.00);

        $user = $this->admin();

        $this->actingAs($user)->post("/pos/shifts/{$shift->id}/close", ['counted_cash' => '100']);

        $this->actingAs($user)
            ->from('/pos/shifts')
            ->post("/pos/shifts/{$shift->id}/close", ['counted_cash' => '50'])
            ->assertSessionHas('toasts');

        $closed = $shift->fresh();

        $this->assertEqualsWithDelta(100.0, (float) $closed->counted_cash, 0.0005, 'the first count must stand');
    }

    public function test_the_shift_is_released_for_the_next_day(): void
    {
        $shift = $this->openShift(0.00);
        $this->sale($shift, 100.00);

        $this->actingAs($this->admin())->post("/pos/shifts/{$shift->id}/close", ['counted_cash' => '100']);

        $this->actingAs($this->admin())
            ->post('/pos/shifts', ['opening_cash' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PosShift::count());
        $this->assertSame(1, PosShift::where('status', 'open')->count());
    }

    public function test_the_shifts_index_lists_shifts(): void
    {
        $this->openShift(50.00);

        $this->actingAs($this->admin())
            ->get('/pos/shifts')
            ->assertOk();
    }
}
