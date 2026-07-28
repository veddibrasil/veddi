<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $roleId = DB::table('roles')->where('slug', 'bar')->whereNull('company_id')->value('id');

        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'Bar',
                'slug' => 'bar',
                'company_id' => null,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permIds = DB::table('permissions')->whereIn('name', ['orders.view', 'orders.update'])->pluck('id');

        foreach ($permIds as $permId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId]
            );
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'bar')->whereNull('company_id')->value('id');

        if ($roleId) {
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
