<?php

namespace App\Services\Order;

use App\Models\Branch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ponto único de invalidação do cardápio em cache (chat público e PDV).
 *
 * O chat guarda o cardápio por filial por 5 min (`OrderChat::getMenuProperty`) e ele carrega
 * estoque/disponibilidade. Qualquer escrita que altere categoria, produto, opção ou o pivot
 * `branch_product` deve invalidar por aqui — senão o cliente vê produto esgotado como comprável.
 */
class MenuCache
{
    public const MENU_TTL_MINUTES = 5;

    public static function menuKey(int $branchId, int $companyId): string
    {
        return "menu:branch:{$branchId}:company:{$companyId}";
    }

    /**
     * Invalida o cardápio de uma filial. Quando a escrita acontece dentro de uma transação,
     * a invalidação espera o commit — senão outro request repopularia o cache com dados antigos.
     */
    public function forgetBranch(int $branchId, ?int $companyId = null): void
    {
        DB::afterCommit(function () use ($branchId, $companyId) {
            $companyId ??= Branch::withoutGlobalScopes()->whereKey($branchId)->value('company_id');

            if ($companyId !== null) {
                Cache::forget(self::menuKey($branchId, (int) $companyId));
            }

            Cache::forget("pdv:products:branch:{$branchId}");
            Cache::forget("pdv:categories:branch:{$branchId}");
        });
    }

    /**
     * Invalida o cardápio de todas as filiais da empresa (inclui filiais inativas/removidas).
     * `withoutGlobalScopes()` é explícito: a chamada pode vir de job/serviço fora do tenant.
     */
    public function forgetCompany(int $companyId): void
    {
        Branch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('id')
            ->each(fn ($branchId) => $this->forgetBranch((int) $branchId, $companyId));
    }
}
