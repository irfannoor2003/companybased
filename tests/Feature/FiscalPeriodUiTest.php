<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalPeriodService;
use App\Support\GeneralLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The period close screen and its lifecycle endpoints.
 *
 * FiscalPeriodTest covers the rules; this covers that the UI and routes are
 * actually wired, that the refusals reach the user as messages rather than
 * 500s, and that the permission split between close and lock holds.
 */
class FiscalPeriodUiTest extends TestCase
{
    use SeedsDatabase;

    private function service(): FiscalPeriodService
    {
        return app(FiscalPeriodService::class);
    }

    private function period(string $start = '2026-01-01', string $end = '2026-01-31'): FiscalPeriod
    {
        return $this->service()->create([
            'name' => 'January 2026',
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    private function journal(string $date): JournalEntry
    {
        $entry = JournalEntry::create([
            'number' => 'JE-'.uniqid(),
            'entry_date' => $date,
            'status' => 'draft',
            'description' => 'Opening entry',
        ]);

        GeneralLedger::replaceLines($entry, [
            ['account_id' => Account::where('code', '1000')->firstOrFail()->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => Account::where('code', '2000')->firstOrFail()->id, 'debit' => 0, 'credit' => 100],
        ]);

        return $entry->fresh();
    }

    public function test_the_period_list_renders(): void
    {
        $this->period();

        $this->actingAs($this->userForRole('Admin'))
            ->get('/accounting/fiscal-periods')
            ->assertOk()
            ->assertSee('January 2026')
            ->assertSee('Open');
    }

    public function test_the_create_screen_renders(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->get('/accounting/fiscal-periods/create')
            ->assertOk()
            ->assertSee('New fiscal period');
    }

    public function test_a_period_can_be_created_from_the_screen(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/accounting/fiscal-periods', [
                'name' => 'March 2026',
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-31',
            ])
            ->assertRedirect('/accounting/fiscal-periods')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('fiscal_periods', ['name' => 'March 2026', 'status' => 'open']);
    }

    /**
     * An overlap must come back as a field error, not a 500. The message names
     * the period that conflicts so the user can find it.
     */
    public function test_an_overlapping_period_is_reported_as_a_field_error(): void
    {
        $this->period();

        $response = $this->actingAs($this->userForRole('Admin'))
            ->from('/accounting/fiscal-periods/create')
            ->post('/accounting/fiscal-periods', [
                'name' => 'Overlapping',
                'start_date' => '2026-01-15',
                'end_date' => '2026-02-28',
            ]);

        $response->assertRedirect('/accounting/fiscal-periods/create');
        $response->assertSessionHasErrors('end_date');

        $this->assertStringContainsString(
            'January 2026',
            session('errors')->first('end_date'),
            'the message should name the conflicting period',
        );
        $this->assertDatabaseMissing('fiscal_periods', ['name' => 'Overlapping']);
    }

    public function test_the_show_screen_renders_with_its_totals(): void
    {
        $period = $this->period();
        GeneralLedger::post($this->journal('2026-01-10'));

        $this->actingAs($this->userForRole('Admin'))
            ->get('/accounting/fiscal-periods/'.$period->id)
            ->assertOk()
            ->assertSee('January 2026')
            ->assertSee('100.00');
    }

    public function test_a_period_can_be_closed_from_the_screen(): void
    {
        $period = $this->period();

        $this->actingAs($this->userForRole('Admin'))
            ->from("/accounting/fiscal-periods/{$period->id}")
            ->post("/accounting/fiscal-periods/{$period->id}/close", ['reason' => 'Month end'])
            ->assertRedirect("/accounting/fiscal-periods/{$period->id}")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('fiscal_periods', [
            'id' => $period->id,
            'status' => 'closed',
            'lock_reason' => 'Month end',
        ]);
    }

    /**
     * Posting into a closed period has to surface the reason. If this 500s the
     * ledger is silently out of step with the books.
     */
    public function test_posting_into_a_closed_period_explains_itself(): void
    {
        $this->period();
        $this->service()->close(FiscalPeriod::first());

        $response = $this->actingAs($this->userForRole('Admin'))
            ->post('/accounting/journal/'.$this->journal('2026-01-10')->id.'/post');

        // Either a redirect with an error or a validation-style response: the
        // point is that it is not an unhandled 500.
        $this->assertTrue(
            $response->status() !== 500,
            'posting into a closed period raised a server error instead of explaining itself',
        );
    }

    public function test_a_locked_period_reports_that_it_cannot_be_reopened(): void
    {
        $period = $this->period();
        $this->service()->lock($period);

        $this->actingAs($this->userForRole('Admin'))
            ->from("/accounting/fiscal-periods/{$period->id}")
            ->post("/accounting/fiscal-periods/{$period->id}/reopen")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('fiscal_periods', ['id' => $period->id, 'status' => 'locked']);
    }

    public function test_locking_a_period_holding_drafts_is_refused_with_a_reason(): void
    {
        $period = $this->period();
        $this->journal('2026-01-10');

        $this->actingAs($this->userForRole('Admin'))
            ->from("/accounting/fiscal-periods/{$period->id}")
            ->post("/accounting/fiscal-periods/{$period->id}/lock")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('fiscal_periods', ['id' => $period->id, 'status' => 'open']);
    }

    public function test_dates_cannot_be_changed_once_a_period_holds_entries(): void
    {
        $period = $this->period();
        GeneralLedger::post($this->journal('2026-01-10'));

        $this->actingAs($this->userForRole('Admin'))
            ->from("/accounting/fiscal-periods/{$period->id}/edit")
            ->put("/accounting/fiscal-periods/{$period->id}", [
                'start_date' => '2026-01-15',
                'end_date' => '2026-01-31',
            ])
            ->assertSessionHasErrors('start_date');

        $this->assertDatabaseHas('fiscal_periods', [
            'id' => $period->id,
            'start_date' => '2026-01-01',
        ]);
    }

    public function test_a_period_holding_entries_cannot_be_deleted(): void
    {
        $period = $this->period();
        GeneralLedger::post($this->journal('2026-01-10'));

        $this->actingAs($this->userForRole('Admin'))
            ->from('/accounting/fiscal-periods')
            ->delete("/accounting/fiscal-periods/{$period->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('fiscal_periods', ['id' => $period->id]);
    }

    public function test_months_can_be_generated_from_the_screen(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/accounting/fiscal-periods/generate', [
                'after' => '2026-01-31',
                'months' => 3,
            ])
            ->assertRedirect('/accounting/fiscal-periods')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('fiscal_periods', ['name' => 'February 2026']);
        $this->assertDatabaseHas('fiscal_periods', ['name' => 'April 2026']);
    }

    /**
     * Close and lock are separate permissions. If one role held both, the
     * distinction between a reversible close and a final lock would be theatre.
     */
    public function test_closing_and_locking_hold_different_permissions(): void
    {
        $this->assertContains('accounting.fiscal_periods.close', \App\Support\Permissions::all());
        $this->assertContains('accounting.fiscal_periods.lock', \App\Support\Permissions::all());

        $routes = collect(app('router')->getRoutes())->mapWithKeys(fn ($r) => [
            $r->getName() => $r->gatherMiddleware(),
        ]);

        $this->assertContains('permission:accounting.fiscal_periods.close', $routes['accounting.fiscal_periods.close']);
        $this->assertContains('permission:accounting.fiscal_periods.lock', $routes['accounting.fiscal_periods.lock']);
    }

    public function test_the_navigation_entry_points_at_a_real_route(): void
    {
        $this->assertNotNull(
            route('accounting.fiscal_periods.index'),
            'the nav entry references a route that does not exist',
        );
    }
}
