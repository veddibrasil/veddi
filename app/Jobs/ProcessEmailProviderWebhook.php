<?php

namespace App\Jobs;

use App\DTOs\ProviderEventData;
use App\Enums\EmailEventType;
use App\Enums\EmailRecipientStatus;
use App\Enums\EmailSuppressionReason;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\Marketing\SuppressionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Retorno do provedor: entrega, bounce, spam e falha. A linha em email_events com
 * provider_event_id único é a trava de idempotência (reentrega do webhook conta uma vez).
 * Bounce permanente e reclamação de spam viram supressão na hora.
 */
class ProcessEmailProviderWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param  list<ProviderEventData>  $events */
    public function __construct(public readonly array $events)
    {
        $this->onQueue(config('marketing.queue'));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SuppressionService $suppressions): void
    {
        $touchedCampaigns = [];

        foreach ($this->events as $event) {
            $recipient = $this->findRecipient($event);

            if (! $recipient) {
                continue;
            }

            // Marcador de idempotência e efeitos juntos: se o efeito falhar, o retry não acha o
            // marcador e aplica de novo (senão um bounce/spam poderia nunca virar supressão).
            $applied = DB::transaction(function () use ($recipient, $event, $suppressions) {
                $inserted = DB::table('email_events')->insertOrIgnore([
                    'recipient_id' => $recipient->id,
                    'campaign_id' => $recipient->campaign_id,
                    'type' => $event->type->value,
                    'provider_event_id' => Str::limit($event->eventId, 250, ''),
                    'occurred_at' => $event->occurredAt,
                    'created_at' => now(),
                ]);

                if ($inserted === 0) {
                    return false;
                }

                $this->apply($recipient, $event, $suppressions);

                return true;
            });

            if ($applied) {
                $touchedCampaigns[$recipient->campaign_id] = true;
            }
        }

        foreach (array_keys($touchedCampaigns) as $campaignId) {
            RefreshCampaignStats::dispatch($campaignId);
        }
    }

    private function apply(EmailCampaignRecipient $recipient, ProviderEventData $event, SuppressionService $suppressions): void
    {
        $at = $event->occurredAt;

        match ($event->type) {
            EmailEventType::Delivered => EmailCampaignRecipient::whereKey($recipient->id)
                ->whereNull('delivered_at')
                ->update([
                    'delivered_at' => $at,
                    'status' => DB::raw("CASE WHEN status = '".EmailRecipientStatus::Sent->value."' THEN '".EmailRecipientStatus::Delivered->value."' ELSE status END"),
                ]),
            EmailEventType::HardBounce => $this->bounce($recipient, $at, $suppressions),
            EmailEventType::Complaint => $this->complaint($recipient, $at, $suppressions),
            EmailEventType::Failed => EmailCampaignRecipient::whereKey($recipient->id)
                ->whereIn('status', [EmailRecipientStatus::Sent->value, EmailRecipientStatus::Sending->value])
                ->update(['status' => EmailRecipientStatus::Failed->value, 'error' => Str::limit((string) ($event->detail ?: 'Recusado pelo provedor.'), 480)]),
            default => null,
        };
    }

    private function bounce(EmailCampaignRecipient $recipient, mixed $at, SuppressionService $suppressions): void
    {
        EmailCampaignRecipient::whereKey($recipient->id)->whereNull('bounced_at')
            ->update(['bounced_at' => $at, 'status' => EmailRecipientStatus::Bounced->value]);

        $suppressions->suppress($recipient->email, EmailSuppressionReason::HardBounce, $recipient->campaign_id);

        Log::channel('marketing')->info('Marketing: bounce permanente suprimido', [
            'campaign_id' => $recipient->campaign_id,
            'recipient_id' => $recipient->id,
        ]);
    }

    private function complaint(EmailCampaignRecipient $recipient, mixed $at, SuppressionService $suppressions): void
    {
        EmailCampaignRecipient::whereKey($recipient->id)->whereNull('complained_at')
            ->update(['complained_at' => $at, 'status' => EmailRecipientStatus::Complained->value]);

        $suppressions->suppress($recipient->email, EmailSuppressionReason::Complaint, $recipient->campaign_id);

        if ($recipient->user_id) {
            User::whereKey($recipient->user_id)->whereNull('marketing_opt_out_at')->update(['marketing_opt_out_at' => now()]);
        }

        Log::channel('marketing')->warning('Marketing: reclamação de spam suprimida', [
            'campaign_id' => $recipient->campaign_id,
            'recipient_id' => $recipient->id,
        ]);
    }

    private function findRecipient(ProviderEventData $event): ?EmailCampaignRecipient
    {
        if ($event->messageId) {
            $recipient = EmailCampaignRecipient::where('provider_message_id', $event->messageId)->first();

            if ($recipient) {
                return $recipient;
            }
        }

        // A tag recipient_id só vale se a mensagem bate (ou se o id ainda não foi gravado:
        // o webhook pode chegar antes do worker salvar o provider_message_id).
        if ($event->recipientId) {
            $recipient = EmailCampaignRecipient::find($event->recipientId);

            if ($recipient && ($recipient->provider_message_id === null || $recipient->provider_message_id === $event->messageId)) {
                return $recipient;
            }
        }

        return null;
    }
}
