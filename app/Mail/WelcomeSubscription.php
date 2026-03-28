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

class WelcomeSubscription extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Company $company,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sua assinatura {$this->company->name} foi ativada!",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome-subscription',
        );
    }
}
