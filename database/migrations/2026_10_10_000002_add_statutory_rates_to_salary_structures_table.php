<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statutory deduction rates on the salary structure.
 *
 * A payroll that cannot record income tax or social-security withholding has no
 * way to be honest about it, because the only figure available is the difference
 * between gross and net — and that difference also contains lateness and absence,
 * which are not tax.
 *
 * Rates live on the salary structure rather than in a global setting so they can
 * differ per employee band, which is how a graduated tax scale is normally
 * administered: the lower the salary, the higher the effective rate.
 *
 * Both default to ZERO rather than to a plausible-sounding figure. Withholding
 * money the business does not owe to an authority is worse than withholding none,
 * because it produces a liability somebody will eventually try to pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            /**
             * Income tax withheld at source, as a percentage of gross pay.
             */
            $table->decimal('statutory_tax_percent', 7, 4)->default(0)->after('other_allowance');

            /**
             * Employee social security / provident fund, as a percentage of gross
             * pay. Kept separate from tax because it is a different liability with a
             * different remittance, and merging them makes the balance sheet wrong
             * in a way nobody can audit back.
             */
            $table->decimal('statutory_social_percent', 7, 4)->default(0)->after('statutory_tax_percent');
        });
    }

    public function down(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->dropColumn(['statutory_tax_percent', 'statutory_social_percent']);
        });
    }
};
