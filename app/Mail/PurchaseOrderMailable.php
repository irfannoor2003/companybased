<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Support\DocumentData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sends a purchase order to a supplier with PDF attached.
 */
class PurchaseOrderMailable extends CompanyMailable implements ShouldQueue
{
    public function __construct(
        public PurchaseOrder $order,
        public ?string $customMessage = null,
    ) {
        $this->useAccountsIdentity();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Purchase Order '.$this->order->number.' from '.company_name(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.purchase-order',
            with: array_merge($this->brandData(), [
                'order' => $this->order,
                'supplier' => $this->order->supplier,
                'customMessage' => $this->customMessage,
            ]),
        );
    }

    public function attachments(): array
    {
        $this->order->loadMissing(['supplier', 'items.product']);

        $html = view('documents.pdf', DocumentData::build($this->order))->render();
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();

        return [
            Attachment::fromData(fn () => $pdf, 'purchase-order-'.$this->order->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
