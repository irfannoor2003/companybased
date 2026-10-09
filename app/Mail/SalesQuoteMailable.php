<?php

namespace App\Mail;

use App\Models\SalesQuote;
use App\Support\DocumentData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sends a sales quotation to a customer with PDF attached.
 *
 * Sales identity: sent from sales@ address or directly from the assigned Salesman
 * if enabled in Super Admin settings.
 */
class SalesQuoteMailable extends CompanyMailable implements ShouldQueue
{
    public function __construct(
        public SalesQuote $quote,
        public ?string $customMessage = null,
    ) {
        $this->useSalesIdentity($quote->salesman);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Quotation '.$this->quote->number.' from '.company_name(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sales-quote',
            with: array_merge($this->brandData(), [
                'quote' => $this->quote,
                'customer' => $this->quote->customer,
                'salesman' => $this->quote->salesman,
                'customMessage' => $this->customMessage,
            ]),
        );
    }

    public function attachments(): array
    {
        $this->quote->loadMissing(['customer', 'salesman', 'items.product']);

        $html = view('documents.pdf', DocumentData::build($this->quote))->render();
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();

        return [
            Attachment::fromData(fn () => $pdf, 'quote-'.$this->quote->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
