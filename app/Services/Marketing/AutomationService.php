<?php

namespace App\Services\Marketing;

use App\DTOs\AudienceFilterData;
use App\Enums\EmailAutomationTrigger;
use App\Enums\EmailCampaignStatus;
use App\Enums\EmailRecipientStatus;
use App\Jobs\DispatchCampaignBatch;
use App\Models\EmailAutomation;
use App\Models\EmailAutomationEnrollment;
use App\Models\EmailAutomationStep;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailTemplate;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Automações por gatilho. Cada passo envia por uma campanha kind=automation (mesmos destinatários,
 * supressão, descadastro, rastreio e métricas das campanhas). Uma pessoa entra uma vez por
 * automação (unique automation_id+email) e sai quando o gatilho deixa de valer.
 */
class AutomationService
{
    // Cadastro abandonado só considera cadastros recentes: lead antigo não recebe sequência nova.
    private const ABANDONED_SIGNUP_WINDOW_DAYS = 30;

    // Empresa nova: cadastro dos últimos 7 dias. Ativar a automação não manda boas-vindas para a base antiga.
    private const NEW_COMPANY_WINDOW_DAYS = 7;

    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly CampaignService $campaigns,
        private readonly TemplateRenderer $renderer,
    ) {}

    /** @return array{enrolled: int, queued: int, exited: int} */
    public function run(): array
    {
        $totals = ['enrolled' => 0, 'queued' => 0, 'exited' => 0];

        EmailAutomation::where('active', true)->with('steps.campaign')->each(function (EmailAutomation $automation) use (&$totals) {
            if ($automation->steps->isEmpty()) {
                return;
            }

            $totals['exited'] += $this->exitResolved($automation);
            $totals['enrolled'] += $this->enroll($automation);
            $totals['queued'] += $this->advance($automation);
        });

        return $totals;
    }

    /** @return array{0: AudienceFilterData, 1: ?CarbonInterface} */
    public function audienceFor(EmailAutomation $automation): array
    {
        return match ($automation->trigger) {
            EmailAutomationTrigger::AbandonedSignup => [
                new AudienceFilterData(
                    companyStatuses: ['PENDING_PAYMENT'],
                    createdFrom: now()->subDays(self::ABANDONED_SIGNUP_WINDOW_DAYS)->toDateString(),
                ),
                now()->subHours($automation->threshold()),
            ],
            // Só empresa que já existe há N dias: a recém-criada também não tem pedidos.
            EmailAutomationTrigger::InactiveCompany => [
                new AudienceFilterData(companyStatuses: ['ACTIVE'], companyActive: true, inactiveDays: $automation->threshold()),
                now()->subDays($automation->threshold()),
            ],
            EmailAutomationTrigger::NewCompany => [
                new AudienceFilterData(
                    companyStatuses: ['ACTIVE'],
                    companyActive: true,
                    createdFrom: now()->subDays(self::NEW_COMPANY_WINDOW_DAYS)->toDateString(),
                ),
                now()->subHours($automation->threshold()),
            ],
        };
    }

    public function enroll(EmailAutomation $automation): int
    {
        $first = $automation->steps->first();
        [$filters, $createdBefore] = $this->audienceFor($automation);
        $enrolled = 0;

        $this->audience->chunk($filters, 1000, function (Collection $rows) use ($automation, $first, &$enrolled) {
            $now = now();

            // Automação acompanha pessoas: o e-mail da empresa (sem user_id) não entra na sequência.
            $enrolled += DB::table('email_automation_enrollments')->insertOrIgnore($rows->whereNotNull('user_id')->values()->map(fn (object $row) => [
                'automation_id' => $automation->id,
                'user_id' => $row->user_id,
                'company_id' => $row->company_id,
                'email' => SuppressionService::normalize($row->email),
                'next_position' => $first->position,
                'status' => EmailAutomationEnrollment::STATUS_ACTIVE,
                'next_run_at' => $now->copy()->addHours($first->delay_hours),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }, $createdBefore);

        return $enrolled;
    }

    /**
     * Sai da sequência quem já resolveu: empresa ativou (cadastro), voltou a vender (inativa) ou
     * deixou de estar ativa (empresa nova: bloqueada ou inadimplente não recebe boas-vindas).
     */
    public function exitResolved(EmailAutomation $automation): int
    {
        $query = EmailAutomationEnrollment::where('automation_id', $automation->id)
            ->where('status', EmailAutomationEnrollment::STATUS_ACTIVE);

        $query = match ($automation->trigger) {
            EmailAutomationTrigger::AbandonedSignup => $query->where(fn ($inner) => $inner->whereNull('company_id')
                ->orWhereNotExists(fn ($companies) => $companies->selectRaw('1')->from('companies')
                    ->whereColumn('companies.id', 'email_automation_enrollments.company_id')
                    ->where('companies.status', 'PENDING_PAYMENT'))),
            EmailAutomationTrigger::InactiveCompany => $query->whereExists(fn ($orders) => $orders->selectRaw('1')->from('orders')
                ->whereColumn('orders.company_id', 'email_automation_enrollments.company_id')
                ->whereColumn('orders.created_at', '>=', 'email_automation_enrollments.created_at')),
            EmailAutomationTrigger::NewCompany => $query->where(fn ($inner) => $inner->whereNull('company_id')
                ->orWhereNotExists(fn ($companies) => $companies->selectRaw('1')->from('companies')
                    ->whereColumn('companies.id', 'email_automation_enrollments.company_id')
                    ->where('companies.status', 'ACTIVE')
                    ->where('companies.active', true))),
        };

        $emails = (clone $query)->pluck('email');

        if ($emails->isEmpty()) {
            return 0;
        }

        // E-mail do passo que ainda não saiu (fila, limite diário, campanha pausada) também não sai mais.
        foreach ($emails->chunk(500) as $chunk) {
            EmailCampaignRecipient::whereIn('campaign_id', $automation->steps->pluck('campaign_id'))
                ->whereIn('email', $chunk->all())
                ->whereIn('status', [EmailRecipientStatus::Pending->value, EmailRecipientStatus::Queued->value])
                ->update(['status' => EmailRecipientStatus::Cancelled->value]);
        }

        return $query->update([
            'status' => EmailAutomationEnrollment::STATUS_EXITED,
            'next_run_at' => null,
            'finished_at' => now(),
        ]);
    }

    /** Envia o passo vencido de cada inscrito e agenda o próximo. */
    public function advance(EmailAutomation $automation): int
    {
        $steps = $automation->steps->keyBy('position');
        $queued = 0;
        $touched = [];

        EmailAutomationEnrollment::where('automation_id', $automation->id)
            ->where('status', EmailAutomationEnrollment::STATUS_ACTIVE)
            ->where('next_run_at', '<=', now())
            ->orderBy('id')
            ->chunkById(500, function (Collection $enrollments) use ($steps, &$queued, &$touched) {
                $people = $this->peopleFor($enrollments);

                foreach ($enrollments as $enrollment) {
                    $step = $steps->get($enrollment->next_position);

                    if (! $step || ! $step->campaign) {
                        $enrollment->update(['status' => EmailAutomationEnrollment::STATUS_COMPLETED, 'next_run_at' => null, 'finished_at' => now()]);

                        continue;
                    }

                    if ($person = $people->get($enrollment->id)) {
                        $queued += $this->campaigns->addRecipients($step->campaign, collect([$person]));
                        $touched[$step->campaign_id] = true;
                    }

                    $next = $steps->first(fn (EmailAutomationStep $candidate) => $candidate->position > $step->position);

                    $enrollment->update($next
                        ? ['next_position' => $next->position, 'next_run_at' => now()->addHours($next->delay_hours)]
                        : ['status' => EmailAutomationEnrollment::STATUS_COMPLETED, 'next_run_at' => null, 'finished_at' => now()]);
                }
            });

        foreach (array_keys($touched) as $campaignId) {
            DispatchCampaignBatch::dispatch($campaignId);
        }

        return $queued;
    }

    /**
     * Salva a automação e os passos. Cada passo copia o template para a campanha interna dele;
     * passo removido cancela a campanha (as métricas ficam).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{id?: ?int, delay_hours: int, subject: string, preheader?: ?string, template_id: int}>  $steps
     */
    public function save(?EmailAutomation $automation, array $data, array $steps, User $actor): EmailAutomation
    {
        return DB::transaction(function () use ($automation, $data, $steps, $actor) {
            $automation ??= new EmailAutomation(['created_by' => $actor->id, 'active' => false]);
            $automation->fill(collect($data)->only(['name', 'trigger', 'trigger_settings', 'from_name', 'from_email', 'reply_to'])->all());
            $automation->save();

            $keep = [];

            foreach (array_values($steps) as $index => $stepData) {
                $template = EmailTemplate::findOrFail($stepData['template_id']);
                $step = isset($stepData['id']) ? $automation->steps()->find($stepData['id']) : null;
                $campaign = $step?->campaign ?? new EmailCampaign([
                    'kind' => EmailCampaign::KIND_AUTOMATION,
                    'created_by' => $actor->id,
                ]);

                $campaign->fill([
                    'name' => Str::limit($automation->name.' · passo '.($index + 1), 250, ''),
                    'subject' => $stepData['subject'],
                    'preheader' => $stepData['preheader'] ?? null,
                    'from_name' => $automation->from_name,
                    'from_email' => $automation->from_email,
                    'reply_to' => $automation->reply_to,
                    'template_id' => $template->id,
                    'blocks' => $template->blocks,
                    'html' => $template->html,
                ]);
                $campaign->html_snapshot = $this->renderer->snapshot($campaign);
                $campaign->status = $automation->active ? EmailCampaignStatus::Sending : EmailCampaignStatus::Paused;
                $campaign->started_at ??= now();
                $campaign->save();

                // Reordenar sem bater no unique(automation_id, position): posição provisória negativa não
                // existe em unsigned, então usa um deslocamento alto e normaliza no fim.
                $step ??= new EmailAutomationStep(['automation_id' => $automation->id]);
                $step->fill(['position' => 1000 + $index, 'delay_hours' => (int) $stepData['delay_hours'], 'campaign_id' => $campaign->id])->save();

                $keep[] = $step->id;
            }

            $removed = $automation->steps()->whereNotIn('id', $keep)->get();

            foreach ($removed as $step) {
                $step->campaign?->forceFill(['status' => EmailCampaignStatus::Cancelled, 'finished_at' => now()])->save();
                $step->delete();
            }

            foreach ($automation->steps()->get() as $step) {
                $step->update(['position' => $step->position - 999]);
            }

            return $automation->refresh();
        });
    }

    /** @return list<string> o que impede ativar a automação */
    public function activationProblems(EmailAutomation $automation): array
    {
        $automation->loadMissing('steps.campaign');

        if ($automation->steps->isEmpty()) {
            return ['Adicione ao menos um e-mail na sequência.'];
        }

        $problems = [];

        foreach ($automation->steps as $step) {
            foreach ($this->campaigns->checklist($step->campaign, audienceCount: 1) as $item) {
                if (! $item['ok'] && ! in_array($item['key'], ['audience', 'test'], true)) {
                    $problems[$item['label']] = "{$item['label']}: {$item['hint']}";
                }
            }
        }

        return array_values($problems);
    }

    public function setActive(EmailAutomation $automation, bool $active): void
    {
        DB::transaction(function () use ($automation, $active) {
            $automation->update(['active' => $active]);

            EmailCampaign::whereIn('id', $automation->steps()->pluck('campaign_id'))
                ->whereIn('status', [EmailCampaignStatus::Sending->value, EmailCampaignStatus::Paused->value])
                ->update(['status' => ($active ? EmailCampaignStatus::Sending : EmailCampaignStatus::Paused)->value]);
        });

        Log::channel('marketing')->info('Marketing: automação '.($active ? 'ativada' : 'pausada'), ['automation_id' => $automation->id]);
    }

    /** @return Collection<int, object> pessoa (formato do AudienceResolver) por enrollment id */
    private function peopleFor(Collection $enrollments): Collection
    {
        return DB::table('email_automation_enrollments')
            ->join('users', 'users.id', '=', 'email_automation_enrollments.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'email_automation_enrollments.company_id')
            ->whereIn('email_automation_enrollments.id', $enrollments->modelKeys())
            ->whereNull('users.deleted_at')
            ->whereNull('users.marketing_opt_out_at')
            ->when(config('marketing.require_opt_in'), fn ($query) => $query->whereNotNull('users.marketing_opt_in_at'))
            ->select([
                'email_automation_enrollments.id as enrollment_id',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as email',
                'companies.id as company_id',
                'companies.name as company_name',
                'companies.plan as plan',
            ])
            ->get()
            ->keyBy('enrollment_id');
    }
}
