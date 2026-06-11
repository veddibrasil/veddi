<?php

namespace App\Services\Payment;

use App\Models\Company;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VindiPartnerService
{
    private string $baseUrl;

    public function __construct()
    {
        $sandbox = config('app.env') !== 'production';
        $this->baseUrl = $sandbox
            ? 'https://sandbox-app.vindi.com.br/api/v1'
            : 'https://app.vindi.com.br/api/v1';
    }

    /**
     * Creates a new partner account (sub-merchant) on Vindi Recorrência.
     * POST /v1/partner/accounts
     *
     * @see https://developers.vindi.com.br/reference/postv1partneraccounts-1
     */
    public function createPartnerAccount(Company $company): array
    {
        $payload = [
            'name' => $company->name,
            'email' => $this->resolveCompanyEmail($company),
            'document' => preg_replace('/\D/', '', $company->owner_cpf_cnpj ?? ''),
        ];

        $response = $this->http()->post("{$this->baseUrl}/partner/accounts", $payload);

        if ($response->failed()) {
            Log::channel('payments')->error('Vindi partner account creation failed', [
                'company_id' => $company->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Vindi partner account error {$response->status()}: {$response->body()}");
        }

        $account = $response->json('account') ?? $response->json();

        Log::channel('payments')->info('Vindi partner account created', [
            'company_id' => $company->id,
            'account_id' => $account['id'] ?? null,
        ]);

        return $account;
    }

    /**
     * Registers a split payment participant on Vindi Recorrência.
     * POST /v1/affiliates
     *
     * @see https://developers.vindi.com.br/reference/postv1affiliates-1
     */
    public function createAffiliate(string $login, bool $enabled = true): array
    {
        $response = $this->http()->post("{$this->baseUrl}/affiliates", [
            'login' => $login,
            'status' => $enabled ? 1 : 0,
            'enabled' => $enabled ? 'true' : 'false',
        ]);

        if ($response->failed()) {
            Log::channel('payments')->error('Vindi affiliate creation failed', [
                'login' => $login,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Vindi affiliate error {$response->status()}: {$response->body()}");
        }

        $affiliate = $response->json('affiliate') ?? $response->json();

        Log::channel('payments')->info('Vindi affiliate created', [
            'login' => $login,
            'affiliate_id' => $affiliate['id'] ?? null,
        ]);

        return $affiliate;
    }

    /**
     * Lists all split payment participants.
     * GET /v1/affiliates
     */
    public function listAffiliates(int $page = 1, int $perPage = 25): array
    {
        $response = $this->http()->get("{$this->baseUrl}/affiliates", [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        if ($response->failed()) {
            Log::channel('payments')->warning('Vindi list affiliates failed', [
                'status' => $response->status(),
            ]);

            return [];
        }

        return $response->json('affiliates') ?? $response->json() ?? [];
    }

    /**
     * Gets a single split payment participant by ID.
     * GET /v1/affiliates/{id}
     */
    public function getAffiliate(int $id): array
    {
        $response = $this->http()->get("{$this->baseUrl}/affiliates/{$id}");

        if ($response->failed()) {
            throw new \RuntimeException("Vindi get affiliate error {$response->status()}: {$response->body()}");
        }

        return $response->json('affiliate') ?? $response->json();
    }

    /**
     * Updates a split payment participant.
     * PUT /v1/affiliates/{id}
     */
    public function updateAffiliate(int $id, array $data): array
    {
        $response = $this->http()->put("{$this->baseUrl}/affiliates/{$id}", $data);

        if ($response->failed()) {
            throw new \RuntimeException("Vindi update affiliate error {$response->status()}: {$response->body()}");
        }

        return $response->json('affiliate') ?? $response->json();
    }

    /**
     * Executes verification check on a split participant.
     * PUT /v1/affiliates/{id}/verify
     */
    public function verifyAffiliate(int $id): array
    {
        $response = $this->http()->put("{$this->baseUrl}/affiliates/{$id}/verify");

        if ($response->failed()) {
            Log::channel('payments')->warning('Vindi verify affiliate failed', [
                'affiliate_id' => $id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Vindi verify affiliate error {$response->status()}: {$response->body()}");
        }

        return $response->json('affiliate') ?? $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth((string) config('payments.vindi_token_account', ''), '')
            ->acceptJson()
            ->asJson();
    }

    private function resolveCompanyEmail(Company $company): string
    {
        $adminUser = $company->users()
            ->wherePivot('role', 'company_admin')
            ->first();

        return $adminUser?->email ?? "{$company->slug}@parceiro.vindi.com.br";
    }
}
