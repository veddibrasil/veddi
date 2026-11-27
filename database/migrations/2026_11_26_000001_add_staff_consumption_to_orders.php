<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Consumo de funcionário no PDV: o valor vira débito do funcionário, não receita da loja.
            // staff_user_id preenchido = pedido fora de faturamento, caixa, carteira e nota fiscal.
            // staff_settled_at = quando o débito foi acertado (descontado em folha ou pago).
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('staff_settled_at')->nullable();
            $table->index(['company_id', 'staff_user_id', 'staff_settled_at'], 'orders_staff_consumption_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_staff_consumption_idx');
            $table->dropConstrainedForeignId('staff_user_id');
            $table->dropColumn('staff_settled_at');
        });
    }
};
