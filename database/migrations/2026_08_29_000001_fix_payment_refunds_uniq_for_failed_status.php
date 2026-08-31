<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // refunds_payment_amount_status_uniq é o índice que sustenta a FK de payment_id
        // (payment_id é a coluna mais à esquerda dele). Precisa de outro índice cobrindo
        // payment_id antes de poder dropar o uniq, senão o MySQL recusa (erro 1553).
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->index('payment_id', 'refunds_payment_id_idx');
        });

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropUnique('refunds_payment_amount_status_uniq');
        });

        // Idempotency only matters while a refund is still "active" (requested/in_progress/succeeded).
        // Multiple failed attempts for the same payment+amount are legitimate (retries after a rejection).
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->string('active_dedupe_key', 60)
                ->virtualAs("CASE WHEN status IN ('requested', 'in_progress', 'succeeded') THEN CONCAT(payment_id, '-', amount, '-', status) ELSE NULL END")
                ->nullable();
        });

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->unique('active_dedupe_key', 'refunds_active_dedupe_key_uniq');
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropUnique('refunds_active_dedupe_key_uniq');
            $table->dropColumn('active_dedupe_key');
        });

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->unique(['payment_id', 'amount', 'status'], 'refunds_payment_amount_status_uniq');
        });

        // Composto acima já cobre payment_id de novo — o índice auxiliar fica redundante.
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropIndex('refunds_payment_id_idx');
        });
    }
};
