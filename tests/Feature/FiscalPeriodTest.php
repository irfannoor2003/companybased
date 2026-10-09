<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalPeriodClosed;
use App\Services\Accounting\FiscalPeriodConflict;
use App\Services\Accounting\FiscalPeriodLocked;
use App\Services\Accounting\FiscalPeriodService;
use App\Support\GeneralLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Period close, and the gate it puts on posting.
 *
 * The behaviour worth protecting is the set of refusals. A ledger that silently
 * posts into a closed period is worse than one that refuses: the error shows up
 * later as a report that does not tie back.
 */
class FiscalPeriodTest extends TestCase
{
    use SeedsDatabase;

    private function service(): FiscalPeriodService
    {
        return app(FiscalPeriodService::class);
    }

    private function january(): FiscalPeriod
    {
        return $this->service()->create([
            'name' => 'January 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);
    }

    private function journal(string $date, float $debit = 100.0): JournalEntry
    {
        $entry = JournalEntry::create([
            'number' => 'JE-'.uniqid(),
            'entry_date' => $date,
            'status' => 'draft',
            'description' => 'Test entry',
        ]);

        $debitAccount = Account::where('code', '1000')->firstOrFail();
        $creditAccount = Account::where('code', '2000')->firstOrFail();

        GeneralLedger::replaceLines($entry, [
            ['account_id' => $debitAccount->id, 'debit' => $debit, 'credit' => 0],
            ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $debit],
        ]);

        return $entry->fresh();
    }

    public function test_a_period_can_be_created(): void
    {
        $period = $this->january();

        $this->assertSame('January 2026', $period->name);
        $this->assertSame('open', $period->status);
        $this->assertTrue($period->isOpen());
        $this->assertTrue($period->contains('2026-01-15'));
        $this->assertFalse($period->contains('2026-02-01'), 'the period must not bleed into February');
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create([
            'start_date' => '2026-03-31',
            'end_date' => '2026-03-01',
        ]);
    }

    public function test_overlapping_periods_are_refused(): void
    {
        $this->january();

        $this->expectException(FiscalPeriodConflict::class);

        // Shares 15 January with January.
        $this->service()->create([
            'start_date' => '2026-01-15',
            'end_date' => '2026-02-28',
        ]);
    }

    public function test_consecutive_periods_do_not_overlap(): void
    {
        $this->january();

        $february = $this->service()->create([
            'name' => 'February 2026',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
        ]);

        $this->assertSame('February 2026', $february->name);
    }

    public function test_a_date_resolves_to_the_period_containing_it(): void
    {
        $january = $this->january();

        $this->assertSame($january->id, FiscalPeriod::forDate('2026-01-20')?->id);
        $this->assertNull(FiscalPeriod::forDate('2026-06-01'), 'a date with no period resolves to none');
    }

    /**
     * A date outside every defined period is allowed through. Requiring a period
     * to exist first would make installing the ledger a prerequisite for trading.
     */
    public function test_a_date_with_no_period_is_still_postable(): void
    {
        $entry = $this->journal('2026-06-15');

        GeneralLedger::post($entry);

        $this->assertSame('posted', $entry->fresh()->status);
    }

    public function test_a_closed_period_refuses_new_entries(): void
    {
        $period = $this->january();
        $this->service()->close($period);

        $entry = $this->journal('2026-01-10');

        $this->expectException(FiscalPeriodClosed::class);
        GeneralLedger::post($entry);
    }

    public function test_a_locked_period_refuses_new_entries(): void
    {
        $period = $this->january();
        $this->service()->lock($period);

        $entry = $this->journal('2026-01-10');

        $this->expectException(FiscalPeriodClosed::class);
        GeneralLedger::post($entry);
    }

    /**
     * Closing sets a date_lock at the period start, so nothing inside a closed
     * period can be added.
     */
    public function test_the_closure_reason_names_the_period(): void
    {
        $period = $this->january();
        $this->service()->close($period);

        $refusal = $period->fresh()->postingRefusal('2026-01-10');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('January 2026', $refusal);
    }

    /**
     * A period may stay open for new work while refusing backdated entries. This
     * is the case that a single status field cannot express.
     */
    public function test_a_date_lock_blocks_backdating_without_closing_the_period(): void
    {
        $period = $this->january();
        $period->update(['date_lock' => '2026-01-20']);

        $this->assertTrue($period->isOpen(), 'the period still accepts current work');

        $this->assertNotNull(
            $period->postingRefusal('2026-01-10'),
            'a backdated entry before the lock date is refused',
        );

        $this->assertNull(
            $period->postingRefusal('2026-01-25'),
            'an entry after the lock date is allowed',
        );
    }

    public function test_a_closed_period_can_be_reopened(): void
    {
        $period = $this->january();
        $this->service()->close($period);

        $reopened = $this->service()->reopen($period->fresh());

        $this->assertSame('open', $reopened->status);
        $this->assertNull($reopened->closed_at);

        // And it accepts entries again.
        GeneralLedger::post($this->journal('2026-01-10'));
    }

    public function test_a_locked_period_cannot_be_reopened(): void
    {
        $period = $this->january();
        $this->service()->lock($period);

        $this->expectException(FiscalPeriodLocked::class);
        $this->service()->reopen($period->fresh());
    }

    public function test_a_locked_period_cannot_be_closed_again(): void
    {
        $period = $this->january();
        $this->service()->lock($period);

        $this->expectException(FiscalPeriodLocked::class);
        $this->service()->close($period->fresh());
    }

    /**
     * Locking while drafts remain would strand them: never reported, never
     * postable.
     */
    public function test_a_period_holding_drafts_cannot_be_locked(): void
    {
        $period = $this->january();
        $this->journal('2026-01-10');

        $this->expectException(FiscalPeriodConflict::class);
        $this->service()->lock($period);
    }

    public function test_a_period_can_be_locked_once_its_drafts_are_cleared(): void
    {
        $period = $this->january();
        $entry = $this->journal('2026-01-10');
        GeneralLedger::post($entry);

        $locked = $this->service()->lock($period->fresh());

        $this->assertSame('locked', $locked->status);
        $this->assertNotNull($locked->closed_at);
    }

    public function test_posting_stamps_the_period_onto_the_entry(): void
    {
        $period = $this->january();
        $entry = $this->journal('2026-01-10');

        $this->assertNull($entry->fiscal_period_id, 'a draft belongs to no period yet');

        GeneralLedger::post($entry);

        $this->assertSame($period->id, $entry->fresh()->fiscal_period_id);
    }

    /**
     * The whole point of the gate: existing documents keep working, and the
     * refusal arrives only where the period actually applies.
     */
    public function test_period_close_does_not_break_posting_outside_it(): void
    {
        $this->service()->close($this->january());

        GeneralLedger::post($this->journal('2026-02-10'));

        $this->assertTrue(true, 'posting outside a closed period is unaffected');
    }

    public function test_months_can_be_generated_consecutively(): void
    {
        $periods = $this->service()->generateMonths(
            \Illuminate\Support\Carbon::parse('2026-01-31'),
            3,
        );

        $this->assertCount(3, $periods);
        $this->assertSame('February 2026', $periods[0]->name);
        $this->assertSame('2026-02-01', $periods[0]->start_date->toDateString());
        $this->assertSame('2026-02-28', $periods[0]->end_date->toDateString(), 'February 2026 has 28 days');
        $this->assertSame('April 2026', $periods[2]->name);

        // No overlaps were created.
        $this->assertSame(3, FiscalPeriod::count());
    }

    public function test_generating_months_over_an_existing_period_is_refused(): void
    {
        // A period covering February, so generating February collides.
        $this->service()->create([
            'name' => 'February 2026',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
        ]);

        $this->expectException(FiscalPeriodConflict::class);
        $this->service()->generateMonths(\Illuminate\Support\Carbon::parse('2026-01-15'), 1);
    }

    /**
     * generateMonths starts at the month *after* $from, so passing the end of an
     * existing period is the normal way to extend the calendar.
     */
    public function test_generating_months_continues_from_the_end_of_the_calendar(): void
    {
        $this->january();

        $periods = $this->service()->generateMonths(\Illuminate\Support\Carbon::parse('2026-01-31'), 2);

        $this->assertSame('February 2026', $periods[0]->name);
        $this->assertSame('March 2026', $periods[1]->name);
    }

    public function test_a_period_refuses_to_contain_a_date_it_does_not_cover(): void
    {
        $period = $this->january();

        $this->assertNull($period->postingRefusal('2026-03-01'));
    }
}
