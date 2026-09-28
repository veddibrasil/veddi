<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda no pagamento a divisão calculada na criação da cobrança (a mesma enviada ao
     * gateway no split), para carteira e transações não recalcularem com outra regra.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('platform_fee', 10, 2)->nullable()->after('card_fee_rate');
            $table->decimal('company_net_amount', 10, 2)->nullable()->after('platform_fee');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['platform_fee', 'company_net_amount']);
        });
    }
};
