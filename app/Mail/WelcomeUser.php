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

class WelcomeUser extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Company $company,
        public string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Bem-vindo(a) à {$this->company->name}!",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome-user',
        );
    }
}
