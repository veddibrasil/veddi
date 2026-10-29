<?php

namespace App\Services\Marketing;

use App\DTOs\AudienceFilterData;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Público do e-mail marketing: usuários das empresas (company_admin/branch_manager) que passam
 * nos filtros, sem descadastro (opt-out), sem supressão e — se exigido — com aceite (opt-in).
 *
 * Consulta direta em company_user/companies/users (Query Builder): o módulo é da plataforma e
 * cruza empresas de propósito, então o CompanyScope não se aplica. Clientes finais dos
 * restaurantes (customers) nunca entram: os dados são de cada empresa.
 */
class AudienceResolver
{
    public function query(AudienceFilterData $filters, ?CarbonInterface $companyCreatedBefore = null): Builder
    {
        return DB::table('company_user')
            ->join('users', 'users.id', '=', 'company_user.user_id')
            ->join('companies', 'companies.id', '=', 'company_user.company_id')
            ->whereNull('users.deleted_at')
            ->whereIn('company_user.role', $filters->effectiveRoles())
            ->when($filters->plans !== [], fn (Builder $query) => $query->whereIn('companies.plan', $filters->plans))
            ->when($filters->companyStatuses !== [], fn (Builder $query) => $query->whereIn('companies.status', $filters->companyStatuses))
            ->when($filters->companyActive !== null, fn (Builder $query) => $query->where('companies.active', $filters->companyActive))
            ->when($filters->createdFrom, fn (Builder $query) => $query->where('companies.created_at', '>=', $filters->createdFrom.' 00:00:00'))
            ->when($filters->createdTo, fn (Builder $query) => $query->where('companies.created_at', '<=', $filters->createdTo.' 23:59:59'))
            ->when($companyCreatedBefore, fn (Builder $query) => $query->where('companies.created_at', '<=', $companyCreatedBefore))
            ->when($filters->pdvEnabled !== null, fn (Builder $query) => $query->where('companies.pdv_module_enabled', $filters->pdvEnabled))
            ->when($filters->fiscalEnabled !== null, fn (Builder $query) => $query->where('companies.fiscal_notes_enabled', $filters->fiscalEnabled))
            // "Sem pedidos há N dias" só vale para empresa que já existe há N dias: a recém-criada também não tem pedidos.
            ->when($filters->inactiveDays !== null, fn (Builder $query) => $query
                ->where('companies.created_at', '<=', now()->subDays($filters->inactiveDays))
                ->whereNotExists(fn (Builder $orders) => $orders->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.company_id', 'companies.id')
                    ->where('orders.created_at', '>=', now()->subDays($filters->inactiveDays))))
            ->when(config('marketing.require_opt_in'), fn (Builder $query) => $query->whereNotNull('users.marketing_opt_in_at'))
            ->whereNull('users.marketing_opt_out_at')
            ->whereNotExists(fn (Builder $suppressions) => $suppressions->selectRaw('1')
                ->from('email_suppressions')
                ->whereRaw('email_suppressions.email = LOWER(TRIM(users.email))'));
    }

    /** Pessoas únicas (o mesmo e-mail em duas empresas conta uma vez). */
    public function count(AudienceFilterData $filters): int
    {
        return (int) $this->query($filters)->selectRaw('COUNT(DISTINCT LOWER(TRIM(users.email))) as total')->value('total');
    }

    /** @return Collection<int, object> */
    public function sample(AudienceFilterData $filters, int $limit = 5): Collection
    {
        return $this->rows($this->query($filters))->limit($limit * 3)->get()->unique('email')->take($limit)->values();
    }

    /**
     * Percorre o público em blocos, já com uma linha por e-mail dentro do bloco
     * (a ordem por usuário mantém as empresas de uma pessoa no mesmo bloco).
     *
     * @param  callable(Collection<int, object>): (bool|void)  $callback  false interrompe
     */
    public function chunk(AudienceFilterData $filters, int $size, callable $callback, ?CarbonInterface $companyCreatedBefore = null): void
    {
        $this->rows($this->query($filters, $companyCreatedBefore))->chunk($size, function (Collection $rows) use ($callback) {
            return $callback($rows->unique(fn (object $row) => SuppressionService::normalize($row->email))->values());
        });
    }

    /** Variáveis congeladas para o destinatário (primeira empresa da pessoa). */
    public static function variablesFor(object $row): array
    {
        return TemplateRenderer::variablesFor($row->user_name, $row->company_name, $row->plan);
    }

    private function rows(Builder $query): Builder
    {
        return $query
            ->select([
                'users.id as user_id',
                'users.name as user_name',
                'users.email as email',
                'companies.id as company_id',
                'companies.name as company_name',
                'companies.plan as plan',
            ])
            ->orderBy('users.id')
            ->orderBy('companies.id');
    }
}
