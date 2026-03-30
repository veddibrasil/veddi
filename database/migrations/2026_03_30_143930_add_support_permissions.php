<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissions = [
            ['name' => 'support.view',  'group' => 'support', 'label' => 'Visualizar tickets de suporte'],
            ['name' => 'support.reply', 'group' => 'support', 'label' => 'Responder e gerenciar tickets'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $perm['name']],
                array_merge($perm, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $permIds = DB::table('permissions')
            ->whereIn('name', ['support.view', 'support.reply'])
            ->pluck('id');

        $roleSlugs = ['company_admin', 'branch_manager'];

        foreach ($roleSlugs as $slug) {
            $roleId = DB::table('roles')
                ->where('slug', $slug)
                ->whereNull('company_id')
                ->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($permIds as $permId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId]
                );
            }
        }
    }

    public function down(): void
    {
        $permIds = DB::table('permissions')
            ->whereIn('name', ['support.view', 'support.reply'])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('name', ['support.view', 'support.reply'])->delete();
    }
};
