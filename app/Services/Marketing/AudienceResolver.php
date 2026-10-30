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
 * O administrador de cada empresa filtrada sempre entra, mesmo com filtro de papel.
 *
 * O e-mail cadastrado da empresa (companies.email, opcional) soma ao público. É uma caixa sem
 * aceite próprio: com require_opt_in fica de fora, e nunca entra no lugar de quem tem conta.
 *
 * Consulta direta em company_user/companies/users (Query Builder): o módulo é da plataforma e
 * cruza empresas de propósito, então o CompanyScope não se aplica. Clientes finais dos
 * restaurantes (customers) nunca entram: os dados são de cada empresa.
 *
 * Listas de contatos podem somar os donos das empresas. Quem já tem conta só entra pelo público
 * de usuários, com as preferências atuais de consentimento; nunca pela cópia importada.
 */
class AudienceResolver
{
    public function query(AudienceFilterData $filters, ?CarbonInterface $companyCreatedBefore = null): Builder
    {
        if ($filters->isContacts()) {
            if ($filters->includeOwners) {
                $contacts = $this->contactsQuery($filters)->select([
                    DB::raw('NULL as user_id'),
                    'email_contacts.name as user_name',
                    'email_contacts.email as email',
                    DB::raw('NULL as company_id'),
                    'email_contacts.company_name as company_name',
                    DB::raw('NULL as plan'),
                    'email_contacts.id as contact_id',
                ]);
                $owners = $this->usersQuery($filters, $companyCreatedBefore, ['company_admin']);

                return DB::query()->fromSub($contacts->unionAll($owners), 'audience');
            }

            return $this->contactsQuery($filters);
        }

        $users = $this->usersQuery($filters, $companyCreatedBefore);

        if (! config('marketing.require_opt_in')) {
            $users->unionAll($this->companyEmailsQuery($filters, $companyCreatedBefore));
        }

        return DB::query()->fromSub($users, 'audience');
    }

    private function usersQuery(AudienceFilterData $filters, ?CarbonInterface $companyCreatedBefore = null, ?array $roles = null): Builder
    {
        $users = DB::table('company_user')
            ->join('users', 'users.id', '=', 'company_user.user_id')
            ->join('companies', 'companies.id', '=', 'company_user.company_id')
            ->whereNull('users.deleted_at')
            ->whereIn('company_user.role', $roles ?? $filters->effectiveRoles());

        return $this->applyCompanyFilters($users, $filters, $companyCreatedBefore)
            ->when(config('marketing.require_opt_in'), fn (Builder $query) => $query->whereNotNull('users.marketing_opt_in_at'))
            ->whereNull('users.marketing_opt_out_at')
            ->whereNotExists(fn (Builder $suppressions) => $suppressions->selectRaw('1')
                ->from('email_suppressions')
                ->whereRaw('email_suppressions.email = LOWER(TRIM(users.email))'))
            ->select([
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('LOWER(TRIM(users.email)) as email'),
                'companies.id as company_id',
                'companies.name as company_name',
                'companies.plan as plan',
                DB::raw('NULL as contact_id'),
            ]);
    }

