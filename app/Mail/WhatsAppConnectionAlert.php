<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WhatsAppConnectionAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  array<int, array{title: string, message: string}>  $alerts */
    public function __construct(
        public User $user,
        public Company $company,
        public array $alerts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Atenção com o WhatsApp de {$this->company->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.whatsapp-connection-alert',
        );
    }
}
