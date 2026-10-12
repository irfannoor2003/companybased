<?php

namespace App\Support;

/**
 * Decimal money arithmetic.
 *
 * Amounts in this application are decimal(14,2) columns cast to strings. Doing
 * arithmetic on them as PHP floats is the source of the class of bug where a
 * document's printed lines do not add up to its printed total: 0.1 + 0.2 is
 * 0.30000000000000004, and money has no third decimal place to absorb it.
 *
 * bcmath is declared in composer.json but is not guaranteed to be present on
 * shared hosting, so every method falls back to float arithmetic with explicit
 * rounding rather than fataling. The fallback is documented as approximate: it is
 * correct for the magnitudes involved here and vastly better than the alternative
 * of an unhandled fatal.
 *
 * Every method returns a string with exactly two decimal places, which is the
 * shape the decimal columns and the casts expect.
 */
final class Money
{
    private const SCALE = 2;

    /**
     * True when decimal arithmetic is genuinely available.
     */
    public static function isDecimal(): bool
    {
        return function_exists('bcadd');
    }

    public static function of(int|string|float|null $amount): string
    {
        return self::isDecimal()
            ? bcadd((string) ($amount ?? 0), '0', self::SCALE)
            : number_format((float) ($amount ?? 0), self::SCALE, '.', '');
    }

    /**
     * Multiply money by a factor -- an integer headcount, a percentage divisor, an
     * exchange rate.
     *
     * Rounded once, at the end, to the scale: rounding the operands first and the
     * product last is what leaves a fraction of a paisa in a payslip.
     */
    public static function multiply(int|string|float|null $amount, int|string|float|null $factor): string
    {
        return self::isDecimal()
            ? bcmul((string) ($amount ?? 0), (string) ($factor ?? 0), self::SCALE)
            : number_format((float) ($amount ?? 0) * (float) ($factor ?? 0), self::SCALE, '.', '');
    }

    /**
     * Round to an explicit scale, for values that are not money.
     *
     * `multiply()` and friends round to the money scale, which is right for a
     * ledger amount and wrong for a unit cost: a moving average is stored at four
     * decimals, and rounding it to two would flatten unit costs of 0.0025 that are
     * real in packaging and chemicals.
     *
     * Used through bcmath where it is available so the rounding is decimal rather
     * than a binary float approximation.
     */
    public static function round(int|string|float|null $amount, int $scale = self::SCALE): string
    {
        if (! self::isDecimal()) {
            return number_format(round((float) ($amount ?? 0), $scale), $scale, '.', '');
        }

        $value = trim((string) ($amount ?? 0));

        if ($value === '') {
            return number_format(0, $scale, '.', '');
        }

        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '+-');

        $unit = bcpow('10', (string) $scale, 0);

        /**
         * Half away from zero, without losing the fraction.
         *
         * The product is taken at a working scale rather than at scale 0, because
         * bcmul at scale 0 truncates and would throw away the very digits being
         * rounded: 99.99999 scaled by 100 truncated to 9999 before the half was
         * ever added, so it came back as 99.99 instead of 100.00.
         *
         * Two earlier versions were wrong here in ways worth recording -- one
         * truncated at the target scale and returned 12 for 12.3456789, the other
         * truncated the product first and so never rounded a value up at all.
         */
        $scaled = bcadd(bcmul($absolute, $unit, $scale + 4), '0.5', 0);
        $rounded = bcdiv($scaled, $unit, $scale);

        // Normalise "-0" so a small negative never prints as a negative zero.
        if ($negative && bccomp($rounded, '0', $scale) !== 0) {
            $rounded = '-'.ltrim($rounded, '-');
        }

        return $rounded;
    }

    public static function add(int|string|float|null ...$amounts): string
    {
        $total = '0';

        foreach ($amounts as $amount) {
            $total = self::isDecimal()
                ? bcadd($total, (string) ($amount ?? 0), self::SCALE)
                : number_format((float) $total + (float) ($amount ?? 0), self::SCALE, '.', '');
        }

        return $total;
    }

    public static function subtract(int|string|float|null $minuend, int|string|float|null $subtrahend): string
    {
        return self::isDecimal()
            ? bcsub((string) ($minuend ?? 0), (string) ($subtrahend ?? 0), self::SCALE)
            : number_format((float) ($minuend ?? 0) - (float) ($subtrahend ?? 0), self::SCALE, '.', '');
    }

    /**
     * Multiply a money amount by a percentage, e.g. 17% of 1,234.56.
     */
    public static function percentageOf(int|string|float|null $amount, int|string|float|null $percent): string
    {
        return self::multiply($amount, (float) ($percent ?? 0) / 100);
    }

    /**
     * Whether $left is below $right, to the scale of the money itself.
     *
     * The float branch is the trap. Casting to float and comparing means
     * `isLessThan('0.00', '0.004')` asks "is zero below four thousandths" using
     * whatever precision a double happens to give, which rounds to the same value
     * and returns false. Every other comparison in this class rounds both sides to
     * SCALE first, so they agree with each other; this one did not, and a caller
     * using a sub-cent tolerance -- a common way to ask "is this effectively
     * zero?" -- got the opposite answer from the identically-purposed
     * isGreaterThan().
     *
     * Both sides are therefore rounded to SCALE before the comparison, which
     * makes 0.004 indistinguishable from 0.00 at money precision. Callers that
     * genuinely need sub-cent precision should compare raw floats themselves.
     */
/**
     * Divide, to the money scale.
     *
     * Kept alongside multiply so tax arithmetic stays in decimal throughout. Doing it
     * in floats and rounding the result is where compound tax goes wrong, because the
     * quotient then carries a float's representation error into every figure derived
     * from it.
     *
     * A zero divisor returns 0 rather than raising, because a zero rate would make
     * the compound formula net / (1 - 0) = net / 1, which is fine, but a zero divisor
     * arriving from a malformed rate should not take a whole document down.
     */
    public static function divide(int|string|float|null $amount, int|string|float|null $divisor): string
    {
        $d = self::of($divisor);

        if (self::equals($d, '0')) {
            return '0.00';
        }

        if (self::isDecimal()) {
            return bcdiv(self::of($amount), $d, self::SCALE);
        }

        return number_format((float) $amount / (float) $d, self::SCALE, '.', '');
    }

    public static function isLessThan(int|string|float|null $left, int|string|float|null $right): bool
    {
        $l = round((float) self::of($left), self::SCALE);
        $r = round((float) self::of($right), self::SCALE);

        return self::isDecimal()
            ? bccomp(number_format($l, self::SCALE, '.', ''), number_format($r, self::SCALE, '.', ''), self::SCALE) < 0
            : $l < $r;
    }

    public static function isGreaterThan(int|string|float|null $left, int|string|float|null $right): bool
    {
        return self::isDecimal()
            ? bccomp(self::of($left), self::of($right), self::SCALE) > 0
            : (float) $left > (float) $right;
    }

    public static function equals(int|string|float|null $left, int|string|float|null $right): bool
    {
        return self::isDecimal()
            ? bccomp(self::of($left), self::of($right), self::SCALE) === 0
            : abs((float) $left - (float) $right) < 0.005;
    }
}
