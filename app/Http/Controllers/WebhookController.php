<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\AbacatePayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function abacatepay(Request $request, AbacatePayService $service): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Signature', '');

        if (! $service->validateWebhookSignature($payload, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data  = $request->json()->all();
        $event = $data['event'] ?? null;

        if ($event !== 'billing.paid') {
            return response()->json(['status' => 'ignored']);
        }

        $billingId = $data['data']['id'] ?? null;
        $payment   = Payment::where('abacatepay_billing_id', $billingId)->first();

        if (! $payment) {
            return response()->json(['error' => 'Payment not found'], 404);
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

        return response()->json(['status' => 'ok']);
    }
}
