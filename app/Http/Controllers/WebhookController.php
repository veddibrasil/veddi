<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Services\AbacatePayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function abacatepay(Request $request): JsonResponse
    {

        try {
            $payload   = $request->getContent();
            $signature = $request->header('X-Signature', '');
            $data      = $request->json()->all();
            $event     = $data['event'] ?? null;

            Log::channel('webhook')->info('Webhook recebido', [
                'event'   => $event,
                'dev_mode' => $data['devMode'] ?? false,
                'data'    => $data,
            ]);

            if ($event !== 'billing.paid') {
                Log::channel('webhook')->debug('Evento ignorado', ['event' => $event]);
                return response()->json(['status' => 'ignored']);
            }

            $billingId = $data['data']['billing']['id'] ?? null;

            // Load payment without tenant scope (webhook is global)
            $payment = Payment::withoutGlobalScope(CompanyScope::class)
                ->where('abacatepay_billing_id', $billingId)
                ->with('order.branch.company')
                ->first();

            if (! $payment) {
                Log::channel('webhook')->warning('Payment não encontrado para billing', ['billing_id' => $billingId]);
                return response()->json(['error' => 'Payment not found'], 404);
            }

            // Validate signature using the company's own credentials
            // Skip signature validation in devMode (AbacatePay test webhooks don't include X-Signature)
            $isDevMode = $data['devMode'] ?? false;
            if (! $isDevMode) {
                $company = $payment->order?->branch?->company;
                $service = new AbacatePayService($company);

                if (! $service->validateWebhookSignature($payload, $signature)) {
                    Log::channel('webhook')->warning('Assinatura inválida no webhook', ['billing_id' => $billingId]);
                    return response()->json(['error' => 'Invalid signature'], 401);
                }
            }

            if ($payment->status === 'paid') {
                Log::channel('webhook')->info('Webhook duplicado ignorado (já pago)', ['billing_id' => $billingId, 'order_id' => $payment->order_id]);
                return response()->json(['status' => 'already_processed']);
            }

            // Reject webhook for expired billings
            if ($payment->expires_at && $payment->expires_at->isPast()) {
                Log::channel('webhook')->warning('Webhook para billing expirado', ['billing_id' => $billingId, 'expired_at' => $payment->expires_at]);
                return response()->json(['error' => 'Billing expired'], 422);
            }

            $payment->update([
                'status'          => 'paid',
                'paid_at'         => now(),
                'webhook_payload' => $data,
            ]);

            $payment->order->update(['status' => 'paid']);

            Log::channel('webhook')->info('Pagamento confirmado via webhook', [
                'billing_id' => $billingId,
                'order_id'   => $payment->order_id,
                'amount'     => $payment->amount,
            ]);

            Log::channel('payments')->info('Pagamento confirmado via webhook AbacatePay', [
                'billing_id' => $billingId,
                'order_id'   => $payment->order_id,
                'amount'     => $payment->amount,
            ]);

            OrderStatusUpdated::dispatch($payment->order->fresh());

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::channel('webhook')->error('Erro ao processar webhook AbacatePay', [
                'message'   => $e->getMessage(),
                'exception' => $e,
            ]);
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }
}
