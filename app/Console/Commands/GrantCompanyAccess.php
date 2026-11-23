<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyAccessGranter;
use Illuminate\Console\Command;

/**
 * Operação interna: dá a um usuário já existente o vínculo de administrador em todas as empresas
 * (ou nas informadas), para suporte. Não cria usuário, não envia e-mail.
 */
class GrantCompanyAccess extends Command
{
    protected $signature = 'user:grant-company-access
        {email : E-mail do usuário que já existe}
        {--company=* : IDs das empresas (padrão: todas)}
        {--role=company_admin : Papel nas empresas novas (company_admin ou branch_manager)}
        {--dry-run : Só lista o que seria feito}';

    protected $description = 'Vincula um usuário existente como administrador de todas as empresas';

    public function handle(): int
    {
        $role = (string) $this->option('role');

        if (! in_array($role, CompanyAccessGranter::ROLES, true)) {
            $this->error('Papel inválido. Use company_admin ou branch_manager.');

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Usuário não encontrado. Ele precisa existir antes.');

            return self::FAILURE;
        }

        $companyIds = array_map('intval', (array) $this->option('company'));

        $companies = Company::query()
            ->when($companyIds !== [], fn ($q) => $q->whereIn('id', $companyIds))
            ->orderBy('id')
            ->get();

        if ($this->option('dry-run')) {
            $linked = $user->companies()->pluck('companies.id')->all();
            $pending = $companies->reject(fn (Company $c) => in_array($c->id, $linked, true));

            foreach ($pending as $company) {
                $this->line("  seria vinculado: #{$company->id} {$company->name} ({$role})");
            }
            $this->info("{$pending->count()} empresa(s) seriam vinculadas para {$user->email}.");

            return self::SUCCESS;
        }

        $added = CompanyAccessGranter::grant($user, $companies, $role);

        foreach ($added as $company) {
            $this->line("  vinculado: #{$company->id} {$company->name} ({$role})");
        }
        $this->info("{$added->count()} empresa(s) vinculadas para {$user->email}.");

        return self::SUCCESS;
    }
}
