<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Pedidos
            ['name' => 'orders.view',   'group' => 'orders', 'label' => 'Visualizar pedidos'],
            ['name' => 'orders.update', 'group' => 'orders', 'label' => 'Atualizar status de pedidos'],
            ['name' => 'orders.report', 'group' => 'orders', 'label' => 'Visualizar relatório de pedidos'],

            // Produtos
            ['name' => 'products.view',   'group' => 'products', 'label' => 'Visualizar produtos'],
            ['name' => 'products.create', 'group' => 'products', 'label' => 'Criar produto'],
            ['name' => 'products.update', 'group' => 'products', 'label' => 'Editar produto'],
            ['name' => 'products.delete', 'group' => 'products', 'label' => 'Remover produto'],

            // Categorias
            ['name' => 'categories.view',   'group' => 'categories', 'label' => 'Visualizar categorias'],
            ['name' => 'categories.create', 'group' => 'categories', 'label' => 'Criar categoria'],
            ['name' => 'categories.update', 'group' => 'categories', 'label' => 'Editar categoria'],
            ['name' => 'categories.delete', 'group' => 'categories', 'label' => 'Remover categoria'],

            // Filiais
            ['name' => 'branches.view',   'group' => 'branches', 'label' => 'Visualizar filiais'],
            ['name' => 'branches.create', 'group' => 'branches', 'label' => 'Criar filial'],
            ['name' => 'branches.update', 'group' => 'branches', 'label' => 'Editar filial'],
            ['name' => 'branches.delete', 'group' => 'branches', 'label' => 'Remover filial'],

            // Empresa
            ['name' => 'company.settings', 'group' => 'company', 'label' => 'Configurações da empresa'],

            // Usuários
            ['name' => 'users.view',   'group' => 'users', 'label' => 'Visualizar usuários'],
            ['name' => 'users.manage', 'group' => 'users', 'label' => 'Gerenciar usuários'],

            // Roles (tipos de usuário)
            ['name' => 'roles.manage', 'group' => 'roles', 'label' => 'Gerenciar tipos de usuário'],

            // Estoque
            ['name' => 'stock.view',   'group' => 'stock', 'label' => 'Visualizar estoque'],
            ['name' => 'stock.adjust', 'group' => 'stock', 'label' => 'Ajustar estoque manualmente'],
            ['name' => 'stock.toggle', 'group' => 'stock', 'label' => 'Habilitar/desabilitar rastreamento de estoque'],

            // Cupons
            ['name' => 'coupons.view',   'group' => 'coupons', 'label' => 'Visualizar cupons'],
            ['name' => 'coupons.create', 'group' => 'coupons', 'label' => 'Criar cupom'],
            ['name' => 'coupons.update', 'group' => 'coupons', 'label' => 'Editar cupom'],
            ['name' => 'coupons.delete', 'group' => 'coupons', 'label' => 'Remover cupom'],

            // PDV
            ['name' => 'pdv.operate', 'group' => 'pdv', 'label' => 'Operar terminal PDV'],

            // Portais
            ['name' => 'portals.manage', 'group' => 'portals', 'label' => 'Gerenciar integração com portais (iFood)'],

            // Fiscal
            ['name' => 'fiscal.view',     'group' => 'fiscal', 'label' => 'Visualizar notas fiscais'],
            ['name' => 'fiscal.issue',    'group' => 'fiscal', 'label' => 'Emitir/cancelar notas fiscais'],
            ['name' => 'fiscal.settings', 'group' => 'fiscal', 'label' => 'Configurações fiscais'],
        ];

        foreach ($permissions as $data) {
            Permission::firstOrCreate(['name' => $data['name']], $data);
        }

        $companyAdminPermissions = [
            'orders.view', 'orders.update', 'orders.report',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',
            'company.settings',
            'users.view', 'users.manage',
            'roles.manage',
            'stock.view', 'stock.adjust', 'stock.toggle',
            'coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete',
            'pdv.operate',
            'portals.manage',
            'fiscal.view', 'fiscal.issue', 'fiscal.settings',
        ];

        $branchManagerPermissions = [
            'orders.view', 'orders.update', 'orders.report',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'branches.view',
            'stock.view', 'stock.adjust', 'stock.toggle',
            'fiscal.view',
        ];

        $roles = [
            [
                'name' => 'Administrador da Empresa',
                'slug' => 'company_admin',
                'company_id' => null,
                'is_system' => true,
                'permissions' => $companyAdminPermissions,
            ],
            [
                'name' => 'Gerente de Filial',
                'slug' => 'branch_manager',
                'company_id' => null,
                'is_system' => true,
                'permissions' => $branchManagerPermissions,
            ],
            [
                'name' => 'Cozinha',
                'slug' => 'cozinha',
                'company_id' => null,
                'is_system' => true,
                'permissions' => ['orders.view', 'orders.update'],
            ],
            [
                'name' => 'Caixa',
                'slug' => 'caixa',
                'company_id' => null,
                'is_system' => true,
                'permissions' => ['orders.view', 'orders.update', 'pdv.operate'],
            ],
        ];

        foreach ($roles as $roleData) {
            $perms = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::firstOrCreate(
                ['slug' => $roleData['slug'], 'company_id' => null],
                $roleData
            );

            $permissionIds = Permission::whereIn('name', $perms)->pluck('id');
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }
}
