<?php

namespace App\Support\Fiscal;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;

/**
 * Links "Corrigir dados" das pendências fiscais. Tributação do produto só para quem pode
 * editar produtos (permissão products.update e papel com acesso ao cardápio, a mesma regra do
 * middleware da rota). Quem só emite vê a pendência e o cadastro a corrigir, sem link.
 */
class FiscalCorrectionLinks
{
    public static function canEditProducts(?User $user, ?Company $company): bool
    {
        if (! $user || ! $company) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (! $user->hasPermission('products.update', $company)) {
            return false;
        }
        $role = $user->roleForCompany($company);

        return in_array($role, ['company_admin', 'branch_manager'], true)
            || ($role && Role::where('slug', $role)->where('company_id', $company->id)->exists());
    }

    /** Edição do produto aberta no cadastro certo: perfil da filial ou cadastro geral. */
    public static function productUrl(array $product, string $returnTo): ?string
    {
        if (empty($product['product_id'])) {
            return null;
        }
        $anchor = ($product['source'] ?? 'general') === 'branch' && ! empty($product['branch_id'])
            ? 'fiscal-filial-'.$product['branch_id']
            : 'dados-fiscais';

        return route('admin.products.edit', ['product' => $product['product_id'], 'voltar' => $returnTo]).'#'.$anchor;
    }

    /** Só caminho interno do painel ("/admin/..."): nunca host externo nem esquema. */
    public static function safeReturnPath(mixed $path): ?string
    {
        if (! is_string($path) || ! str_starts_with($path, '/admin/') || str_contains($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) || strlen($path) > 255) {
            return null;
        }

        return $path;
    }
}
