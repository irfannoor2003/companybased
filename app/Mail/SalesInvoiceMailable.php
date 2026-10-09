<?php

namespace App\Mail;

use App\Models\SalesInvoice;
use App\Support\InvoiceTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sends a sales invoice to a customer with PDF attached.
 *
 * Accounts identity: sent from accounts@/billing@ address, so billing conversations
 * and remittances land with the accounting team.
 */
class SalesInvoiceMailable extends CompanyMailable implements ShouldQueue
{
    public function __construct(
        public SalesInvoice $invoice,
        public ?string $customMessage = null,
    ) {
        $this->useAccountsIdentity();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice '.$this->invoice->number.' from '.company_name(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sales-invoice',
            with: array_merge($this->brandData(), [
                'invoice' => $this->invoice,
                'customer' => $this->invoice->customer,
                'customMessage' => $this->customMessage,
            ]),
        );
    }

    public function attachments(): array
    {
        $this->invoice->loadMissing(['customer', 'items.product', 'payments', 'creditNotes']);

        $html = view(InvoiceTemplate::view((string) $this->invoice->template), ['invoice' => $this->invoice])->render();
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();

        return [
            Attachment::fromData(fn () => $pdf, 'invoice-'.$this->invoice->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
