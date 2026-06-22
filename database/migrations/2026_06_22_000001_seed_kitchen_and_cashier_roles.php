<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // pdv.operate pode não existir ainda (só era criado via PermissionSeeder)
        DB::table('permissions')->updateOrInsert(
            ['name' => 'pdv.operate'],
            ['group' => 'pdv', 'label' => 'Operar terminal PDV', 'created_at' => $now, 'updated_at' => $now]
        );

        $roles = [
            ['name' => 'Cozinha', 'slug' => 'cozinha', 'perms' => ['orders.view', 'orders.update']],
            ['name' => 'Caixa', 'slug' => 'caixa', 'perms' => ['orders.view', 'orders.update', 'pdv.operate']],
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
        $roleIds = DB::table('roles')->whereIn('slug', ['cozinha', 'caixa'])->whereNull('company_id')->pluck('id');

        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
    }
};
