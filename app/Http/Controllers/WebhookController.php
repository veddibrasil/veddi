<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Services\AbacatePayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function abacatepay(Request $request): JsonResponse
    {

        try {
            $payload   = $request->getContent();
            $signature = $request->header('X-Signature', '');
            $data      = $request->json()->all();
            $event     = $data['event'] ?? null;

            \Log::info('Received AbacatePay webhook', [
                'event' => $event,
                'data' => $data,
            ]);
            if ($event !== 'billing.paid') {
                return response()->json(['status' => 'ignored']);
            }

            $billingId = $data['data']['billing']['id'] ?? null;

            // Load payment without tenant scope (webhook is global)
            $payment = Payment::withoutGlobalScope(CompanyScope::class)
                ->where('abacatepay_billing_id', $billingId)
                ->with('order.branch.company')
                ->first();

            if (! $payment) {
                return response()->json(['error' => 'Payment not found'], 404);
            }

            // Validate signature using the company's own credentials
            // Skip signature validation in devMode (AbacatePay test webhooks don't include X-Signature)
            $isDevMode = $data['devMode'] ?? false;
            if (! $isDevMode) {
                $company = $payment->order?->branch?->company;
                $service = new AbacatePayService($company);

                if (! $service->validateWebhookSignature($payload, $signature)) {
                    return response()->json(['error' => 'Invalid signature'], 401);
                }
            }

            if ($payment->status === 'paid') {
                return response()->json(['status' => 'already_processed']);
            }

            $payment->update([
                'status'          => 'paid',
                'paid_at'         => now(),
                'webhook_payload' => $data,
            ]);

            $payment->order->update(['status' => 'paid']);

            OrderStatusUpdated::dispatch($payment->order->fresh());

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            \Log::error('Error processing AbacatePay webhook: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }
}
