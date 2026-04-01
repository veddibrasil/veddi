<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Valor original do pedido (antes das taxas de cartão)
            $table->decimal('original_amount', 10, 2)->nullable()->after('amount');
            // Valor absoluto da taxa de cartão cobrada ao cliente
            $table->decimal('card_fee', 10, 2)->nullable()->after('original_amount');
            // Taxa total aplicada (cartão + antecipação + sistema)
            $table->decimal('card_fee_rate', 8, 6)->nullable()->after('card_fee');
            // Número de parcelas
            $table->tinyInteger('installments')->nullable()->after('card_fee_rate');
            // Prazo de antecipação utilizado (2, 7, 15 ou 30 dias)
            $table->tinyInteger('anticipation_days')->nullable()->after('installments');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'original_amount',
                'card_fee',
                'card_fee_rate',
                'installments',
                'anticipation_days',
            ]);
        });
    }
};
