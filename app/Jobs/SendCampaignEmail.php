<?php

namespace App\Jobs;

use App\Contracts\EmailMarketingProviderInterface;
use App\Enums\EmailCampaignStatus;
use App\Enums\EmailRecipientStatus;
use App\Exceptions\EmailProviderException;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\SuppressionService;
use App\Services\Marketing\TemplateRenderer;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia a campanha para um destinatário. Idempotente: só sai de "queued", e a reserva
 * queued → sending é um UPDATE condicional (dois workers nunca enviam o mesmo).
 * Falha permanente marca "failed" sem retry; temporária volta após 1, 5 e 15 minutos.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public readonly int $recipientId)
    {
        $this->onQueue(config('marketing.queue'));
        $this->afterCommit();
    }

    /** O limitador de taxa devolve o job à fila várias vezes; o teto é de tempo, não de tentativas. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('marketing-send')];
    }

    public function handle(
        EmailMarketingProviderInterface $provider,
        TemplateRenderer $renderer,
        SuppressionService $suppressions,
        CampaignService $campaigns,
    ): void {
        $recipient = EmailCampaignRecipient::with('campaign')->find($this->recipientId);
        $campaign = $recipient?->campaign;

        if (! $recipient || ! $campaign || $recipient->status !== EmailRecipientStatus::Queued) {
            return;
        }

        if ($campaign->status !== EmailCampaignStatus::Sending) {
            // Pausada: volta para a fila da campanha (retomar continua daqui). Qualquer outro
            // status (cancelada, encerrada): não sai mais.
            $this->moveFromQueued($recipient, $campaign->status === EmailCampaignStatus::Paused
                ? EmailRecipientStatus::Pending
                : EmailRecipientStatus::Cancelled);

            return;
        }

        if ($suppressions->blocks($recipient)) {
            $this->moveFromQueued($recipient, EmailRecipientStatus::Suppressed);
            $campaigns->finishIfDone($campaign);

            return;
        }

        $claimed = EmailCampaignRecipient::whereKey($recipient->id)
            ->where('status', EmailRecipientStatus::Queued->value)
            ->update([
                'status' => EmailRecipientStatus::Sending->value,
                'attempts' => $recipient->attempts + 1,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        // Espelha a reserva no modelo: sem isso, voltar para "queued" não ficaria sujo e não salvaria.
        $recipient->forceFill(['status' => EmailRecipientStatus::Sending, 'attempts' => $recipient->attempts + 1])->syncOriginal();

        try {
            $messageId = $provider->send($renderer->render($campaign, $recipient));
        } catch (EmailProviderException $exception) {
            if ($exception->accountLevel) {
                $this->pauseForAccountProblem($recipient, $campaign, $campaigns, $exception);

                return;
            }

            $this->handleFailure($recipient, $campaign, $campaigns, $exception->getMessage(), $exception->permanent);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->handleFailure($recipient, $campaign, $campaigns, 'Erro inesperado no envio.', false);

            return;
        }

        $recipient->forceFill([
            'status' => EmailRecipientStatus::Sent,
            'provider_message_id' => $messageId,
            'sent_at' => now(),
            'error' => null,
        ])->save();

        EmailCampaign::whereKey($campaign->id)->increment('sent_count');

        $campaigns->finishIfDone($campaign);
    }

    private function handleFailure(
        EmailCampaignRecipient $recipient,
        EmailCampaign $campaign,
        CampaignService $campaigns,
        string $error,
        bool $permanent,
    ): void {
        $backoff = (array) config('marketing.sending.backoff', [60, 300, 900]);
        $error = Str::limit($error, 480);

        if (! $permanent && $recipient->attempts <= count($backoff)) {
            $recipient->forceFill(['status' => EmailRecipientStatus::Queued, 'error' => $error])->save();
            $this->release((int) $backoff[$recipient->attempts - 1]);

            return;
        }

        $recipient->forceFill(['status' => EmailRecipientStatus::Failed, 'error' => $error])->save();
        EmailCampaign::whereKey($campaign->id)->increment('failed_count');

        Log::channel('marketing')->warning('Marketing: envio falhou', [
            'campaign_id' => $campaign->id,
            'recipient_id' => $recipient->id,
            'permanent' => $permanent,
            'attempts' => $recipient->attempts,
        ]);

        $campaigns->finishIfDone($campaign);
    }

    /** Desistiu de vez (passou o retryUntil, worker morto): quem ainda está na fila vira falha. */
    public function failed(?Throwable $exception = null): void
    {
        $updated = EmailCampaignRecipient::whereKey($this->recipientId)
            ->whereIn('status', [EmailRecipientStatus::Queued->value, EmailRecipientStatus::Sending->value])
            ->update(['status' => EmailRecipientStatus::Failed->value, 'error' => 'Envio desistido após várias tentativas.']);

        if ($updated === 1 && ($campaignId = EmailCampaignRecipient::whereKey($this->recipientId)->value('campaign_id'))) {
            EmailCampaign::whereKey($campaignId)->increment('failed_count');
        }
    }

    /**
     * Chave inválida, domínio não verificado, autenticação SMTP recusada: falharia para todos.
     * Pausa a campanha e devolve o destinatário à fila (retomar depois de corrigir continua daqui).
     */
    private function pauseForAccountProblem(
        EmailCampaignRecipient $recipient,
        EmailCampaign $campaign,
        CampaignService $campaigns,
        EmailProviderException $exception,
    ): void {
        $recipient->forceFill([
            'status' => EmailRecipientStatus::Pending,
            'attempts' => max(0, $recipient->attempts - 1),
            'error' => Str::limit($exception->getMessage(), 480),
        ])->save();

        if ($campaigns->transition($campaign, [EmailCampaignStatus::Sending], EmailCampaignStatus::Paused, [
            'last_error' => Str::limit('Pausada: o provedor recusou a conta ('.$exception->getMessage().'). Corrija a configuração e retome.', 480),
        ])) {
            Log::channel('discord')->error('E-mail marketing: provedor recusou a conta, campanha pausada', [
                'campaign_id' => $campaign->id,
                'provider_status' => $exception->getCode(),
            ]);
        }
    }

    private function moveFromQueued(EmailCampaignRecipient $recipient, EmailRecipientStatus $status): void
    {
        EmailCampaignRecipient::whereKey($recipient->id)
            ->where('status', EmailRecipientStatus::Queued->value)
            ->update(['status' => $status->value]);
    }
}
