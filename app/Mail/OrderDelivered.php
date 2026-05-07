<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderDelivered extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public Customer $customer,
        public ?Company $company,
    ) {}

    public function envelope(): Envelope
    {
        $companyName = $this->company?->name ?? config('app.name');

        return new Envelope(
            subject: "Pedido #{$this->order->order_number} finalizado — {$companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-delivered',
        );
    }
}
