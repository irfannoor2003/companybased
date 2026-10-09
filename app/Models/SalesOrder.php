<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\Money;

class SalesOrder extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'sales_orders';

    protected $fillable = [
        'number', 'number', 'reference_number', 'tracking_code', 'quote_id', 'customer_id', 'salesman_id', 'issue_date', 'expected_delivery_date',
        'status', 'currency', 'exchange_rate', 'subtotal', 'discount_amount', 'tax_amount', 'total',
        'shipping_address', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expected_delivery_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'quote_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'order_id');
    }

    /**
     * Statuses that mean an invoice no longer counts towards this order.
     *
     * `cancelled` rather than `void`: sales_invoices.status is an enum that has no
     * `void` member, so writing one silently truncated to an empty string -- which
     * matches nothing here and left a cancelled invoice still consuming its order
     * lines.
     *
     * @var array<int, string>
     */
    public const DEAD_INVOICE_STATUSES = ['void', 'cancelled'];

    /**
     * Order lines not yet covered by a live invoice, by product.
     *
     * This is what makes an order-invoice flow usable rather than merely possible.
     * Copying every line into the invoice -- which is what the create screen does
     * today -- produces an invoice for the whole order on first pass. That is fine
     * for a single-delivery order and wrong for one delivered in three, because
     * the second invoice then has nothing left to bill and the third is impossible.
     *
     * Matched by product rather than by line, so a partial delivery bills the
     * right goods even when the delivery note reordered or merged the lines.
     *
     * @return array<int, array{product_id: int, description: string, qty: float, unit_price: float, discount_percent: float, tax_percent: float}>
     */
    public function uninvoicedLines(): array
    {
        /** @var array<int, array{product_id: int, description: string, qty: float, unit_price: float, discount_percent: float, tax_percent: float}> $lines */
        $lines = [];

        foreach ($this->items as $item) {
            $invoicedQty = '0';

            foreach ($this->liveInvoices()->with('items')->get() as $invoice) {
                foreach ($invoice->items as $invoiced) {
                    // Only the same product counts. A loose item invoiced by name
                    // has no product, so it can never be matched and is skipped
                    // rather than silently consuming a line.
                    if ($invoiced->product_id && $invoiced->product_id === $item->product_id) {
                        $invoicedQty = Money::add($invoicedQty, $invoiced->qty);
                    }
                }
            }

            $remaining = Money::of(Money::subtract($item->qty, $invoicedQty));

            // Compared against zero, not against a 0.004 tolerance. Money has no
            // sub-cent precision, so a sub-cent threshold is indistinguishable
            // from zero at the scale every figure here is rounded to -- the guard
            // silently never fired and fully invoiced lines stayed on offer.
            if (! Money::isGreaterThan($remaining, '0')) {
                continue;
            }

            $lines[] = [
                'product_id' => $item->product_id,
                'description' => $item->description,
                'qty' => (float) $remaining,
                'unit_price' => (float) $item->unit_price,
                'discount_percent' => (float) $item->discount_percent,
                'tax_percent' => (float) $item->tax_percent,
            ];
        }

        return $lines;
    }

    /**
     * Invoices raised against this order that are still live.
     *
     * Voided and deleted invoices are excluded deliberately: an order is invoiced
     * "as far as it has gone", and counting a cancelled invoice against it would
     * make a fully delivered order look part-billed forever.
     */
    public function liveInvoices(): HasMany
    {
        return $this->invoices()
            ->whereNotIn('status', self::DEAD_INVOICE_STATUSES)
            ->whereNull('deleted_at');
    }

    /**
     * What is still owed to invoice on this order.
     *
     * Total less everything already invoiced. This is the figure that decides
     * whether another invoice may be raised, so it must exclude voided ones --
     * otherwise an order that was invoiced, voided, and re-invoiced would refuse
     * the second attempt because the voided one still counted.
     */
    public function uninvoicedTotal(): string
    {
        $invoiced = '0.00';

        foreach ($this->liveInvoices()->get() as $invoice) {
            $invoiced = Money::add($invoiced, $invoice->total);
        }

        return Money::of(Money::subtract($this->total, $invoiced));
    }

    /**
     * Whether a further invoice would exceed what the order is worth.
     *
     * Guards the one mistake that quietly corrupts a sales ledger: invoicing a
     * customer for more than they agreed to buy.
     */
    public function isFullyInvoiced(): bool
    {
        return ! Money::isGreaterThan($this->uninvoicedTotal(), '0');
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(SalesDeliveryNote::class, 'order_id');
    }

    public function statusEvents(): MorphMany
    {
        return $this->morphMany(SalesStatusEvent::class, 'trackable');
    }

    public static function statusOptions(): array
    {
        return ['draft', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled'];
    }

    public function isConfirmed(): bool
    {
        return ! in_array($this->status, ['draft', 'cancelled']);
    }
}
