<?php

namespace Database\Seeders;

use App\Models\PosPaymentMethod;
use App\Models\PosReconciliation;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PosSeeder extends Seeder
{
    public function run(): void
    {
        if (PosPaymentMethod::query()->count() > 0) {
            return;
        }

        $user = User::query()->orderBy('id')->first();

        $cash = PosPaymentMethod::create(['code' => 'CASH', 'name' => 'Cash', 'is_cash' => true, 'is_active' => true, 'notes' => 'Physical cash at the till.']);
        $momo = PosPaymentMethod::create(['code' => 'MTN_MOMO', 'name' => 'MTN Mobile Money', 'is_cash' => false, 'is_active' => true, 'notes' => 'Mobile money transfer.']);
        $card = PosPaymentMethod::create(['code' => 'CARD', 'name' => 'Card', 'is_cash' => false, 'is_active' => true, 'notes' => 'Debit / credit card.']);

        $products = Product::query()->where('is_active', true)->limit(8)->get();

        // Two closed shifts with sales, plus a reconciliation on the first.
        $this->seedShift($user, $cash, $products, Carbon::now()->subDays(3)->startOfDay()->addHours(9), 'closed', true);
        $this->seedShift($user, $momo, $products, Carbon::now()->subDays(1)->startOfDay()->addHours(9), 'closed', false);

        // One open shift so the sale screen works immediately.
        PosShift::create([
            'shift_number' => next_document_number('pos_shift', 'SHF'),
            'opened_by' => $user->id,
            'opened_at' => Carbon::now()->subHours(2),
            'opening_cash' => 500,
            'status' => 'open',
            'notes' => 'Current till session.',
        ]);
    }

    private function seedShift(User $user, PosPaymentMethod $payment, $products, Carbon $openedAt, string $status, bool $reconcile): void
    {
        $openingCash = 500.0;

        $shift = PosShift::create([
            'shift_number' => next_document_number('pos_shift', 'SHF'),
            'opened_by' => $user->id,
            'opened_at' => $openedAt,
            'opening_cash' => $openingCash,
            'status' => $status,
            'notes' => 'Seeded till session.',
        ]);

        $salesTotal = 0.0;

        foreach (range(1, rand(3, 5)) as $n) {
            $items = [];
            $subtotal = 0.0;

            foreach ($products->shuffle()->take(rand(1, 3)) as $product) {
                $qty = rand(1, 3);
                $price = (float) $product->retail_price;
                $items[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'line_total' => round($qty * $price, 2),
                ];
                $subtotal += $qty * $price;
            }

            $tax = round($subtotal * 0.10, 2);
            $total = round($subtotal + $tax, 2);
            $salesTotal += $total;

            $sale = PosSale::create([
                'receipt_number' => next_document_number('pos_receipt', 'RC'),
                'shift_id' => $shift->id,
                'customer_name' => 'Walk-in customer',
                'subtotal' => round($subtotal, 2),
                'discount' => 0,
                'tax' => $tax,
                'total' => $total,
                'payment_method_id' => $payment->id,
                'amount_paid' => $total,
                'change_due' => 0,
                'status' => 'completed',
                'sold_at' => $openedAt->copy()->addMinutes(($n * 47) + 10),
                'notes' => 'Seeded sale.',
            ]);

            foreach ($items as $item) {
                PosSaleItem::create([
                    'pos_sale_id' => $sale->id,
                    'product_id' => $item['product']->id,
                    'item_name' => $item['product']->name,
                    'unit' => $item['product']->unit,
                    'quantity' => (string) $item['quantity'],
                    'unit_price' => (string) $item['unit_price'],
                    'line_total' => (string) $item['line_total'],
                ]);
            }
        }

        if ($status === 'closed') {
            $expected = round($openingCash + $salesTotal, 2);
            $counted = $reconcile ? $expected : round($expected - 12.5, 2);

            $shift->update([
                'status' => 'closed',
                'closed_by' => $user->id,
                'closed_at' => $openedAt->copy()->addHours(9),
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance' => round($counted - $expected, 2),
            ]);
        }

        if ($reconcile) {
            PosReconciliation::create([
                'shift_id' => $shift->id,
                'reconciled_by' => $user->id,
                'reconciled_at' => $shift->closed_at,
                'opening_cash' => $openingCash,
                'sales_total' => $salesTotal,
                'expected_cash' => round($openingCash + $salesTotal, 2),
                'counted_cash' => round($openingCash + $salesTotal, 2),
                'variance' => 0,
                'notes' => 'Till matched.',
            ]);
        }
    }
}
