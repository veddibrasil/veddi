<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAsaasWebhook;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AsaasSimulatePaymentController extends Controller
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(config('app.debug'), 403, 'Disponível apenas em ambiente de desenvolvimento.');

        $request->validate([
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'type' => ['sometimes', 'in:setup_fee,subscription'],
            'event' => ['sometimes', 'in:PAYMENT_CONFIRMED,PAYMENT_RECEIVED,PAYMENT_OVERDUE'],
        ]);

        $companyId = $request->integer('company_id') ?: session('pending_company_id');

        abort_unless($companyId, 422, 'Informe company_id ou acesse após o cadastro.');

        $company = Company::findOrFail($companyId);

        abort_unless($company->asaas_customer_id, 422, 'Empresa sem asaas_customer_id cadastrado.');

        $type = $request->input('type', 'setup_fee');
        $event = $request->input('event', 'PAYMENT_CONFIRMED');

        $payload = match ($type) {
            'subscription' => $this->subscriptionPayload($company, $event),
            default => $this->setupFeePayload($company, $event),
        };

        ProcessAsaasWebhook::dispatch($event, $payload);

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'queued',
                'company_id' => $company->id,
                'type' => $type,
                'event' => $event,
                'payload' => $payload,
            ]);
        }

        return redirect()->route('login')
            ->with('status', 'Pagamento simulado! Aguarde alguns instantes e faça login.');
    }

    private function setupFeePayload(Company $company, string $event): array
    {
        return [
            'event' => $event,
            'payment' => [
                'id' => $company->asaas_setup_charge_id ?? 'sim_setup_'.$company->id,
                'customer' => $company->asaas_customer_id,
                'subscription' => null,
                'value' => 99.00,
                'status' => $event === 'PAYMENT_OVERDUE' ? 'OVERDUE' : 'CONFIRMED',
                'description' => '[simulação] Taxa de ativação',
            ],
        ];
    }

    private function subscriptionPayload(Company $company, string $event): array
    {
        return [
            'event' => $event,
            'payment' => [
                'id' => 'sim_sub_'.now()->timestamp.'_'.$company->id,
                'customer' => $company->asaas_customer_id,
                'subscription' => $company->asaas_subscription_id ?? 'sim_subscription_'.$company->id,
                'value' => $company->plan?->monthlyPrice() ?? 0,
                'status' => $event === 'PAYMENT_OVERDUE' ? 'OVERDUE' : 'CONFIRMED',
                'description' => '[simulação] Assinatura mensal',
            ],
        ];
    }
}
