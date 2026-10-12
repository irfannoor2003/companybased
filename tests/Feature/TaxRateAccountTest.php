<?php

namespace Tests\Feature;

use App\Services\Accounting\LedgerPosting;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A tax rate's `account_id` must name the account its tax actually posts to.
 *
 * The column looks authoritative and is not consulted anywhere. Posting has always
 * used the fixed OUTPUT_TAX_PAYABLE and INPUT_TAX_RECEIVABLE accounts, and no
 * document item table carries `tax_rate_id`, so a posted figure cannot be traced
 * back to the rate that produced it. Two consequences:
 *
 *  - A NULL account is not a blocker. Tax posts whether or not it is set, so an
 *    earlier claim that it blocked live tax filing was wrong.
 *
 *  - A MISMATCHED account is worse than a missing one. An owner who pointed a
 *    sales rate at the input-tax account would see it saved, and the tax would
 *    still post to output tax, with nothing reporting the disagreement.
 *
 * So the column is held to the truth rather than trusted: every active rate has to
 * agree with where its scope posts, and the command checks it on a real database.
 */
class TaxRateAccountTest extends TestCase
{
    use SeedsDatabase;

    private function accountId(string $code): int
    {
        return (int) \App\Models\Account::where('code', $code)->value('id');
    }

    private function makeRate(array $attributes = []): \App\Models\TaxRate
    {
        return \App\Models\TaxRate::create(array_merge([
            'code' => 'TEST-'.uniqid(),
            'name' => 'Test rate',
            'rate' => '15.0000',
            'applies_to' => 'sales',
            'account_id' => $this->accountId(LedgerPosting::OUTPUT_TAX_PAYABLE),
            'effective_from' => today()->toDateString(),
            'is_active' => true,
            'is_compound' => false,
            'is_exempt' => false,
            'priority' => 0,
        ], $attributes));
    }

    public function test_a_sales_rate_is_expected_to_name_the_output_tax_account(): void
    {
        $this->assertSame(
            LedgerPosting::OUTPUT_TAX_PAYABLE,
            LedgerPosting::taxAccountFor('sales'),
            'sales tax must post to output tax payable',
        );
    }

    public function test_a_purchase_rate_is_expected_to_name_the_input_tax_account(): void
    {
        $this->assertSame(
            LedgerPosting::INPUT_TAX_RECEIVABLE,
            LedgerPosting::taxAccountFor('purchase'),
            'purchase tax must post to input tax receivable',
        );
    }

    /**
     * The command is the enforcement point: it has to notice a rate that names the
     * wrong account, and fail rather than reporting success.
     */
    public function test_the_command_fails_on_a_rate_naming_the_wrong_account(): void
    {
        // A sales rate pointing at the input-tax account: exactly the silent
        // misconfiguration described above.
        $this->makeRate(['applies_to' => 'sales', 'account_id' => $this->accountId(LedgerPosting::INPUT_TAX_RECEIVABLE)]);

        $this->artisan('accounting:verify-tax-rates')->assertFailed();
    }

    public function test_the_command_fails_on_a_rate_with_no_account(): void
    {
        $this->makeRate(['account_id' => null]);

        $this->artisan('accounting:verify-tax-rates')->assertFailed();
    }

    public function test_the_command_passes_when_every_rate_agrees(): void
    {
        $this->makeRate(['applies_to' => 'sales', 'account_id' => $this->accountId(LedgerPosting::OUTPUT_TAX_PAYABLE)]);
        $this->makeRate(['applies_to' => 'purchase', 'account_id' => $this->accountId(LedgerPosting::INPUT_TAX_RECEIVABLE)]);

        $this->artisan('accounting:verify-tax-rates')->assertSuccessful();
    }

    /**
     * --fix corrects a mismatched rate and leaves a matching one alone.
     */
    public function test_the_fix_points_a_rate_at_the_account_its_scope_posts_to(): void
    {
        $wrong = $this->makeRate([
            'applies_to' => 'purchase',
            'account_id' => $this->accountId(LedgerPosting::OUTPUT_TAX_PAYABLE),
        ]);

        $this->artisan('accounting:verify-tax-rates --fix')->assertFailed();

        $this->assertSame(
            $this->accountId(LedgerPosting::INPUT_TAX_RECEIVABLE),
            (int) $wrong->refresh()->account_id,
            '--fix did not correct the account',
        );

        $this->artisan('accounting:verify-tax-rates')->assertSuccessful();
    }

    /**
     * Every active rate the adoption migration left behind must now be verifiable.
     *
     * The migration deliberately left `account_id` NULL rather than guessing, so a
     * deployment can start up with rates whose account is unconfirmed. That is the
     * state this command exists to report.
     */
    public function test_rates_adopted_without_an_account_are_reported_rather_than_assumed_correct(): void
    {
        $adopted = \App\Models\TaxRate::create([
            'code' => 'ADOPTED-SAL-17-00',
            'name' => 'Adopted sales rate 17%',
            'rate' => '17.0000',
            'applies_to' => 'sales',
            'account_id' => null,
            'effective_from' => '2024-01-01',
            'is_active' => true,
        ]);

        $this->artisan('accounting:verify-tax-rates')
            ->expectsOutputToContain('ADOPTED-SAL-17-00')
            ->assertFailed();

        $this->assertNull($adopted->account_id, 'the rate was silently corrected without being asked');
    }
}
