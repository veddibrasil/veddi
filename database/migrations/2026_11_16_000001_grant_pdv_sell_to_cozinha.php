<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'pdv.sell'],
            ['group' => 'pdv', 'label' => 'Venda direta no PDV (sem relatórios nem fechamento)', 'created_at' => $now, 'updated_at' => $now]
        );

        $permId = DB::table('permissions')->where('name', 'pdv.sell')->value('id');

        $roleId = DB::table('roles')->where('slug', 'cozinha')->whereNull('company_id')->value('id');

        if ($roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId]
            );
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('name', 'pdv.sell')->value('id');

        if ($permId) {
            DB::table('role_permissions')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
