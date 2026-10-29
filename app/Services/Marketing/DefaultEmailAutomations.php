<?php

namespace App\Services\Marketing;

use App\Enums\EmailAutomationTrigger;
use App\Models\EmailAutomation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Automações prontas: cada uma usa os modelos padrão de template (instalados se faltarem) e nasce
 * pausada. Ativar continua passando pelo checklist (remetente verificado, rodapé legal).
 */
class DefaultEmailAutomations
{
    public function __construct(
        private readonly AutomationService $automations,
        private readonly DefaultEmailTemplates $templates,
    ) {}

    /** Cria as automações que ainda não existem (comparando pelo nome). Retorna quantas criou. */
    public function install(User $actor): int
    {
        $existing = EmailAutomation::pluck('name')->map(fn (string $name) => Str::lower(trim($name)))->all();
        $created = 0;

        foreach ($this->definitions() as $definition) {
            if (in_array(Str::lower($definition['name']), $existing, true)) {
                continue;
            }

            $steps = [];

            foreach ($definition['steps'] as $step) {
                $template = $this->templates->findOrInstall($step['template'], $actor->id);

                if (! $template) {
                    continue 2;
                }

                $steps[] = [
                    'delay_hours' => $step['delay_hours'],
                    'template_id' => $template->id,
                    'subject' => (string) $template->subject_default,
                    'preheader' => $template->preheader_default,
                ];
            }

            $this->automations->save(null, [
                'name' => $definition['name'],
                'trigger' => $definition['trigger']->value,
                'trigger_settings' => ['threshold' => $definition['threshold']],
                'from_name' => (string) config('marketing.from.name'),
                'from_email' => (string) config('marketing.from.address'),
                'reply_to' => config('marketing.reply_to') ?: null,
            ], $steps, $actor);

            $created++;
        }

        return $created;
    }

    /**
     * @return list<array{name: string, description: string, trigger: EmailAutomationTrigger, threshold: int, steps: list<array{template: string, delay_hours: int}>}>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'Boas-vindas a empresas novas',
                'description' => 'Boas-vindas 1 h depois do cadastro e dicas de venda 3 dias depois.',
                'trigger' => EmailAutomationTrigger::NewCompany,
                'threshold' => 1,
                'steps' => [
                    ['template' => 'Boas-vindas', 'delay_hours' => 0],
                    ['template' => 'Dicas para vender mais', 'delay_hours' => 72],
                ],
            ],
            [
                'name' => 'Recuperar cadastro abandonado',
                'description' => 'Lembrete 2 h depois do cadastro sem pagamento e último aviso 2 dias depois.',
                'trigger' => EmailAutomationTrigger::AbandonedSignup,
                'threshold' => 2,
                'steps' => [
                    ['template' => 'Cadastro pendente: lembrete', 'delay_hours' => 0],
                    ['template' => 'Cadastro pendente: último aviso', 'delay_hours' => 48],
                ],
            ],
            [
                'name' => 'Reengajar empresa sem pedidos',
                'description' => 'Checklist depois de 30 dias sem pedidos e dicas de venda uma semana depois.',
                'trigger' => EmailAutomationTrigger::InactiveCompany,
                'threshold' => 30,
                'steps' => [
                    ['template' => 'Empresa sem pedidos: reengajamento', 'delay_hours' => 0],
                    ['template' => 'Dicas para vender mais', 'delay_hours' => 168],
                ],
            ],
        ];
    }
}
