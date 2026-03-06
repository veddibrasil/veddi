<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Services\AbacatePayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function abacatepay(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Signature', '');
        $data      = $request->json()->all();
        $event     = $data['event'] ?? null;

        if ($event !== 'billing.paid') {
            return response()->json(['status' => 'ignored']);
        }

        $billingId = $data['data']['id'] ?? null;

        // Load payment without tenant scope (webhook is global)
        $payment = Payment::withoutGlobalScope(CompanyScope::class)
            ->where('abacatepay_billing_id', $billingId)
            ->with('order.branch.company')
            ->first();

        if (! $payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        // Validate signature using the company's own credentials
        $company = $payment->order?->branch?->company;
        $service = new AbacatePayService($company);

        if (! $service->validateWebhookSignature($payload, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
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
