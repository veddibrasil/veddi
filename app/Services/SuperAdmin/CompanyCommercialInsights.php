<?php

namespace App\Services\SuperAdmin;

use App\Enums\Plan;
use App\Models\Company;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Scopes\CompanyScope;
use App\Services\Company\AddonModulePricing;
use Illuminate\Support\Facades\Cache;

class CompanyCommercialInsights
{
    public function forCompany(Company $company): array
    {
        $usage = Cache::remember("superadmin:company:{$company->id}:commercial:v2", now()->addMinutes(5), function () use ($company) {
            $orders = Order::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id);
            $confirmed = (clone $orders)->whereIn('status', Order::CONFIRMED_STATUSES);
            $month = (clone $confirmed)->whereBetween('created_at', [now()->startOfMonth(), now()]);
            $recent = (clone $confirmed)->whereBetween('created_at', [now()->subDays(30), now()]);
            $previous = (clone $confirmed)->where('created_at', '>=', now()->subDays(60))->where('created_at', '<', now()->subDays(30));

            return [
                'month_orders' => (clone $month)->count(),
                'month_fees' => (float) (clone $month)->sum('fee'),
                'recent_orders' => (clone $recent)->count(),
                'recent_revenue' => (float) (clone $recent)->sum('total'),
                'previous_orders' => (clone $previous)->count(),
                'last_order_at' => (clone $confirmed)->max('created_at'),
                'pdv_orders' => (clone $recent)->where('order_type', 'pdv')->count(),
                'ifood_orders' => (clone $recent)->where('channel', 'ifood')->count(),
                'food99_orders' => (clone $recent)->where('channel', '99food')->count(),
                'tables' => RestaurantTable::where('company_id', $company->id)->where('active', true)->count(),
                'branches' => $company->branches()->withoutGlobalScope(CompanyScope::class)->count(),
                'updated_at' => now(),
            ];
        });

        $plan = $company->plan;
        $modules = collect(AddonModulePricing::MODULES)->map(fn (array $definition, string $key) => [
            'key' => $key,
            'label' => $definition['label'],
            'enabled' => (bool) $company->{$definition['column']},
            'price' => AddonModulePricing::price($key),
        ])->all();
        $monthlyTotal = ($plan?->monthlyPrice() ?? 0) + AddonModulePricing::extraFor($company)['amount'];
        $growth = $usage['previous_orders'] > 0
            ? round(($usage['recent_orders'] - $usage['previous_orders']) / $usage['previous_orders'] * 100, 1)
            : null;
        $limit = $plan?->maxOrdersPerMonth();
        $opportunities = [];
        $add = function (string $title, string $priority, string $reason, string $message, ?float $monthlyIncrease = null) use (&$opportunities, $company) {
            $opportunities[] = compact('title', 'priority', 'reason', 'monthlyIncrease') + [
                'message' => "Olá, equipe {$company->name}! {$message}",
            ];
        };

