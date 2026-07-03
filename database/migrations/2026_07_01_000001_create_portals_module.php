<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('external_merchant_id');
            $table->text('credentials')->nullable();
            $table->string('status')->default('disconnected');
            $table->string('active_interruption_id')->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_merchant_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel')->default('website')->after('order_type');
            $table->foreignId('portal_id')->nullable()->constrained()->nullOnDelete()->after('channel');
            $table->string('external_order_id')->nullable()->after('portal_id');
            $table->string('external_status')->nullable()->after('external_order_id');

            $table->unique(['portal_id', 'external_order_id']);
        });

        Schema::create('product_portal_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();
            $table->string('external_item_id');
            $table->timestamps();

            $table->unique(['portal_id', 'product_id']);
            $table->unique(['portal_id', 'external_item_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            // Módulo Portais é cobrado na mesma assinatura/fatura do plano (asaas_subscription_id),
            // igual ao módulo PDV — não há assinatura própria.
            $table->boolean('portals_module_enabled')->default(false)->after('pdv_module_enabled');
        });

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'portals.manage'],
            ['group' => 'portals', 'label' => 'Gerenciar integração com portais (iFood)', 'created_at' => $now, 'updated_at' => $now]
        );

        $roleId = DB::table('roles')->where('slug', 'company_admin')->whereNull('company_id')->value('id');

        if ($roleId) {
            $permId = DB::table('permissions')->where('name', 'portals.manage')->value('id');

            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId]
            );
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('name', 'portals.manage')->value('id');

        if ($permId) {
            DB::table('role_permissions')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('portals_module_enabled');
        });

        Schema::dropIfExists('product_portal_mappings');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['portal_id', 'external_order_id']);
            $table->dropConstrainedForeignId('portal_id');
            $table->dropColumn(['channel', 'external_order_id', 'external_status']);
        });

        Schema::dropIfExists('portals');
    }
};
