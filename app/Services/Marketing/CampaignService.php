<?php

namespace App\Services\Marketing;

use App\Contracts\EmailMarketingProviderInterface;
use App\DTOs\AudienceFilterData;
use App\Enums\EmailCampaignStatus;
use App\Enums\EmailRecipientStatus;
use App\Jobs\BuildCampaignRecipients;
use App\Jobs\DispatchCampaignBatch;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Ciclo da campanha: rascunho → (agendada) → montando público → enviando → enviada, com pausa,
 * retomada e cancelamento. Toda transição é um UPDATE condicionado ao status atual, então dois
 * cliques (ou o agendador junto com o botão) nunca disparam duas vezes.
 */
class CampaignService
{
    private const CONTENT_FIELDS = ['subject', 'preheader', 'html', 'from_email', 'from_name', 'ab_subject_b'];

    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly TemplateRenderer $renderer,
        private readonly SuppressionService $suppressions,
        private readonly CampaignStatsService $stats,
        private readonly EmailMarketingProviderInterface $provider,
    ) {}

    /**
     * Cria ou atualiza o rascunho. Mudou assunto/conteúdo/remetente: o teste enviado deixa de valer.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(?EmailCampaign $campaign, array $data, User $actor): EmailCampaign
    {
        $campaign ??= new EmailCampaign([
            'kind' => EmailCampaign::KIND_CAMPAIGN,
            'status' => EmailCampaignStatus::Draft,
            'created_by' => $actor->id,
        ]);

        if ($campaign->exists && ! $campaign->status->isEditable()) {
            throw new LogicException('Campanha em envio ou encerrada não pode ser alterada.');
        }

        if (array_key_exists('blocks', $data)) {
            $data['blocks'] = $this->renderer->normalizeBlocks($data['blocks'] ?? []);
            $data['html'] = $this->renderer->blocksToHtml($data['blocks']);
        }

        if (array_key_exists('filters', $data) && is_array($data['filters'])) {
            $data['filters'] = AudienceFilterData::fromArray($data['filters'])->toArray();
        }

        $campaign->fill(collect($data)->only([
            'name', 'subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'template_id',
            'segment_id', 'filters', 'blocks', 'html', 'ab_subject_b', 'ab_sample_percent', 'ab_wait_hours',
        ])->all());

        if ($campaign->exists && $campaign->isDirty(self::CONTENT_FIELDS)) {
            $campaign->test_sent_at = null;
        }

        // Editar uma campanha agendada tira do agendamento: precisa revisar e agendar de novo.
        if ($campaign->exists && $campaign->status === EmailCampaignStatus::Scheduled && $campaign->isDirty()) {
            $campaign->status = EmailCampaignStatus::Draft;
        }

        $campaign->save();

        return $campaign;
    }

    /**
     * Itens que precisam estar ok para o disparo sair.
     *
     * @return list<array{key: string, label: string, ok: bool, hint: ?string}>
     */
    public function checklist(EmailCampaign $campaign, ?int $audienceCount = null): array
    {
        $domain = Str::lower(Str::after((string) ($campaign->from_email ?: config('marketing.from.address')), '@'));
        $verified = collect(config('marketing.verified_domains', []))->map(fn ($item) => Str::lower($item));
        $unknown = $this->renderer->unknownVariables($campaign->subject, $campaign->preheader, $campaign->html, $campaign->ab_subject_b);
        $pendingEdits = $this->renderer->pendingEdits($campaign->subject, $campaign->preheader, $campaign->html, $campaign->ab_subject_b);
        $audienceCount ??= $this->audience->count($campaign->audienceFilter());
        $legal = config('marketing.legal');

        $items = [
            [
                'key' => 'content',
                'label' => 'Assunto e conteúdo preenchidos',
                'ok' => filled($campaign->subject) && filled(trim(strip_tags((string) $campaign->html, '<img>'))),
                'hint' => 'Preencha o assunto e adicione ao menos um bloco com conteúdo.',
            ],
            [
                'key' => 'variables',
                'label' => 'Variáveis válidas',
                'ok' => $unknown === [],
                'hint' => $unknown === [] ? null : 'Variáveis desconhecidas: '.implode(', ', array_map(fn ($name) => '{{'.$name.'}}', $unknown)),
            ],
            [
                'key' => 'pending_edits',
                'label' => 'Trechos do modelo editados',
                'ok' => $pendingEdits === [],
                'hint' => $pendingEdits === [] ? null : 'Troque os trechos marcados: '.Str::limit(implode(', ', $pendingEdits), 200),
            ],
            [
                'key' => 'sender',
                'label' => 'Remetente de domínio verificado',
                'ok' => $domain !== '' && $verified->contains($domain),
                'hint' => $verified->isEmpty()
                    ? 'Nenhum domínio verificado em MARKETING_VERIFIED_DOMAINS (SPF/DKIM no provedor).'
                    : 'Use um remetente de: '.$verified->implode(', '),
            ],
            [
                'key' => 'unsubscribe',
                'label' => 'Link de descadastro no e-mail',
                'ok' => str_contains($this->renderer->snapshot($campaign), TemplateRenderer::UNSUBSCRIBE_TOKEN),
                'hint' => 'O layout precisa do link de descadastro.',
            ],
            [
                'key' => 'legal',
                'label' => 'Rodapé com razão social e CNPJ',
                'ok' => filled($legal['company_name'] ?? null) && filled($legal['document'] ?? null),
                'hint' => 'Configure MARKETING_LEGAL_NAME, MARKETING_LEGAL_DOCUMENT e MARKETING_LEGAL_ADDRESS.',
            ],
            [
                'key' => 'audience',
                'label' => 'Público com destinatários',
                'ok' => $audienceCount > 0,
                'hint' => 'Nenhum destinatário depois de descontar supressões e opt-out.',
            ],
            [
                'key' => 'test',
                'label' => 'Teste enviado com o conteúdo atual',
                'ok' => $campaign->test_sent_at !== null,
                'hint' => 'Envie um teste no passo de revisão (refazer após mudar o conteúdo).',
            ],
        ];

        if (filled($campaign->ab_subject_b) || $campaign->ab_sample_percent) {
            $items[] = [
                'key' => 'ab',
                'label' => 'Teste A/B configurado',
                'ok' => $this->abIsValid($campaign),
                'hint' => 'Assunto B diferente do A, amostra entre 10% e 50% e espera entre 1 e 72 horas.',
            ];
        }

        return array_map(fn (array $item) => ['hint' => $item['ok'] ? null : $item['hint']] + $item, $items);
    }

    public function canDispatch(EmailCampaign $campaign, ?int $audienceCount = null): bool
    {
        return collect($this->checklist($campaign, $audienceCount))->every(fn (array $item) => $item['ok']);
    }

    /**
     * Envio de teste para até 5 e-mails, com os dados de uma empresa real se informada.
     *
     * @param  list<string>  $emails
     */
    public function sendTest(EmailCampaign $campaign, array $emails, ?int $companyId = null): int
    {
        $emails = collect($emails)->map(fn ($email) => SuppressionService::normalize($email))->filter()->unique()->values();

        if ($emails->isEmpty() || $emails->count() > (int) config('marketing.sending.test_max_recipients', 5)) {
            throw new InvalidArgumentException('Informe de 1 a '.config('marketing.sending.test_max_recipients', 5).' e-mails.');
        }

        if ($invalid = $emails->first(fn ($email) => ! SuppressionService::isValidEmail($email))) {
            throw new InvalidArgumentException("E-mail inválido: {$invalid}");
        }

        $variables = $this->renderer->sampleVariables($companyId);
        $variants = $campaign->hasAbTest() ? ['a', 'b'] : ['a'];

        foreach ($emails as $email) {
            foreach ($variants as $variant) {
                $this->provider->send($this->renderer->renderTest($campaign, $email, $variables, $variant));
            }
        }

        $campaign->forceFill(['test_sent_at' => now()])->save();

        Log::channel('marketing')->info('Marketing: teste enviado', [
            'campaign_id' => $campaign->id,
            'recipients' => $emails->count(),
            'provider' => $this->provider->name(),
        ]);

        return $emails->count();
    }

    public function schedule(EmailCampaign $campaign, CarbonInterface $at): void
    {
        if (! $campaign->status->isEditable() || ! $this->canDispatch($campaign)) {
            throw new LogicException('A campanha não pode ser agendada (confira o checklist).');
        }

        if ($at->isPast()) {
            throw new InvalidArgumentException('Escolha uma data e hora no futuro.');
        }

        $campaign->forceFill(['status' => EmailCampaignStatus::Scheduled, 'scheduled_at' => $at])->save();
    }

    public function unschedule(EmailCampaign $campaign): bool
    {
        return $this->transition($campaign, [EmailCampaignStatus::Scheduled], EmailCampaignStatus::Draft, ['scheduled_at' => null]);
    }

    /**
     * Começa o disparo (botão "Disparar" ou agendador). O público é recalculado aqui no servidor:
     * o número mostrado na tela nunca é usado.
     */
    public function start(EmailCampaign $campaign, bool $fromScheduler = false): bool
    {
        $lock = Cache::lock("marketing:campaign:{$campaign->id}:start", 30);

        if (! $lock->get()) {
            return false;
        }

        try {
            $campaign->refresh();

            if (! $this->canDispatch($campaign)) {
                if (! $fromScheduler) {
                    throw new LogicException('Checklist incompleto: a campanha não pode ser disparada.');
                }

                // Agendada, mas algo mudou desde então (público vazio, domínio removido...): não sai.
                $this->transition($campaign, [EmailCampaignStatus::Scheduled], EmailCampaignStatus::Draft, [
                    'last_error' => 'Agendamento suspenso: o checklist deixou de passar no horário do envio.',
                ]);

                return false;
            }

            $from = $fromScheduler ? [EmailCampaignStatus::Scheduled] : [EmailCampaignStatus::Draft, EmailCampaignStatus::Scheduled];

            if (! $this->transition($campaign, $from, EmailCampaignStatus::Building, ['last_error' => null])) {
                return false;
            }

            BuildCampaignRecipients::dispatch($campaign->id);

            Log::channel('marketing')->info('Marketing: disparo iniciado', [
                'campaign_id' => $campaign->id,
                'scheduled' => $fromScheduler,
            ]);

            $this->stats->broadcast($campaign->refresh());

            return true;
        } finally {
            $lock->release();
        }
    }

    /** @return int quantos destinatários novos entraram (duplicados e suprimidos ficam de fora) */
    public function addRecipients(EmailCampaign $campaign, Collection $rows, EmailRecipientStatus $status = EmailRecipientStatus::Pending): int
    {
        $byEmail = $rows
            ->map(fn (object $row) => [$row, SuppressionService::normalize($row->email)])
            ->filter(fn (array $pair) => SuppressionService::isValidEmail($pair[1]))
            ->unique(fn (array $pair) => $pair[1])
            ->keyBy(fn (array $pair) => $pair[1]);

        $suppressed = array_flip($this->suppressions->suppressedAmong($byEmail->keys()->all()));
        $now = now();

        $inserts = $byEmail
            ->reject(fn (array $pair, string $email) => isset($suppressed[$email]))
            ->map(fn (array $pair, string $email) => [
                'campaign_id' => $campaign->id,
                'user_id' => $pair[0]->user_id ?? null,
                'company_id' => $pair[0]->company_id ?? null,
                'email' => $email,
                'name' => Str::limit((string) ($pair[0]->user_name ?? ''), 250, '') ?: null,
                'variables' => json_encode(AudienceResolver::variablesFor($pair[0]), JSON_UNESCAPED_UNICODE),
                'status' => $status->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        return $inserts === [] ? 0 : DB::table('email_campaign_recipients')->insertOrIgnore($inserts);
    }

    /**
     * Teste A/B: sorteia a amostra (metade A, metade B) e segura o restante até a vencedora.
     * Idempotente: só mexe em quem ainda não tem variante.
     */
    public function assignAbVariants(EmailCampaign $campaign): void
    {
        if (! $campaign->hasAbTest() || $campaign->ab_winner) {
            return;
        }

        $ids = EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->whereNull('variant')
            ->where('status', EmailRecipientStatus::Pending->value)
            ->pluck('id')
            ->shuffle();

        $sampleSize = (int) ceil($ids->count() * $campaign->ab_sample_percent / 100);
        $sample = $ids->take(max($sampleSize, min(2, $ids->count())));
        [$groupA, $groupB] = $sample->values()->partition(fn ($id, $index) => $index % 2 === 0);

        foreach ([['a', $groupA], ['b', $groupB]] as [$variant, $group]) {
            foreach ($group->chunk(1000) as $chunk) {
                EmailCampaignRecipient::whereIn('id', $chunk->all())->update(['variant' => $variant]);
            }
        }

        foreach ($ids->diff($sample)->chunk(1000) as $chunk) {
            EmailCampaignRecipient::whereIn('id', $chunk->all())->update(['status' => EmailRecipientStatus::Held->value]);
        }
    }

    /** Escolhe a vencedora (maior taxa de abertura; empate fica com A) e libera o restante. */
    public function decideAbTest(EmailCampaign $campaign): ?string
    {
        if (! $campaign->hasAbTest() || $campaign->ab_winner || ! $campaign->started_at) {
            return null;
        }

        if ($campaign->started_at->copy()->addHours((int) $campaign->ab_wait_hours)->isFuture()) {
            return null;
        }

        $results = $this->stats->abResults($campaign);
        $winner = $results['b']['open_rate'] > $results['a']['open_rate'] ? 'b' : 'a';

        $decided = EmailCampaign::whereKey($campaign->id)->whereNull('ab_winner')
            ->update(['ab_winner' => $winner, 'ab_decided_at' => now()]);

        if ($decided !== 1) {
            return null;
        }

        EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', EmailRecipientStatus::Held->value)
            ->update(['status' => EmailRecipientStatus::Pending->value, 'variant' => $winner]);

        Log::channel('marketing')->info('Marketing: teste A/B decidido', [
            'campaign_id' => $campaign->id,
            'winner' => $winner,
            'open_rate_a' => $results['a']['open_rate'],
            'open_rate_b' => $results['b']['open_rate'],
        ]);

        if ($campaign->refresh()->status === EmailCampaignStatus::Sending) {
            DispatchCampaignBatch::dispatch($campaign->id);
        }

        return $winner;
    }

    public function pause(EmailCampaign $campaign): bool
    {
        $paused = $this->transition($campaign, [EmailCampaignStatus::Building, EmailCampaignStatus::Sending], EmailCampaignStatus::Paused);

        if ($paused) {
            $this->stats->broadcast($campaign->refresh());
        }

        return $paused;
    }

    /** Pausada antes de terminar de montar o público volta a montar (insertOrIgnore não duplica). */
    public function resume(EmailCampaign $campaign): bool
    {
        $campaign->refresh();
        $target = $campaign->started_at ? EmailCampaignStatus::Sending : EmailCampaignStatus::Building;

        if (! $this->transition($campaign, [EmailCampaignStatus::Paused], $target, ['last_error' => null])) {
            return false;
        }

        $target === EmailCampaignStatus::Sending
            ? DispatchCampaignBatch::dispatch($campaign->id)
            : BuildCampaignRecipients::dispatch($campaign->id);

        $this->stats->broadcast($campaign->refresh());

        return true;
    }

    /** Cancela: quem ainda não saiu não sai mais (os jobs já na fila também conferem o status). */
    public function cancel(EmailCampaign $campaign): bool
    {
        $from = array_filter(EmailCampaignStatus::cases(), fn (EmailCampaignStatus $status) => $status->canCancel());

        if (! $this->transition($campaign, $from, EmailCampaignStatus::Cancelled, ['finished_at' => now()])) {
            return false;
        }

        EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', [EmailRecipientStatus::Pending->value, EmailRecipientStatus::Held->value, EmailRecipientStatus::Queued->value])
            ->update(['status' => EmailRecipientStatus::Cancelled->value]);

        $this->stats->refresh($campaign->refresh());

        return true;
    }

    /** Termina a campanha quando ninguém mais está na fila (nem segurado pelo A/B). */
    public function finishIfDone(EmailCampaign $campaign): bool
    {
        $open = EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', EmailRecipientStatus::open())
            ->exists();

        if ($open || $campaign->isAutomation()) {
            return false;
        }

        if (! $this->transition($campaign, [EmailCampaignStatus::Sending], EmailCampaignStatus::Sent, ['finished_at' => now()])) {
            return false;
        }

        $this->stats->refresh($campaign->refresh());

        Log::channel('marketing')->info('Marketing: campanha concluída', [
            'campaign_id' => $campaign->id,
            'sent' => $campaign->sent_count,
            'failed' => $campaign->failed_count,
        ]);

        return true;
    }

    public function duplicate(EmailCampaign $campaign, User $actor): EmailCampaign
    {
        return EmailCampaign::create([
            'name' => Str::limit('Cópia de '.$campaign->name, 250, ''),
            'kind' => EmailCampaign::KIND_CAMPAIGN,
            'status' => EmailCampaignStatus::Draft,
        ] + $campaign->only([
            'subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'template_id', 'segment_id',
            'filters', 'blocks', 'html', 'ab_subject_b', 'ab_sample_percent', 'ab_wait_hours',
        ]) + ['created_by' => $actor->id]);
    }

    /**
     * @param  list<EmailCampaignStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    public function transition(EmailCampaign $campaign, array $from, EmailCampaignStatus $to, array $extra = []): bool
    {
        $updated = EmailCampaign::whereKey($campaign->id)
            ->whereIn('status', array_map(fn (EmailCampaignStatus $status) => $status->value, $from))
            ->update(['status' => $to->value, 'updated_at' => now()] + $extra);

        if ($updated === 1) {
            $campaign->forceFill(['status' => $to] + $extra)->syncOriginal();
        }

        return $updated === 1;
    }

    private function abIsValid(EmailCampaign $campaign): bool
    {
        return filled($campaign->ab_subject_b)
            && trim((string) $campaign->ab_subject_b) !== trim((string) $campaign->subject)
            && $campaign->ab_sample_percent >= 10 && $campaign->ab_sample_percent <= 50
            && $campaign->ab_wait_hours >= 1 && $campaign->ab_wait_hours <= 72;
    }
}
