<?php

namespace App\Support;

use App\Models\DiscountRule;

trait DiscountLimit
{
    /**
     * Get the maximum discount percentage allowed for the current user
     * based on active discount rules that apply to their role.
     */
    public function getMaxDiscountForUser(): ?float
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $roles = $user->getRoleNames()->all();

        $rules = DiscountRule::active()
            ->where('type', 'percentage')
            ->get()
            ->filter(fn ($rule) => $rule->appliesToRole(...$roles));

        if ($rules->isEmpty()) {
            return null;
        }

        return $rules->min('max_value');
    }

    /**
     * Validate that all item discounts are within the allowed limit.
     * Returns true if valid, throws validation exception if not.
     */
    public function validateDiscountLimits(array $items): void
    {
        $maxDiscount = $this->getMaxDiscountForUser();
        if ($maxDiscount === null) {
            return;
        }

        foreach ($items as $index => $item) {
            $discount = (float) ($item['discount_percent'] ?? 0);
            if ($discount > $maxDiscount) {
                abort(response()->json([
                    'message' => "Discount on line " . ($index + 1) . " ({$discount}%) exceeds the maximum allowed ({$maxDiscount}%).",
                ], 422));
            }
        }
    }
}
