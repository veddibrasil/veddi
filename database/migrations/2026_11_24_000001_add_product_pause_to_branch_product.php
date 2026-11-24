<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Papéis do sistema que operam a filial no dia a dia e podem tirar um item de venda. */
    private const ROLES = ['company_admin', 'branch_manager', 'caixa', 'cozinha', 'bar'];

    public function up(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            // Pausa manual (acabou o ingrediente, fritadeira parada). `available` continua sendo o
            // "dá para vender" que chat, PDV e marketplaces leem; isto só impede o estoque de
            // devolver o item à venda sozinho e diz quem pausou.
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'products.pause'],
            ['group' => 'products', 'label' => 'Pausar/retomar produto na filial', 'created_at' => $now, 'updated_at' => $now]
        );

        $permId = DB::table('permissions')->where('name', 'products.pause')->value('id');

        foreach (DB::table('roles')->whereNull('company_id')->whereIn('slug', self::ROLES)->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permId]);
        }
    }

    public function down(): void
    {
        // Item pausado volta a valer só o estoque, senão ficaria fora de venda sem motivo visível.
        DB::table('branch_product')->whereNotNull('paused_at')->where('track_stock', false)->update(['available' => true]);
        DB::table('branch_product')->whereNotNull('paused_at')->where('track_stock', true)->where('quantity', '>', 0)->update(['available' => true]);

        Schema::table('branch_product', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paused_by');
            $table->dropColumn('paused_at');
        });

        $permId = DB::table('permissions')->where('name', 'products.pause')->value('id');

        if ($permId) {
            DB::table('role_permissions')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
