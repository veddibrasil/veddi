<?php

namespace App\Services\Company;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Preço mensal dos módulos adicionais (PDV, Fiscal, Garçom) cobrados na mesma
 * fatura da assinatura do plano. Fonte única para a tela de assinatura e para o
 * CreateAsaasSubscription — o valor sempre vem do config, nunca do cliente.
 */
class AddonModulePricing
{
    /** módulo => [coluna em companies, chave de config, rótulo na fatura] */
    public const MODULES = [
        'pdv' => ['column' => 'pdv_module_enabled', 'config' => 'pdv.addon_monthly_price', 'default' => 99.00, 'label' => 'Módulo PDV'],
        'fiscal' => ['column' => 'fiscal_notes_enabled', 'config' => 'fiscal.addon_monthly_price', 'default' => 149.00, 'label' => 'Módulo Fiscal'],
        'waiter' => ['column' => 'waiter_module_enabled', 'config' => 'waiter.addon_monthly_price', 'default' => 99.00, 'label' => 'Módulo Garçom'],
    ];

    public static function price(string $module): float
    {
        $definition = self::MODULES[$module];

        return (float) config($definition['config'], $definition['default']);
    }

    /**
     * Módulos ativos da empresa, com sobrescritas para simular a fatura depois
     * de ativar (true) ou cancelar (false) algum deles.
     *
     * @param  array<string, bool>  $overrides
     * @return array<string, bool>
     */
    public static function enabledModules(Company $company, array $overrides = []): array
    {
        $enabled = [];

        foreach (self::MODULES as $module => $definition) {
            $enabled[$module] = $overrides[$module] ?? (bool) $company->{$definition['column']};
        }

        return $enabled;
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array{amount: float, description: string}
     */
    public static function extraFor(Company $company, array $overrides = []): array
    {
        $amount = 0.0;
        $parts = [];

        foreach (self::enabledModules($company, $overrides) as $module => $enabled) {
            if ($enabled) {
                $amount += self::price($module);
                $parts[] = self::MODULES[$module]['label'];
            }
        }

        return ['amount' => $amount, 'description' => implode(' + ', $parts)];
    }

    /**
     * Valor de uma mensalidade proporcional aos dias que faltam até o próximo
     * vencimento da assinatura (o ciclo é o mês que termina nesse vencimento).
     */
    public static function prorate(float $monthlyAmount, CarbonInterface $today, CarbonInterface $nextDueDate): float
    {
        $dueDate = CarbonImmutable::instance($nextDueDate)->startOfDay();
        $cycleDays = max(1, (int) round($dueDate->subMonthNoOverflow()->diffInDays($dueDate)));
        $remainingDays = (int) round(CarbonImmutable::instance($today)->startOfDay()->diffInDays($dueDate, false));
        $remainingDays = max(0, min($cycleDays, $remainingDays));

        return round($monthlyAmount * $remainingDays / $cycleDays, 2);
    }
}
