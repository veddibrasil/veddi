<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'pdv.waiter_operate'],
            ['group' => 'pdv', 'label' => 'Operar PDV (garçom — mesas e comandas)', 'created_at' => $now, 'updated_at' => $now]
        );

        $roleId = DB::table('roles')->where('slug', 'garcom')->whereNull('company_id')->value('id');

        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'Garçom',
                'slug' => 'garcom',
                'company_id' => null,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permId = DB::table('permissions')->where('name', 'pdv.waiter_operate')->value('id');

        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $roleId, 'permission_id' => $permId]
        );
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'garcom')->whereNull('company_id')->value('id');

        if ($roleId) {
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        DB::table('permissions')->where('name', 'pdv.waiter_operate')->delete();
    }
};
