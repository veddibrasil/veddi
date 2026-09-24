<?php

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Trilha de auditoria pra ações irreversíveis/sensíveis do super admin (mesmo canal
 * `discord` já usado pros alertas críticos de pagamento e WhatsApp — a equipe vê na hora).
 * Sem isso, escalonar privilégio, apagar empresa ou liberar plano pago sem cobrança
 * não deixava nenhum rastro de quem fez, quando e por quê.
 */
final class AuditLog
{
    public static function privilegeChanged(User $actor, User $target, bool $grantedSuperAdmin): void
    {
        Log::channel('discord')->warning(
            $grantedSuperAdmin ? 'Super Admin: privilégio CONCEDIDO' : 'Super Admin: privilégio REVOGADO',
            [
                'actor_id' => $actor->id,
                'actor_email' => $actor->email,
                'target_user_id' => $target->id,
                'target_email' => $target->email,
            ]
        );
    }

    public static function companyDeleted(User $actor, Company $company): void
    {
        Log::channel('discord')->critical('Super Admin: empresa EXCLUÍDA', [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'company_slug' => $company->slug,
        ]);
    }

    public static function paymentBypassed(User $actor, Company $company, string $plan): void
    {
        Log::channel('discord')->warning('Super Admin: ativação de plano pago SEM cobrança (bypass)', [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'plan' => $plan,
        ]);
    }
}
