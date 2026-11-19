<?php

namespace App\Services\Fiscal;

use App\DTOs\FiscalAutoIssueVerdict;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;
use App\Models\Order;

/**
 * Quando um pedido recebe NFC-e sem ninguém pedir. Única fonte da regra: o observer do pedido
 * e do pagamento, a varredura (fiscal:auto-issue) e o job AutoIssueFiscalNote perguntam aqui.
 *
 * Emite só quando tudo vale:
 * - filial com configuração fiscal habilitada e "Emitir NFC-e automaticamente" ligada, sem o
 *   disjuntor aberto, e pedido criado depois de ligar (activeConfigFor);
 * - venda do PDV paga na hora não: o caixa decide na tela (orders.fiscal_auto_skip);
 * - pedido do chat ou do PDV. Marketplace (iFood, 99Food, Keeta) fica de fora até a contabilidade
 *   confirmar forma de pagamento e intermediador da NFC-e: segue pelo Controle de notas;
 * - pedido aceito pela loja e com pagamento recebido. Chat e iFood ficam 'paid' antes do aceite;
 *   "a receber" do PDV e "pagar na entrega" do chat avançam de etapa antes de receber;
 * - nenhuma nota tentada antes (falha volta pelo Controle, nunca reemite sozinha);
 * - FiscalEmissionPolicy decide NFC-e. CNPJ (NF-e) ou documento a conferir ficam no Controle.
 * - valor maior que zero em todos os itens: brinde sem valor precisa da contabilidade (CFOP de
 *   bonificação) e é emitido pelo Controle.
 */
class FiscalAutoIssuePolicy
{
    public function __construct(
        private readonly FiscalConfigResolver $resolver,
        private readonly FiscalEmissionPolicy $emissionPolicy,
    ) {}

    /**
     * Configuração da filial com a emissão automática valendo para este pedido; null = não vale
     * (módulo ou chave desligados, disjuntor aberto, pedido criado antes de ligar).
     */
    public function activeConfigFor(Order $order): ?CompanyFiscalConfig
    {
        // Portão de uma consulta antes de carregar empresa e filial: roda em todo save de pedido
        // e a maioria das filiais não liga a emissão automática.
        $switchedOn = CompanyFiscalConfig::where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)
            ->where('enabled', true)
            ->where('auto_issue_enabled', true)
            ->whereNull('auto_issue_paused_at')
            ->exists();
        if (! $switchedOn) {
            return null;
        }

        // Sem loadMissing: chamado de dentro do save (observer), não pode pendurar relação no
        // pedido de quem está gravando.
        $company = $order->relationLoaded('company') ? $order->company : Company::find($order->company_id);
        if (! $company?->canUseFiscalNotes()) {
            return null;
        }

        $config = $this->resolver->forBranch($company, $order->branch_id);
        if (! $config?->autoIssueActive()) {
            return null;
        }

        // Pedido anterior à ativação nunca sai sozinho (ligar não emite o histórico).
        return $config->auto_issue_enabled_at && $order->created_at?->lt($config->auto_issue_enabled_at) ? null : $config;
    }

    /**
     * Filtro sem consulta antes de enfileirar: pedido na etapa certa e sem esperar aceite. O
     * dispatcher confere a filial e o pagamento depois; esperando aceite ou pagamento não
     * enfileira, porque o aceite/pagamento grava o pedido (ou o Payment) de novo e volta aqui.
     */
    public static function candidate(Order $order): bool
    {
        return $order->fiscal_auto_attempted_at === null
            && ! $order->fiscal_auto_skip
            && ! $order->is_open_tab
            && ! $order->isMarketplaceOrder()
            && in_array($order->status, Order::FISCAL_ISSUABLE_STATUSES, true)
            && ! $order->waitsForAcceptance();
    }

    public function evaluate(Order $order): FiscalAutoIssueVerdict
    {
        if ($order->fiscal_auto_attempted_at !== null) {
            return FiscalAutoIssueVerdict::off('Emissão automática já tratou o pedido.');
        }
        if ($order->isMarketplaceOrder()) {
            return FiscalAutoIssueVerdict::off('Pedido de marketplace: emitir pelo Controle de notas.');
        }

        if (! $this->activeConfigFor($order)) {
            return FiscalAutoIssueVerdict::off('Emissão automática desligada na filial ou pedido anterior à ativação.');
        }

        if ($order->fiscal_auto_skip) {
            return FiscalAutoIssueVerdict::skip('O PDV decidiu a nota na venda (emitiu na hora ou o caixa desmarcou).');
        }
        if ($order->isClosed()) {
            return FiscalAutoIssueVerdict::skip('Pedido cancelado.');
        }
        if ($order->is_open_tab || ! in_array($order->status, Order::FISCAL_ISSUABLE_STATUSES, true)) {
            return FiscalAutoIssueVerdict::wait('Pedido ainda não confirmado.');
        }
        if ($order->waitsForAcceptance()) {
            return FiscalAutoIssueVerdict::wait('Aguardando aceite da loja.');
        }
        if ($order->needsPdvPaymentConfirmation()) {
            return FiscalAutoIssueVerdict::wait('Aguardando o recebimento do pagamento.');
        }

        if (FiscalNote::withoutGlobalScopes()->where('company_id', $order->company_id)->where('order_id', $order->id)->exists()) {
            return FiscalAutoIssueVerdict::skip('Pedido já tem nota fiscal.');
        }
        if ((float) $order->total <= 0) {
            return FiscalAutoIssueVerdict::skip('Pedido sem valor.');
        }
        $order->loadMissing('items');
        if ($order->items->isEmpty()) {
            return FiscalAutoIssueVerdict::wait('Pedido ainda sem itens gravados.');
        }
        if ($order->items->contains(fn ($item) => (float) $item->subtotal <= 0 || $item->quantity <= 0)) {
            return FiscalAutoIssueVerdict::skip('Item sem valor (brinde): confira com a contabilidade e emita pelo Controle de notas.');
        }

        $decision = $this->emissionPolicy->forOrder($order);
        if ($decision->model !== '65') {
            return FiscalAutoIssueVerdict::skip($decision->model === '55'
                ? 'NF-e (CNPJ do comprador): emitir pelo Controle de notas.'
                : implode(' ', $decision->reasons));
        }

        return FiscalAutoIssueVerdict::issue();
    }
}
