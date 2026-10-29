<?php

namespace App\Services\Marketing;

use App\DTOs\AudienceFilterData;
use App\Models\EmailSegment;
use Illuminate\Support\Str;

/**
 * Segmentos prontos, instalados pelo super admin na tela de Segmentos ou ao começar uma campanha
 * pronta. Cada um vira um EmailSegment comum (editável). Só filtros relativos ou fixos: nada de
 * data absoluta, que envelheceria depois de instalado.
 */
class DefaultEmailSegments
{
    public function __construct(private readonly AudienceResolver $audience) {}

    /**
     * Cria os segmentos que ainda não existem (comparando pelo nome). Retorna quantos criou.
     *
     * @param  list<string>|null  $only  nomes dos segmentos
     */
    public function install(?int $userId = null, ?array $only = null): int
    {
        $existing = EmailSegment::pluck('name')->map(fn (string $name) => Str::lower(trim($name)))->all();
        $wanted = $only === null ? null : array_map(fn (string $name) => Str::lower(trim($name)), $only);
        $created = 0;

        foreach ($this->definitions() as $definition) {
            $name = Str::lower($definition['name']);

            if (in_array($name, $existing, true) || ($wanted !== null && ! in_array($name, $wanted, true))) {
                continue;
            }

            $filters = AudienceFilterData::fromArray($definition['filters']);

            EmailSegment::create([
                'name' => $definition['name'],
                'filters' => $filters->toArray(),
                'last_count' => $this->audience->count($filters),
                'last_counted_at' => now(),
                'created_by' => $userId,
            ]);

            $created++;
        }

        return $created;
    }

    /** Segmento pelo nome (sem diferenciar maiúsculas), instalando o padrão se faltar. */
    public function findOrInstall(string $name, ?int $userId = null): ?EmailSegment
    {
        $find = fn () => EmailSegment::whereRaw('LOWER(TRIM(name)) = ?', [Str::lower(trim($name))])->oldest('id')->first();

        if ($segment = $find()) {
            return $segment;
        }

        $this->install($userId, [$name]);

        return $find();
    }

    /** @return list<array{name: string, description: string, filters: array<string, mixed>}> */
    public function definitions(): array
    {
        $active = ['company_statuses' => ['ACTIVE'], 'company_active' => true];
        $owners = ['roles' => ['company_admin']];

        return [
            [
                'name' => 'Todos os usuários de empresas ativas',
                'description' => 'Donos e gerentes. Bom para comunicados e novidades.',
                'filters' => $active,
            ],
            [
                'name' => 'Donos de empresas ativas',
                'description' => 'Só o administrador de cada empresa ativa.',
                'filters' => $active + $owners,
            ],
            [
                'name' => 'Gerentes de filial',
                'description' => 'Quem opera a filial no dia a dia.',
                'filters' => $active + ['roles' => ['branch_manager']],
            ],
            [
                'name' => 'Plano Grátis ativo',
                'description' => 'Donos no plano Grátis: público do upgrade.',
                'filters' => $active + $owners + ['plans' => ['free']],
            ],
            [
                'name' => 'Assinantes Essencial e PRO',
                'description' => 'Donos dos planos pagos.',
                'filters' => $active + $owners + ['plans' => ['essencial', 'pro']],
            ],
            [
                'name' => 'Cadastro não concluído',
                'description' => 'Cadastrou e não pagou a ativação (qualquer data).',
                'filters' => $owners + ['company_statuses' => ['PENDING_PAYMENT']],
            ],
            [
                'name' => 'Sem pedidos há 30 dias',
                'description' => 'Empresas ativas, cadastradas há 30 dias ou mais, sem pedido no período.',
                'filters' => $active + $owners + ['inactive_days' => 30],
            ],
            [
                'name' => 'Ativos sem PDV',
                'description' => 'Público do módulo PDV.',
                'filters' => $active + $owners + ['pdv_enabled' => false],
            ],
            [
                'name' => 'Ativos sem nota fiscal',
                'description' => 'Público do módulo Fiscal.',
                'filters' => $active + $owners + ['fiscal_enabled' => false],
            ],
            [
                'name' => 'Empresas inadimplentes',
                'description' => 'Mensalidade em atraso. Use para avisos, não para promoção.',
                'filters' => $owners + ['company_statuses' => ['OVERDUE']],
            ],
        ];
    }
}
