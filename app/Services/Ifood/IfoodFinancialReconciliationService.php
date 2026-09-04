<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Models\CompanyWalletEntry;
use App\Models\IfoodIntegration;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repasse do iFood já vem líquido de comissão — diferente da carteira Vindi/Asaas,
 * a "credit" aqui é o netAmount que o iFood efetivamente repassa, não o total bruto
 * do pedido. A taxa da própria plataforma Veddi (order->fee, calculada na criação
 * do pedido via FeeCalculator) é debitada à parte, igual a qualquer outro canal.
 *
 * Formato de settlement especulativo (Financial API do iFood não confirmada em
 * sandbox ainda) — ver IfoodGatewayService::getSettlements. Ajustar campos abaixo
 * quando o payload real for confirmado (Fase 8).
 */
class IfoodFinancialReconciliationService
{
    public function __construct(private readonly IfoodGatewayContract $gateway) {}

    public function reconcile(IfoodIntegration $integration, CarbonInterface $from, CarbonInterface $to): void
    {
        $settlements = $this->gateway->getSettlements($integration, $from, $to);

        foreach ($settlements as $settlement) {
            try {
                $this->reconcileOne($integration, $settlement);
            } catch (\Throwable $e) {
                Log::channel('ifood')->error('iFood: falha ao conciliar settlement, seguindo para os demais', [
                    'ifood_integration_id' => $integration->id,
                    'settlement' => $settlement,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function reconcileOne(IfoodIntegration $integration, array $settlement): void
    {
        $settlementId = $settlement['id'] ?? null;
        $ifoodOrderId = $settlement['orderId'] ?? null;
        $type = $settlement['type'] ?? 'ORDER';
        $netAmount = (float) ($settlement['netAmount'] ?? 0.0);

        if (! $settlementId || ! $ifoodOrderId) {
            Log::channel('ifood')->warning('iFood: settlement sem id ou orderId, ignorado', ['settlement' => $settlement]);

            return;
        }

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('external_order_id', $ifoodOrderId)
            ->first();

        if (! $order) {
            Log::channel('ifood')->warning('iFood: settlement referencia pedido não encontrado', [
                'ifood_order_id' => $ifoodOrderId,
                'settlement_id' => $settlementId,
            ]);

            return;
        }

        $entryType = $type === 'CANCELLATION' ? 'refund' : 'credit';

        $alreadyReconciled = false;

        DB::transaction(function () use ($integration, $order, $settlementId, $netAmount, $entryType, &$alreadyReconciled) {
            if (CompanyWalletEntry::where('order_id', $order->id)->where('reference', $settlementId)->where('type', $entryType)->lockForUpdate()->exists()) {
                $alreadyReconciled = true;

                return;
            }

            CompanyWalletEntry::create([
                'company_id' => $integration->company_id,
                'order_id' => $order->id,
                'type' => $entryType,
                'amount' => abs($netAmount),
                'description' => $entryType === 'refund'
                    ? "Estorno repasse iFood - Pedido #{$order->order_number}"
                    : "Repasse iFood (líquido) - Pedido #{$order->order_number}",
                'reference' => $settlementId,
            ]);

            $orderFee = (float) $order->fee;
            if ($entryType === 'credit' && $orderFee > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $integration->company_id,
                    'order_id' => $order->id,
                    'type' => 'fee',
                    'amount' => $orderFee,
                    'description' => "Taxa plataforma - Pedido #{$order->order_number}",
                    'reference' => $settlementId,
                ]);
            }
        });

        if ($alreadyReconciled) {
            Log::channel('ifood')->info('iFood: settlement já conciliado (idempotente)', [
                'order_id' => $order->id,
                'settlement_id' => $settlementId,
            ]);

            return;
        }

        Log::channel('ifood')->info('iFood: settlement conciliado', [
            'order_id' => $order->id,
            'settlement_id' => $settlementId,
            'type' => $entryType,
            'net_amount' => $netAmount,
        ]);
    }
}
