<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payment\VindiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VindiSimulatePaymentController extends Controller
{
    public function __invoke(Request $request, VindiService $vindi): JsonResponse
    {
        abort_unless(config('app.debug'), 403, 'Disponível apenas em ambiente de desenvolvimento.');

        $request->validate([
            'transaction_token' => ['required', 'string'],
        ]);

        $token = $request->input('transaction_token');
        $status = $vindi->getTransactionStatus($token);

        $payment = Payment::where('vindi_transaction_token', $token)->first();

        return response()->json([
            'transaction_token' => $token,
            'status' => $status,
            'payment_id' => $payment?->id,
            'order_id' => $payment?->order_id,
        ]);
    }
}
