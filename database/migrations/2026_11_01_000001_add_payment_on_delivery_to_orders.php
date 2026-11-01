<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cliente do chat que paga ao receber: `payment_method` guarda como ele vai pagar, e sozinho
     * não separa PIX online (a Vindi confirma) de PIX na entrega (a loja confirma).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('payment_on_delivery')->default(false)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_on_delivery');
        });
    }
};
