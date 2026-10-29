<?php

namespace App\Mail\Marketing;

use App\DTOs\RenderedEmailData;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Mailable genérico do e-mail marketing: recebe o HTML já renderizado (TemplateRenderer).
 * Não é ShouldQueue: o envio já roda dentro do SendCampaignEmail (fila marketing), e o id da
 * mensagem precisa voltar para o destinatário.
 */
class CampaignEmail extends Mailable
{
    public function __construct(public readonly RenderedEmailData $email)
    {
        // Tags/metadata viram cabeçalhos do provedor (Postmark, Mailgun, SES...) e voltam no webhook.
        foreach ($email->tags as $key => $value) {
            $this->metadata($key, $value);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->email->fromEmail, $this->email->fromName),
            to: [new Address($this->email->toEmail, $this->email->toName)],
            replyTo: $this->email->replyTo ? [new Address($this->email->replyTo)] : [],
            subject: $this->email->subject,
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->email->headers);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->email->html,
            text: 'emails.marketing.text',
            with: ['text' => $this->email->text],
        );
    }
}