    /** E-mail da própria empresa. A saudação usa o nome do primeiro administrador, que lê essa caixa. */
    private function companyEmailsQuery(AudienceFilterData $filters, ?CarbonInterface $companyCreatedBefore = null): Builder
    {
        return $this->applyCompanyFilters(DB::table('companies'), $filters, $companyCreatedBefore)
            ->whereRaw("TRIM(COALESCE(companies.email, '')) <> ''")
            // Quem tem conta recebe pelo público de usuários, com o consentimento e o descadastro de lá.
            ->whereNotExists(fn (Builder $users) => $users->selectRaw('1')
                ->from('users')
                ->whereRaw('LOWER(TRIM(users.email)) = LOWER(TRIM(companies.email))'))
            ->whereNotExists(fn (Builder $suppressions) => $suppressions->selectRaw('1')
                ->from('email_suppressions')
                ->whereRaw('email_suppressions.email = LOWER(TRIM(companies.email))'))
            ->select([
                DB::raw('NULL as user_id'),
                DB::raw("COALESCE((
                    SELECT users.name FROM company_user
                    INNER JOIN users ON users.id = company_user.user_id
                    WHERE company_user.company_id = companies.id
                        AND company_user.role = 'company_admin'
                        AND users.deleted_at IS NULL
                    ORDER BY users.id LIMIT 1
                ), companies.name) as user_name"),
                DB::raw('LOWER(TRIM(companies.email)) as email'),
                'companies.id as company_id',
                'companies.name as company_name',
                'companies.plan as plan',
                DB::raw('NULL as contact_id'),
            ]);
    }

    private function applyCompanyFilters(Builder $companies, AudienceFilterData $filters, ?CarbonInterface $companyCreatedBefore = null): Builder
    {
        return $companies
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
                    ->where('orders.created_at', '>=', now()->subDays($filters->inactiveDays))));
    }

    /** Pessoas únicas (o mesmo e-mail em duas empresas, ou em duas listas, conta uma vez). */
    public function count(AudienceFilterData $filters): int
    {
        // Contato já é gravado normalizado na importação.
        $email = $this->onlyContacts($filters) ? 'email_contacts.email' : 'audience.email';

        return (int) $this->query($filters)->selectRaw("COUNT(DISTINCT {$email}) as total")->value('total');
    }

    /** @return Collection<int, object> */
    public function sample(AudienceFilterData $filters, int $limit = 5): Collection
    {
        return $this->rows($this->query($filters), $filters)->limit($limit * 3)->get()->unique('email')->take($limit)->values();
    }

    /**
     * Percorre o público em blocos, já com uma linha por e-mail dentro do bloco
     * (a ordem por usuário mantém as empresas de uma pessoa no mesmo bloco).
     *
     * @param  callable(Collection<int, object>): (bool|void)  $callback  false interrompe
     */
    public function chunk(AudienceFilterData $filters, int $size, callable $callback, ?CarbonInterface $companyCreatedBefore = null): void
    {
        $this->rows($this->query($filters, $companyCreatedBefore), $filters)->chunk($size, function (Collection $rows) use ($callback) {
            return $callback($rows->unique(fn (object $row) => SuppressionService::normalize($row->email))->values());
        });
    }

    /** Variáveis congeladas para o destinatário (primeira empresa da pessoa; contato não tem plano). */
    public static function variablesFor(object $row): array
    {
        return TemplateRenderer::variablesFor($row->user_name, $row->company_name, $row->plan ?? null);
    }

    private function contactsQuery(AudienceFilterData $filters): Builder
    {
        return DB::table('email_contacts')
            ->whereIn('email_contacts.list_id', $filters->contactLists)
            // Quem já tem conta não é possível cliente: recebe pelo público de usuários, com o
            // consentimento e o descadastro de lá.
            ->whereNotExists(fn (Builder $users) => $users->selectRaw('1')
                ->from('users')
                ->whereRaw('LOWER(TRIM(users.email)) = email_contacts.email'))
            ->whereNotExists(fn (Builder $suppressions) => $suppressions->selectRaw('1')
                ->from('email_suppressions')
                ->whereColumn('email_suppressions.email', 'email_contacts.email'));
    }

    private function rows(Builder $query, AudienceFilterData $filters): Builder
    {
        if ($this->onlyContacts($filters)) {
            return $query
                ->select([
                    'email_contacts.id as contact_id',
                    'email_contacts.name as user_name',
                    'email_contacts.email as email',
                    'email_contacts.company_name as company_name',
                ])
                ->orderBy('email_contacts.id');
        }

        return $query->select('audience.*')->orderBy('user_id')->orderBy('company_id')->orderBy('contact_id');
    }

    private function onlyContacts(AudienceFilterData $filters): bool
    {
        return $filters->isContacts() && ! $filters->includeOwners;
    }
}
