<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            // Taxa de cartão à vista (tudo cobrado à vista / 1x)
            $table->decimal('card_rate_1x', 5, 4)->default(0.0299);

            // Taxas de antecipação por prazo de recebimento
            $table->decimal('anticipation_rate_d2',  5, 4)->default(0.0299);
            $table->decimal('anticipation_rate_d7',  5, 4)->default(0.0249);
            $table->decimal('anticipation_rate_d15', 5, 4)->default(0.0199);
            $table->decimal('anticipation_rate_d30', 5, 4)->default(0.0000);

            // Margem adicional do sistema (percentual sobre o valor do pedido)
            $table->decimal('system_fee_rate', 5, 4)->default(0.0000);

            // Prazo de antecipação padrão para cartão (dias: 2, 7, 15, 30)
            $table->unsignedTinyInteger('default_anticipation_days')->default(15);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
