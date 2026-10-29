<?php

namespace App\Services\Marketing\Providers;

use App\Contracts\EmailMarketingProviderInterface;
use App\DTOs\RenderedEmailData;
use App\Exceptions\EmailProviderException;
use App\Exceptions\InvalidEmailWebhookException;
use App\Mail\Marketing\CampaignEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * Envia pelo mailer "marketing" (config/mail.php): qualquer transport do Laravel — SMTP do
 * provedor, SES, Postmark ou log em desenvolvimento. Sem webhook: entrega, bounce e spam só
 * chegam com um provedor que tenha (ex.: ResendProvider).
 */
class MailMarketingProvider implements EmailMarketingProviderInterface
{
    public function name(): string
    {
        return 'mail';
    }

    public function send(RenderedEmailData $email): string
    {
        try {
            $sent = Mail::mailer((string) config('marketing.mailer', 'marketing'))->send(new CampaignEmail($email));
        } catch (UnexpectedResponseException $exception) {
            // Resposta SMTP 5xx (caixa inexistente, remetente recusado) é definitiva; 4xx é temporária.
            $code = (int) $exception->getCode();

            // 530/535: autenticação SMTP recusada — problema da conta, não do destinatário.
            if ($code === 530 || $code === 535) {
                throw EmailProviderException::account(self::safeMessage($exception->getMessage()), $code, $exception);
            }

            throw new EmailProviderException(self::safeMessage($exception->getMessage()), $code >= 500 && $code < 600, $code, $exception);
        } catch (TransportExceptionInterface $exception) {
            throw EmailProviderException::temporary(self::safeMessage($exception->getMessage()), 0, $exception);
        }

        return $sent?->getMessageId() ?: (string) Str::uuid();
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): array
    {
        throw new InvalidEmailWebhookException('O provedor "mail" não recebe webhook.');
    }

    /** Mensagem do transport sem credencial (o SMTP às vezes ecoa o usuário/host). */
    private static function safeMessage(string $message): string
    {
        return Str::limit(preg_replace('/(password|pass|auth|user(name)?)\S*/i', '$1=***', $message) ?? '', 300);
    }
}
