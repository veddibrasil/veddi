<?php

namespace App\Services\Marketing;

use App\Enums\EmailEventType;
use App\Enums\EmailSuppressionReason;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailEvent;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lista global de supressão (descadastro, bounce permanente, spam, manual). Consultada ao montar
 * o público e de novo antes de cada envio: quem está aqui nunca recebe novo disparo.
 */
class SuppressionService
{
    public static function normalize(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 255;
    }

    /** Mascara para log e auditoria: m***@dominio.com. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public function isSuppressed(string $email): bool
    {
        return EmailSuppression::where('email', self::normalize($email))->exists();
    }

    /**
     * Checagem antes de cada envio: supressão e o consentimento atual do usuário (pode ter saído
     * pelo perfil depois que o público foi montado, numa campanha de vários dias ou automação).
     */
    public function blocks(EmailCampaignRecipient $recipient): bool
    {
        if ($this->isSuppressed($recipient->email)) {
            return true;
        }

        // Sem usuário: contato de lista (aceite na importação) ou e-mail da empresa (sem aceite próprio).
        if (! $recipient->user_id) {
            return $recipient->company_id && config('marketing.require_opt_in');
        }

        $user = User::withTrashed()->find($recipient->user_id, ['id', 'deleted_at', 'marketing_opt_in_at', 'marketing_opt_out_at']);

        return ! $user
            || $user->trashed()
            || $user->marketing_opt_out_at !== null
            || (config('marketing.require_opt_in') && $user->marketing_opt_in_at === null);
    }

    /**
     * @param  list<string>  $emails  já normalizados
     * @return list<string> os que estão suprimidos
     */
    public function suppressedAmong(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return EmailSuppression::whereIn('email', $emails)->pluck('email')->all();
    }

    public function suppress(
        string $email,
        EmailSuppressionReason $reason,
        ?int $sourceCampaignId = null,
        ?string $note = null,
        ?int $createdBy = null,
    ): EmailSuppression {
        $email = self::normalize($email);

        $suppression = EmailSuppression::firstOrCreate(['email' => $email], [
            'reason' => $reason,
            'source_campaign_id' => $sourceCampaignId,
            'note' => $note,
            'created_by' => $createdBy,
        ]);

        // Reclamação de spam e bounce permanente pesam mais que descadastro/manual: o motivo
        // mais grave fica registrado (só um removível pelo próprio usuário via preferências).
        if (! $suppression->wasRecentlyCreated && $this->severity($reason) > $this->severity($suppression->reason)) {
            $suppression->update(['reason' => $reason, 'source_campaign_id' => $sourceCampaignId ?? $suppression->source_campaign_id]);
        }

        return $suppression;
    }

    /** Descadastro pelo link do e-mail (página ou One-Click). Idempotente. */
    public function unsubscribe(EmailCampaignRecipient $recipient): void
    {
        DB::transaction(function () use ($recipient) {
            $this->suppress($recipient->email, EmailSuppressionReason::Unsubscribed, $recipient->campaign_id);

            if (EmailCampaignRecipient::whereKey($recipient->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]) === 1) {
                EmailCampaign::whereKey($recipient->campaign_id)->increment('unsubscribed_count');

                EmailEvent::create([
                    'recipient_id' => $recipient->id,
                    'campaign_id' => $recipient->campaign_id,
                    'type' => EmailEventType::Unsubscribe,
                    'occurred_at' => now(),
                ]);
            }

            $this->userFor($recipient->email)?->forceFill(['marketing_opt_out_at' => now()])->save();
        });

        Log::channel('marketing')->info('Marketing: descadastro', [
            'campaign_id' => $recipient->campaign_id,
            'recipient_id' => $recipient->id,
        ]);
    }

    /**
     * Volta a receber (página de preferências ou perfil). Só desfaz o que a própria pessoa fez:
     * bounce, spam e supressão manual continuam valendo.
     */
    public function resubscribe(string $email): bool
    {
        $email = self::normalize($email);
        $suppression = EmailSuppression::where('email', $email)->first();

        if ($suppression && $suppression->reason !== EmailSuppressionReason::Unsubscribed) {
            return false;
        }

        DB::transaction(function () use ($suppression, $email) {
            $suppression?->delete();
            $this->userFor($email)?->forceFill(['marketing_opt_in_at' => now(), 'marketing_opt_out_at' => null])->save();
        });

        return true;
    }

    /** Remoção pelo super admin, com motivo registrado na trilha de auditoria (Discord). */
    public function remove(EmailSuppression $suppression, string $reason, User $actor): void
    {
        $suppression->delete();

        Log::channel('discord')->warning('E-mail marketing: supressão removida', [
            'actor_id' => $actor->id,
            'suppression_id' => $suppression->id,
            'email' => self::mask($suppression->email),
            'original_reason' => $suppression->reason->value,
            'removal_reason' => $reason,
        ]);
    }

    /**
     * Importa e-mails (um por linha, ou a primeira coluna de um CSV com , ; ou tab) como
     * supressão manual.
     *
     * @return array{added: int, existing: int, invalid: int}
     */
    public function importCsv(string $path, ?string $note, User $actor): array
    {
        $result = ['added' => 0, 'existing' => 0, 'invalid' => 0];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return $result;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $first = preg_split('/[,;\t]/', $line, 2)[0] ?? '';
                $email = self::normalize(trim(ltrim($first, "\u{FEFF}"), " \t\n\r\0\x0B\"'"));

                if ($email === '' || $email === 'email' || $email === 'e-mail') {
                    continue;
                }

                if (! self::isValidEmail($email)) {
                    $result['invalid']++;

                    continue;
                }

                $created = $this->suppress($email, EmailSuppressionReason::Manual, null, $note, $actor->id)->wasRecentlyCreated;
                $result[$created ? 'added' : 'existing']++;
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    private function severity(EmailSuppressionReason $reason): int
    {
        return match ($reason) {
            EmailSuppressionReason::Unsubscribed => 1,
            EmailSuppressionReason::Manual => 2,
            EmailSuppressionReason::HardBounce => 3,
            EmailSuppressionReason::Complaint => 4,
        };
    }

    private function userFor(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [self::normalize($email)])->first();
    }
}
