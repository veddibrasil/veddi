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
            ['name' => 'orders.view',   'group' => 'orders',     'label' => 'Visualizar pedidos'],
            ['name' => 'orders.update', 'group' => 'orders',     'label' => 'Atualizar status de pedidos'],

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
        ];

        foreach ($permissions as $data) {
            Permission::firstOrCreate(['name' => $data['name']], $data);
        }

        $companyAdminPermissions = [
            'orders.view', 'orders.update',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',
            'company.settings',
            'users.view', 'users.manage',
            'roles.manage',
            'stock.view', 'stock.adjust', 'stock.toggle',
        ];

        $branchManagerPermissions = [
            'orders.view', 'orders.update',
            'products.view',
            'categories.view',
            'branches.view',
            'stock.view', 'stock.adjust',
        ];

        $roles = [
            [
                'name'        => 'Administrador da Empresa',
                'slug'        => 'company_admin',
                'company_id'  => null,
                'is_system'   => true,
                'permissions' => $companyAdminPermissions,
            ],
            [
                'name'        => 'Gerente de Filial',
                'slug'        => 'branch_manager',
                'company_id'  => null,
                'is_system'   => true,
                'permissions' => $branchManagerPermissions,
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
