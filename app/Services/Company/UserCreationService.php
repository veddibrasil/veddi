<?php

namespace App\Services\Company;

use App\Mail\WelcomeUser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UserCreationService
{
    private const BRANCH_SCOPED_ROLES = ['branch_manager', 'cozinha', 'caixa', 'garcom'];

    /**
     * Cria um novo usuário, vincula à empresa com o papel informado e envia o e-mail de boas-vindas.
     */
    public static function create(Company $company, string $name, string $email, string $role, ?int $branchId): User
    {
        $temporaryPassword = 'password';

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($temporaryPassword),
        ]);

        $user->companies()->attach($company->id, [
            'role' => $role,
            'branch_id' => in_array($role, self::BRANCH_SCOPED_ROLES) && $branchId
                ? $branchId
                : null,
        ]);

        UserPermissionService::assignRolePermissions($user, $company, $role);

        try {
            Mail::to($user->email)->send(new WelcomeUser($user, $company, $temporaryPassword));
        } catch (\Throwable $e) {
            Log::warning('Falha ao enviar email de boas-vindas ao novo usuário', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }
}