        if (! $company->active || $company->status !== 'ACTIVE') {
            $add('Retomar a operação', 'Prioridade', 'Empresa inativa ou com pendência de ativação/cobrança. Resolver a situação antes de oferecer expansão.', 'Podemos ajudar a revisar a ativação e as pendências da sua conta para retomar a operação na VEDDI?');
        } elseif ($company->pending_plan) {
            $add('Concluir mudança de plano', 'Prioridade', "Existe uma mudança pendente para {$company->pending_plan->label()}.", "Sua mudança para o plano {$company->pending_plan->label()} está pendente. Posso ajudar a conferir o que falta para concluir?");
        } elseif ($usage['recent_orders'] === 0) {
            $reason = $usage['last_order_at'] ? 'Nenhum pedido confirmado nos últimos 30 dias.' : 'A empresa ainda não registrou pedidos confirmados.';
            $add('Acompanhar ativação e uso', 'Prioridade', $reason.' Confirmar dificuldades e apoiar os primeiros resultados.', 'Como está o uso da VEDDI? Posso ajudar a revisar o cadastro, o cardápio e a operação para vocês começarem a receber pedidos?');
        } else {
            if ($plan === Plan::Free) {
                $target = Plan::Essencial;
                $increase = $target->monthlyPrice() - $plan->monthlyPrice();
                $reason = "{$usage['month_orders']} pedidos confirmados neste mês".($limit !== null ? " para uma faixa de {$limit} pedidos com a taxa inicial" : '').'.';
                $priority = $limit !== null && $usage['month_orders'] >= $limit * 0.8 ? 'Alta' : 'Explorar';
                $costComparison = '';
                if ($usage['month_fees'] >= $increase) {
                    $reason .= ' As taxas de plano já registradas no mês somam '.$this->money($usage['month_fees']).', atingindo o adicional mensal de '.$this->money($increase).' do Essencial.';
                    $costComparison = ' As taxas de plano registradas neste mês somam '.$this->money($usage['month_fees']).'; vale comparar esse custo com a mensalidade, considerando também a ativação e as taxas dos meios de pagamento.';
                    $priority = 'Alta';
                }
                $setup = $target->setupFee();
                $add('Oferecer plano Essencial', $priority, $reason, "Vocês já têm {$usage['month_orders']} pedidos confirmados neste mês. O Essencial custa ".$this->money($target->monthlyPrice()).'/mês, tem pedidos ilimitados e taxa de plano de '.$this->percentage($target->feePercentage()).' por pedido. A ativação na mudança do gratuito é de '.$this->money($setup).'.'.$costComparison.' Podemos avaliar se faz sentido para a operação?', $increase);
            } elseif ($plan === Plan::Essencial) {
                $aboveLimit = $usage['branches'] > $plan->maxBranches();
                $add('Explorar expansão com o PRO', $aboveLimit ? 'Alta' : 'Explorar', "{$usage['branches']} de {$plan->maxBranches()} filial(is) do plano em uso. Confirmar se há intenção de abrir novas unidades.", 'Vocês estão planejando novas unidades? O PRO permite até '.Plan::Pro->maxBranches().' filiais por '.$this->money(Plan::Pro->monthlyPrice()).'/mês. Podemos conversar sobre essa expansão?', Plan::Pro->monthlyPrice() - $plan->monthlyPrice());
            }

            if ($growth !== null && $growth >= 25) {
                $add('Conversar sobre o crescimento', 'Alta', "Pedidos confirmados cresceram {$growth}%: {$usage['previous_orders']} nos 30 dias anteriores e {$usage['recent_orders']} nos últimos 30 dias.", "O volume passou de {$usage['previous_orders']} para {$usage['recent_orders']} pedidos nos períodos de 30 dias. Como a equipe está lidando com esse crescimento? Podemos revisar os recursos que ajudam nessa rotina?");
            }

            foreach ($modules as $key => $module) {
                if ($module['enabled'] || ($key === 'waiter' && ! $company->pdv_module_enabled)) {
                    continue;
                }

                [$reason, $benefit, $priority] = match ($key) {
                    'pdv' => ['PDV não contratado. Confirmar se também há vendas no balcão ou atendimento presencial.', 'centralizar vendas de balcão, caixa e comandas', 'Explorar'],
                    'fiscal' => ["{$usage['recent_orders']} pedidos confirmados nos últimos 30 dias e módulo Fiscal não contratado. Confirmar como a emissão é feita hoje.", 'emitir documentos fiscais na rotina de vendas', 'Explorar'],
                    'waiter' => ["PDV ativo, {$usage['tables']} mesa(s) ativa(s) e {$usage['pdv_orders']} pedido(s) de PDV nos últimos 30 dias. Confirmar a rotina do salão.", 'registrar pedidos pelo garçom e organizar o atendimento das mesas', $usage['tables'] > 0 ? 'Alta' : 'Explorar'],
                };
                $add('Apresentar '.$module['label'], $priority, $reason, "Como vocês fazem para {$benefit} hoje? O {$module['label']} custa ".$this->money($module['price']).'/mês adicional. Posso apresentar como funciona e avaliar com vocês?', $module['price']);
            }
        }

        $priorityOrder = ['Prioridade' => 0, 'Alta' => 1, 'Explorar' => 2];
        usort($opportunities, fn (array $a, array $b) => $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']]);

        return compact('usage', 'modules', 'monthlyTotal', 'growth', 'limit', 'opportunities');
    }

    private function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    private function percentage(float $value): string
    {
        return number_format($value * 100, 2, ',', '.').'%';
    }
}
