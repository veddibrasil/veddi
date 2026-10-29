<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChatVerificationCode extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public string $code,
        public string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Seu código de acesso — {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.chat-verification-code');
    }
}
