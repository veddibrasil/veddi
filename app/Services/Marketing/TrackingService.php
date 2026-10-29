<?php

namespace App\Services\Marketing;

use App\Enums\EmailEventType;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Links assinados por destinatário: pixel de abertura, redirect de clique e descadastro.
 * Sem expiração (o e-mail fica na caixa); a assinatura impede trocar o destinatário ou o destino.
 */
class TrackingService
{
    public function openPixelUrl(EmailCampaignRecipient $recipient): string
    {
        return URL::signedRoute('marketing.open', ['recipient' => $recipient->id]);
    }

    public function unsubscribeUrl(EmailCampaignRecipient $recipient): string
    {
        return URL::signedRoute('marketing.unsubscribe', ['recipient' => $recipient->id]);
    }

    public function clickUrl(EmailCampaignRecipient $recipient, string $url): string
    {
        return URL::signedRoute('marketing.click', ['recipient' => $recipient->id, 'u' => self::encodeUrl($url)]);
    }

    /** Troca os links http(s) do HTML pelo redirect de clique deste destinatário. */
    public function rewriteLinks(string $html, EmailCampaignRecipient $recipient): string
    {
        return preg_replace_callback(
            '/(<a\b[^>]*?\shref=")(https?:[^"]+)(")/i',
            fn (array $match) => $match[1].e($this->clickUrl($recipient, html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5))).$match[3],
            $html,
        ) ?? $html;
    }

    public function recordOpen(EmailCampaignRecipient $recipient, Request $request): void
    {
        $this->storeEvent($recipient, EmailEventType::Open, $request);
        $this->markFirst($recipient, 'first_opened_at', 'opened_count');
    }

    /** Clique também conta como abertura (o pixel pode ter sido bloqueado pelo cliente de e-mail). */
    public function recordClick(EmailCampaignRecipient $recipient, string $url, Request $request): void
    {
        $this->storeEvent($recipient, EmailEventType::Click, $request, $url);
        $this->markFirst($recipient, 'first_clicked_at', 'clicked_count');
        $this->markFirst($recipient, 'first_opened_at', 'opened_count');
    }

    public static function encodeUrl(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    public static function decodeUrl(?string $encoded): ?string
    {
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /** IP nunca fica em claro: hash com a chave da aplicação. */
    public static function hashIp(?string $ip): ?string
    {
        return $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }

    private function storeEvent(EmailCampaignRecipient $recipient, EmailEventType $type, Request $request, ?string $url = null): void
    {
        EmailEvent::create([
            'recipient_id' => $recipient->id,
            'campaign_id' => $recipient->campaign_id,
            'type' => $type,
            'url' => $url ? Str::limit($url, 2040, '') : null,
            'user_agent' => Str::limit((string) $request->userAgent(), 490, '') ?: null,
            'ip_hash' => self::hashIp($request->ip()),
            'occurred_at' => now(),
        ]);
    }

    /** Primeira abertura/clique: grava a data e soma no contador da campanha uma vez só. */
    private function markFirst(EmailCampaignRecipient $recipient, string $column, string $counter): void
    {
        $updated = EmailCampaignRecipient::whereKey($recipient->id)->whereNull($column)->update([$column => now()]);

        if ($updated === 1) {
            EmailCampaign::whereKey($recipient->campaign_id)->increment($counter);
        }
    }
}
