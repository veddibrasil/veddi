<?php

namespace App\Http\Controllers;

use App\Services\Payment\StarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StarkSimulatePaymentController extends Controller
{
    public function __invoke(Request $request, StarkService $stark): JsonResponse
    {
        abort_unless(config('app.debug'), 403, 'Disponível apenas em ambiente de desenvolvimento.');

        $request->validate([
            'brcode' => ['sometimes', 'string'],
            'tax_id' => ['sometimes', 'string'],
            'amount' => ['sometimes', 'integer', 'min:1'],
            'description' => ['sometimes', 'string', 'max:100'],
        ]);

        $brcode = $request->input('brcode', '00020126390014br.gov.bcb.pix0117valid@sandbox.com52040000530398654041.005802BR5908Jon Snow6009Sao Paulo62110507sdktest63046109');
        $taxId = $request->input('tax_id', '012.345.678-90');
        $amountInCents = $request->integer('amount', 100);
        $description = $request->input('description', 'Simulacao pagamento PIX sandbox');

        $payment = $stark->payBrcode($brcode, $taxId, $description, $amountInCents);

        return response()->json([
            'status' => 'created',
            'payment_id' => $payment['id'],
            'payment_status' => $payment['status'],
            'brcode' => $brcode,
            'amount' => $amountInCents,
        ]);
    }
}
