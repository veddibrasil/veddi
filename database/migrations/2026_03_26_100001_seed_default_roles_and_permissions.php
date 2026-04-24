<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['name' => 'orders.view',         'group' => 'orders',     'label' => 'Visualizar pedidos'],
            ['name' => 'orders.update',        'group' => 'orders',     'label' => 'Atualizar status de pedidos'],
            ['name' => 'products.view',        'group' => 'products',   'label' => 'Visualizar produtos'],
            ['name' => 'products.create',      'group' => 'products',   'label' => 'Criar produto'],
            ['name' => 'products.update',      'group' => 'products',   'label' => 'Editar produto'],
            ['name' => 'products.delete',      'group' => 'products',   'label' => 'Remover produto'],
            ['name' => 'categories.view',      'group' => 'categories', 'label' => 'Visualizar categorias'],
            ['name' => 'categories.create',    'group' => 'categories', 'label' => 'Criar categoria'],
            ['name' => 'categories.update',    'group' => 'categories', 'label' => 'Editar categoria'],
            ['name' => 'categories.delete',    'group' => 'categories', 'label' => 'Remover categoria'],
            ['name' => 'branches.view',        'group' => 'branches',   'label' => 'Visualizar filiais'],
            ['name' => 'branches.create',      'group' => 'branches',   'label' => 'Criar filial'],
            ['name' => 'branches.update',      'group' => 'branches',   'label' => 'Editar filial'],
            ['name' => 'branches.delete',      'group' => 'branches',   'label' => 'Remover filial'],
            ['name' => 'company.settings',     'group' => 'company',    'label' => 'Configurações da empresa'],
            ['name' => 'users.view',           'group' => 'users',      'label' => 'Visualizar usuários'],
            ['name' => 'users.manage',         'group' => 'users',      'label' => 'Gerenciar usuários'],
            ['name' => 'roles.manage',         'group' => 'roles',      'label' => 'Gerenciar tipos de usuário'],
            ['name' => 'stock.view',           'group' => 'stock',      'label' => 'Visualizar estoque'],
            ['name' => 'stock.adjust',         'group' => 'stock',      'label' => 'Ajustar estoque manualmente'],
            ['name' => 'stock.toggle',         'group' => 'stock',      'label' => 'Habilitar/desabilitar rastreamento de estoque'],
            ['name' => 'coupons.view',         'group' => 'coupons',    'label' => 'Visualizar cupons'],
            ['name' => 'coupons.create',       'group' => 'coupons',    'label' => 'Criar cupom'],
            ['name' => 'coupons.update',       'group' => 'coupons',    'label' => 'Editar cupom'],
            ['name' => 'coupons.delete',       'group' => 'coupons',    'label' => 'Remover cupom'],
            ['name' => 'support.view',         'group' => 'support',    'label' => 'Visualizar tickets de suporte'],
            ['name' => 'support.reply',        'group' => 'support',    'label' => 'Responder e gerenciar tickets'],
        ];

        $now = now();

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $perm['name']],
                array_merge($perm, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $companyAdminPerms = [
            'orders.view', 'orders.update',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',
            'company.settings',
            'users.view', 'users.manage',
            'roles.manage',
            'stock.view', 'stock.adjust', 'stock.toggle',
            'coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete',
            'support.view', 'support.reply',
        ];

        $branchManagerPerms = [
            'orders.view', 'orders.update',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'branches.view',
            'stock.view', 'stock.adjust', 'stock.toggle',
            'support.view', 'support.reply',
        ];

        $roles = [
            ['name' => 'Administrador da Empresa', 'slug' => 'company_admin',   'perms' => $companyAdminPerms],
            ['name' => 'Gerente de Filial',         'slug' => 'branch_manager',  'perms' => $branchManagerPerms],
        ];

        foreach ($roles as $roleData) {
            $roleId = DB::table('roles')->where('slug', $roleData['slug'])->whereNull('company_id')->value('id');

            if (! $roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => $roleData['name'],
                    'slug' => $roleData['slug'],
                    'company_id' => null,
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permIds = DB::table('permissions')->whereIn('name', $roleData['perms'])->pluck('id');

            foreach ($permIds as $permId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('role_id', DB::table('roles')->whereNull('company_id')->pluck('id'))
            ->delete();

        DB::table('roles')->whereNull('company_id')->where('is_system', true)->delete();
        DB::table('permissions')->delete();
    }
};
