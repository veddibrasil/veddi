<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Payment\VindiPartnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateVindiPartnerAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly Company $company,
    ) {}

    public function handle(VindiPartnerService $service): void
    {
        if (empty(config('payments.vindi_token_account'))) {
            Log::channel('payments')->warning('VINDI_TOKEN_ACCOUNT não configurado — ignorando criação de conta parceira', [
                'company_id' => $this->company->id,
            ]);

            return;
        }

        if ($this->company->vindi_partner_id) {
            Log::channel('payments')->info('Conta parceira Vindi já existe', [
                'company_id' => $this->company->id,
                'partner_id' => $this->company->vindi_partner_id,
            ]);

            return;
        }

        // 1. Create partner account (sub-merchant)
        $account = $service->createPartnerAccount($this->company);

        $partnerId = (string) ($account['id'] ?? '');
        $partnerLogin = $account['email'] ?? $account['login'] ?? null;

        $this->company->update(['vindi_partner_id' => $partnerId]);

        // 2. Register as split affiliate using the account login
        if ($partnerLogin) {
            $affiliate = $service->createAffiliate($partnerLogin, enabled: true);

            Log::channel('payments')->info('Conta parceira Vindi + afiliado provisionados', [
                'company_id' => $this->company->id,
                'partner_id' => $partnerId,
                'affiliate_id' => $affiliate['id'] ?? null,
            ]);
        } else {
            Log::channel('payments')->warning('Conta parceira Vindi criada sem login — afiliado não registrado', [
                'company_id' => $this->company->id,
                'partner_id' => $partnerId,
                'account' => $account,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('payments')->error('CreateVindiPartnerAccount falhou após todas tentativas', [
            'company_id' => $this->company->id,
            'error' => $e->getMessage(),
        ]);
    }
}
