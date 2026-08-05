<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma coluna por estação (não um timestamp único): addOrIncrementItem() funde
     * quantidade nova na MESMA linha de order_items quando o produto+opções já
     * existe no pedido — sem contador por estação não dá pra saber quanto daquela
     * linha já foi mandado pra cozinha/bar e quanto é lançamento novo.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('kitchen_sent_quantity')->default(0)->after('options');
            $table->unsignedInteger('bar_sent_quantity')->default(0)->after('kitchen_sent_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['kitchen_sent_quantity', 'bar_sent_quantity']);
        });
    }
};
