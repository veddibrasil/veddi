<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emissão automática de NFC-e por filial (App\Services\Fiscal\FiscalAutoIssuePolicy):
 * - company_fiscal_configs.auto_issue_enabled: liga a emissão automática na filial (desligada por padrão).
 * - company_fiscal_configs.auto_issue_enabled_at: pedidos criados antes de ligar nunca saem sozinhos.
 * - company_fiscal_configs.auto_issue_paused_at/_reason: disjuntor depois de rejeições seguidas.
 * - company_fiscal_configs.auto_issue_resumed_at: retomada do disjuntor; rejeições anteriores não contam mais.
 * - orders.fiscal_auto_attempted_at: a emissão automática já tratou o pedido (uma vez só).
 * - orders.fiscal_auto_skip: o PDV decidiu a nota na venda (emitiu na hora ou o caixa desmarcou).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->boolean('auto_issue_enabled')->default(false)->after('nfce_cnpj_enabled');
            $table->timestamp('auto_issue_enabled_at')->nullable()->after('auto_issue_enabled');
            $table->timestamp('auto_issue_paused_at')->nullable()->after('auto_issue_enabled_at');
            $table->string('auto_issue_pause_reason', 255)->nullable()->after('auto_issue_paused_at');
            $table->timestamp('auto_issue_resumed_at')->nullable()->after('auto_issue_pause_reason');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('fiscal_auto_attempted_at')->nullable()->after('fiscal_document_preference');
            $table->boolean('fiscal_auto_skip')->default(false)->after('fiscal_auto_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fiscal_auto_attempted_at', 'fiscal_auto_skip']);
        });

        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn(['auto_issue_enabled', 'auto_issue_enabled_at', 'auto_issue_paused_at', 'auto_issue_pause_reason', 'auto_issue_resumed_at']);
        });
    }
};
