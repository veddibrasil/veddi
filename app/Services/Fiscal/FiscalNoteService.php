<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalNoteDTO;
use App\Exceptions\FiscalNoteAlreadyIssuedException;
use App\Models\Branch;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FiscalNoteService
{
    public function __construct(
        private readonly FiscalNoteProviderInterface $provider,
        private readonly FiscalConfigResolver $resolver,
    ) {}

    public function issue(Order $order, ?string $customerDocument = null, ?int $requestedBy = null): FiscalNote
    {
        $company = $order->company;

        Log::channel('fiscal')->info('FiscalNoteService: emissão iniciada', [
            'order_id' => $order->id,
            'company_id' => $company->id,
        ]);

        [
            'config' => $companyConfig,
            'environment' => $effectiveEnvironment,
            'branch' => $branch,
            'recipient' => $recipient,
            'model' => $model,
            'items' => $items,
        ] = $this->prepare($order, $customerDocument);

        $provider = $this->resolveProvider($companyConfig, $effectiveEnvironment);
        $reference = 'order_'.$order->id.'_'.Str::uuid();

        $dto = new FiscalNoteDTO(
            emitenteCnpj: $branch?->cnpj ?: $company->owner_cpf_cnpj ?? '',
            emitenteNome: $company->name,
            emitenteLogradouro: $branch?->address ?? '',
            emitenteNumero: $branch?->number ?? 'S/N',
            emitenteBairro: $branch?->neighborhood ?? '',
            emitenteMunicipio: $branch?->city ?? '',
            emitenteUf: $branch?->state ?? '',
            emitenteCep: $branch?->cep ?? '',
            emitenteCrt: $companyConfig->crt ?: config('fiscal.crt'),
            emitenteInscricaoEstadual: $companyConfig->inscricao_estadual,
            nfceSerie: (string) ($model === '55' ? $companyConfig->nfe_serie : ($companyConfig->nfce_serie ?: config('fiscal.nfce_serie'))),
            orderId: $order->id,
            total: (float) $order->total,
            paymentMethod: $order->payment_method ?? 'other',
            items: $items,
            customerDocument: $recipient['document'] ?: null,
            environment: $effectiveEnvironment,
            orderType: $order->order_type,
            model: $model,
            recipient: $recipient,
            reference: $reference,
            freight: (float) $order->delivery_fee,
            discount: (float) $order->discount + (float) $order->manual_discount,
            otherExpenses: (float) $order->service_fee + (float) $order->couvert_fee,
            payments: $model === '55' ? $order->payments()->where('status', 'paid')->get()->map(fn ($payment) => [
                'method' => match ($payment->payment_gateway) {
                    'cash' => 'cash', 'card_machine' => 'credit_card', 'pix_manual' => 'pix',
                    default => $order->payment_method,
                },
                'amount' => (float) ($payment->original_amount ?? $payment->amount),
            ])->all() : [],

        );

        $fiscalNote = $this->createPendingNoteExclusively($order, $company->id, $order->branch_id, [
            'model' => $model,
            'fiscal_config_id' => $companyConfig->id,
            'environment' => $effectiveEnvironment,
            'issuer_cnpj' => preg_replace('/\D/', '', $dto->emitenteCnpj),
            'series' => (int) $dto->nfceSerie,
            'provider_reference' => $reference,
            'data' => ['recipient' => $recipient, 'requested_by' => $requestedBy ?? auth()->id()],
        ]);

        try {
            $result = $provider->issue($dto);

            DB::transaction(function () use (&$fiscalNote, $result, $recipient) {
                $fiscalNote = FiscalNote::withoutGlobalScopes()->lockForUpdate()->findOrFail($fiscalNote->id);
                $final = in_array($fiscalNote->status, ['authorized', 'cancelled'], true);
                $fiscalNote->update([
                    'status' => $final ? $fiscalNote->status : $result->status,
                    'number' => $result->rawResponse['numero'] ?? $fiscalNote->number,
                    'protocol' => $result->rawResponse['protocolo'] ?? $fiscalNote->protocol,
                    'provider_reference' => $result->providerReference ?? $fiscalNote->provider_reference,
                    'access_key' => $result->accessKey ?? $fiscalNote->access_key,
                    'data' => array_replace($fiscalNote->data ?? [], [
                        'recipient' => $recipient,
                        'xml_url' => $result->xmlUrl ?? $fiscalNote->xml_url,
                        'danfe_url' => $result->danfeUrl ?? $fiscalNote->danfe_url,
                        'error_message' => $result->errorMessage,
                        'issued_at' => now()->toIso8601String(),
                        'raw_response' => $result->rawResponse,
                        'request_payload' => $result->requestPayload,
                        'customer_cpf_cnpj' => $recipient['document'],
                    ]),
                ]);
            });
            $order->update(['fiscal_error' => in_array($result->status, ['error', 'rejected'], true) ? $result->errorMessage : null]);

            Log::channel('fiscal')->info('Nota fiscal emitida', [
                'order_id' => $order->id,
                'fiscal_note_id' => $fiscalNote->id,
                'status' => $result->status,
            ]);
        } catch (\Throwable $e) {
            $fiscalNote->refresh();
            $fiscalNote->update([
                'status' => in_array($fiscalNote->status, ['authorized', 'cancelled'], true) ? $fiscalNote->status : 'pending',
                'data' => array_merge($fiscalNote->data ?? [], ['error_message' => $e->getMessage()]),
            ]);

            Log::channel('fiscal')->error('Erro ao emitir nota fiscal', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $fiscalNote->fresh();
    }

    /**
     * Mesmas validações de issue() sem criar nota nem chamar a Focus NFe — o Controle de notas
     * fiscais usa antes de emitir em lote, pra separar o que está pronto do que tem pendência.
     *
     * @return string|null mensagem da primeira pendência, ou null se o pedido pode ser emitido
     */
    public function preflight(Order $order): ?string
    {
        try {
            $this->prepare($order);

            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Resultado de um lote do Controle de notas: situação da última tentativa de cada pedido.
     * Job que falha antes do envio (pendência de dados, configuração) não cria nota — conta
     * como 'not_sent'. Rejeição da SEFAZ não falha o job, por isso não dá pra usar só
     * $batch->failedJobs pra dizer ao lojista como o lote terminou.
     *
     * @param  array<int, int>  $orderIds
     * @return array{authorized: int, rejected: int, pending: int, not_sent: int}
     */
    public function batchOutcome(int $companyId, array $orderIds): array
    {
        $latest = FiscalNote::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get(['order_id', 'status'])
            ->keyBy('order_id');

        $outcome = ['authorized' => 0, 'rejected' => 0, 'pending' => 0, 'not_sent' => 0];

        foreach ($orderIds as $orderId) {
            $outcome[match ($latest->get($orderId)?->status) {
                'authorized' => 'authorized',
                'rejected', 'error' => 'rejected',
                'pending' => 'pending',
                default => 'not_sent',
            }]++;
        }

        return $outcome;
    }

    /** "11 autorizada(s), 3 rejeitada(s)" — usado no banner do lote e na notificação do sino. */
    public static function describeBatchOutcome(array $outcome): string
    {
        $labels = ['authorized' => 'autorizada(s)', 'rejected' => 'rejeitada(s)/com erro', 'pending' => 'em processamento', 'not_sent' => 'não enviada(s) por pendência'];

        return collect($labels)
            ->filter(fn ($label, $key) => ($outcome[$key] ?? 0) > 0)
            ->map(fn ($label, $key) => $outcome[$key].' '.$label)
            ->implode(', ') ?: 'nenhum pedido processado';
    }

    /**
     * Valida módulo, configuração fiscal da filial, destinatário e itens e monta o que a emissão
     * precisa. Lança RuntimeException com mensagem pro lojista na primeira pendência.
     *
     * @return array{config: CompanyFiscalConfig, environment: string, branch: ?Branch, recipient: array, model: string, items: array}
     */
    private function prepare(Order $order, ?string $customerDocument = null): array
    {
        $company = $order->company;

        if (! $company->canUseFiscalNotes()) {
            throw new \RuntimeException('Módulo de nota fiscal não habilitado para esta empresa.');
        }

        $companyConfig = $this->resolver->forBranch($company, $order->branch_id);

        if (! $companyConfig) {
            throw new \RuntimeException('Nenhuma configuração fiscal encontrada para esta filial. Configure a filial ou marque uma configuração padrão.');
        }

        if (! $companyConfig->enabled) {
            throw new \RuntimeException('Configuração fiscal não habilitada para esta filial.');
        }

        $environment = $companyConfig->environment ?: config('fiscal.environment');
        $effectiveEnvironment = $this->effectiveEnvironment($environment);
        $token = $companyConfig->tokenFor($effectiveEnvironment) ?: config('fiscal.focus_nfe.token');

        if (! $token) {
            throw new \RuntimeException('Token do provedor fiscal não configurado. Contate o suporte.');
        }

        $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($companyConfig->branch_id ?? $order->branch_id);
        $recipient = $order->fiscal_recipient ?? FiscalRecipient::fromCustomer($order->customer);
        if ($customerDocument !== null) {
            $recipient['document'] = $customerDocument;
        }
        $model = FiscalRecipient::model($recipient);
        try {
            $recipient = FiscalRecipient::validate($recipient);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw new \RuntimeException('Dados fiscais pendentes: '.implode(' ', $e->validator->errors()->all()));
        }
        if ($model === '55' && ! $companyConfig->nfe_enabled) {
            throw new \RuntimeException('NF-e não habilitada para o emitente. Habilite NF-e na configuração fiscal.');
        }
        if ($model === '55' && (blank($branch?->state) || blank($branch?->cnpj ?: $company->owner_cpf_cnpj))) {
            throw new \RuntimeException('Complete o CNPJ e a UF do emitente antes de emitir NF-e.');
        }
        $items = $this->buildItems($order, $model, $branch?->state, $recipient['state'] ?? null, (int) ($companyConfig->crt ?: config('fiscal.crt')));
        if ($model === '55') {
            $calculated = array_sum(array_column($items, 'subtotal')) + (float) $order->delivery_fee
                + (float) $order->service_fee + (float) $order->couvert_fee
                - (float) $order->discount - (float) $order->manual_discount;
            if (round($calculated * 100) !== round((float) $order->total * 100) || $calculated <= 0) {
                throw new \RuntimeException('Totais fiscais divergentes. Confira itens, descontos e taxas do pedido.');
            }
        }

        return [
            'config' => $companyConfig,
            'environment' => $effectiveEnvironment,
            'branch' => $branch,
            'recipient' => $recipient,
            'model' => $model,
            'items' => $items,
        ];
    }

    /**
     * Trava a linha do pedido durante a checagem de nota ativa + criação do registro
     * pending, numa única transação. Sem isso, dois disparos concorrentes (ex.: evento
     * de pagamento duplicado, retry de job) passam ambos pelo "existe nota?" antes que
     * qualquer um grave a sua, e a empresa acaba emitindo duas NFC-e reais pro mesmo pedido.
     */
    private function createPendingNoteExclusively(Order $order, int $companyId, ?int $branchId, array $attributes): FiscalNote
    {
        return DB::transaction(function () use ($order, $companyId, $branchId, $attributes) {
            $lockedOrder = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->fiscal_recipient !== $order->fiscal_recipient) {
                throw new \RuntimeException('Os dados fiscais foram alterados durante a emissão. Confira o pedido e tente novamente.');
            }

            $hasActiveNote = FiscalNote::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->whereIn('status', ['pending', 'authorized'])
                ->exists();

            if ($hasActiveNote) {
                throw new FiscalNoteAlreadyIssuedException(
                    "Já existe uma nota fiscal ativa para o pedido {$order->id}."
                );
            }

            $order->update(['fiscal_recipient' => $attributes['data']['recipient']]);

            return FiscalNote::create([
                ...$attributes,
                'company_id' => $companyId,
                'order_id' => $order->id,
                'branch_id' => $branchId,
                'status' => 'pending',
            ]);
        });
    }

    /**
     * Consulta o status real na Focus NFe (GET /v2/nfce/{ref}) e atualiza a nota local.
     * Usada pelo job de reconciliação periódica (notas presas em "pending") e antes de
     * reemitir uma nota "error"/"rejected" — timeout de rede não garante que a Focus (e,
     * por trás dela, a SEFAZ) não recebeu a emissão anterior; reemitir sem checar arrisca
     * reciclar o mesmo número de NFC-e e cair em "Duplicidade de NF-e" na SEFAZ.
     * Sem provider_reference (falha antes do POST sequer sair), não há o que consultar.
     */
    public function reconcile(FiscalNote $note): FiscalNote
    {
        if (! $note->provider_reference) {
            return $note;
        }

        $companyConfig = $this->resolver->forNote($note);
        $environment = $note->environment ?: ($companyConfig?->environment ?: config('fiscal.environment'));
        $effectiveEnvironment = $this->effectiveEnvironment($environment);
        $token = $companyConfig?->tokenFor($effectiveEnvironment) ?: config('fiscal.focus_nfe.token');

        if (! $token) {
            return $note;
        }

        $provider = (new FocusNfeService($token, $this->baseUrlFor($effectiveEnvironment)))->forModel($note->model ?? '65');
        $result = $provider->query($note->provider_reference);

        if ($result->status === 'error') {
            return $note;
        }

        DB::transaction(function () use (&$note, $result) {
            $note = FiscalNote::withoutGlobalScopes()->lockForUpdate()->findOrFail($note->id);
            if ($note->status === 'cancelled' || ($note->status === 'authorized' && ! in_array($result->status, ['authorized', 'cancelled'], true))) {
                return;
            }
            $data = $note->data ?? [];
            $data['xml_url'] = $result->xmlUrl ?? $data['xml_url'] ?? null;
            $data['danfe_url'] = $result->danfeUrl ?? $data['danfe_url'] ?? null;
            $data['reconciled_at'] = now()->toIso8601String();
            $data['raw_response'] = $result->rawResponse;
            $data['error_message'] = $result->errorMessage;
            $note->update([
                'status' => $result->status,
                'access_key' => $result->accessKey ?? $note->access_key,
                'number' => $result->rawResponse['numero'] ?? $note->number,
                'protocol' => $result->rawResponse['protocolo'] ?? $note->protocol,
                'data' => $data,
            ]);
        });

        Log::channel('fiscal')->info('FiscalNoteService: status atualizado via reconciliação', [
            'fiscal_note_id' => $note->id,
            'status' => $note->status,
        ]);

        return $note->fresh();
    }

    public function cancel(FiscalNote $note, string $justification): void
    {
        \Illuminate\Support\Facades\Validator::make(['justification' => $justification], ['justification' => 'required|string|min:15|max:255'])->validate();
        Log::channel('fiscal')->info('FiscalNoteService: cancelamento solicitado', [
            'fiscal_note_id' => $note->id,
            'order_id' => $note->order_id,
        ]);

        if (! $note->provider_reference) {
            throw new \RuntimeException('Nota fiscal sem referência do provider.');
        }

        $companyConfig = $this->resolver->forNote($note);
        $environment = $note->environment ?: ($companyConfig?->environment ?: config('fiscal.environment'));
        $effectiveEnvironment = $this->effectiveEnvironment($environment);

        $provider = $companyConfig
            ? $this->resolveProvider($companyConfig, $effectiveEnvironment)
            : $this->provider;
        if ($provider instanceof FocusNfeService) {
            $provider = $provider->forModel($note->model ?? '65');
        }
        if (! $note->isCancellable()) {
            throw new \RuntimeException('Apenas notas autorizadas podem ser canceladas.');
        }

        $result = $provider->cancel($note->provider_reference, $justification);

        $data = $note->data ?? [];
        if ($result->status === 'cancelled') {
            $data['cancelled_at'] = now()->toIso8601String();
        }
        $note->events()->create([
            'company_id' => $note->company_id, 'user_id' => auth()->id(),
            'type' => 'cancellation', 'status' => $result->status,
            'data' => ['justification' => $justification, 'response' => $result->rawResponse, 'error' => $result->errorMessage],
        ]);
        $data['cancel_raw_response'] = $result->rawResponse;

        $note->update([
            'status' => $result->status === 'cancelled' ? 'cancelled' : $note->status,
            'data' => $data,
        ]);

        Log::channel('fiscal')->info('FiscalNoteService: resultado do cancelamento', [
            'fiscal_note_id' => $note->id,
            'status' => $note->status,
        ]);
    }

    /**
     * Empresa com token próprio (dual token da Focus NFe ou o legado provider_token)
     * emite pela conta dela; sem token próprio, cai no provider da plataforma (injetado).
     * Recebe o ambiente já efetivo (pós rebaixamento de segurança) para token e URL
     * nunca ficarem dessincronizados (token de produção batendo em URL de homologação).
     */
    private function resolveProvider(CompanyFiscalConfig $companyConfig, string $effectiveEnvironment): FiscalNoteProviderInterface
    {
        $token = $companyConfig->tokenFor($effectiveEnvironment);

        if (! $token) {
            return $this->provider instanceof FocusNfeService
                ? new FocusNfeService(config('fiscal.focus_nfe.token'), $this->baseUrlFor($effectiveEnvironment))
                : $this->provider;
        }

        return new FocusNfeService($token, $this->baseUrlFor($effectiveEnvironment));
    }

    /**
     * Só bate na API de produção da Focus NFe quando o app roda em produção —
     * qualquer outro APP_ENV sempre usa homologação, mesmo que a empresa esteja
     * configurada como "produção", para nunca emitir nota fiscal real fora de produção.
     */
    private function effectiveEnvironment(string $configuredEnvironment): string
    {
        return self::resolveEffectiveEnvironment($configuredEnvironment);
    }

    private function baseUrlFor(string $effectiveEnvironment): string
    {
        return self::resolveBaseUrl($effectiveEnvironment);
    }

    /**
     * Público/estático para ser reutilizado pelo webhook, que recebe callbacks
     * fora do fluxo normal de emissão e precisa da mesma regra host x ambiente
     * para montar URLs absolutas de DANFE/XML.
     */
    public static function resolveEffectiveEnvironment(string $configuredEnvironment): string
    {
        return (app()->isProduction() && $configuredEnvironment === 'producao') ? 'producao' : 'homologacao';
    }

    public static function resolveBaseUrl(string $effectiveEnvironment): string
    {
        return $effectiveEnvironment === 'producao'
            ? config('fiscal.focus_nfe.base_url_producao')
            : config('fiscal.focus_nfe.base_url_homologacao');
    }

    private function buildItems(Order $order, string $model = '65', ?string $issuerState = null, ?string $recipientState = null, int $crt = 1): array
    {
        $order->loadMissing('items.product');

        return $order->items->map(function ($item) use ($model, $issuerState, $recipientState, $crt) {
            $fiscal = $item->product?->fiscal_data ?? [];
            if ($model === '55') {
                foreach (['ncm', 'cfop', 'icms_situation', 'origin', 'pis_situation', 'cofins_situation'] as $field) {
                    if (! isset($fiscal[$field]) || $fiscal[$field] === '') {
                        throw new \RuntimeException('Dados fiscais pendentes no produto '.$item->product_name.': '.$field.'.');
                    }
                }
                $cfop = (string) ($issuerState !== $recipientState ? ($fiscal['interstate_cfop'] ?? '') : $fiscal['cfop']);
                if (! preg_match($issuerState !== $recipientState ? '/^6[0-9]{3}$/' : '/^5[0-9]{3}$/', $cfop)) {
                    throw new \RuntimeException('Configure o CFOP da operação no produto '.$item->product_name.'.');
                }
                $fiscal['cfop'] = $cfop;
                if (! preg_match('/^[0-9]{8}$/', $fiscal['ncm']) || ! in_array((string) $fiscal['origin'], array_map('strval', range(0, 8)), true)) {
                    throw new \RuntimeException('NCM ou origem inválidos no produto '.$item->product_name.'.');
                }
                if (($crt === 1 && strlen($fiscal['icms_situation']) !== 3) || ($crt !== 1 && strlen($fiscal['icms_situation']) !== 2)) {
                    throw new \RuntimeException('CST/CSOSN incompatível com o regime do emitente no produto '.$item->product_name.'.');
                }
                if ($item->quantity <= 0 || (float) $item->subtotal <= 0) {
                    throw new \RuntimeException('Quantidade ou valor fiscal inválido no produto '.$item->product_name.'.');
                }
            }

            return [
                'name' => $item->product_name,
                'unit' => $fiscal['unit'] ?? 'UN',
                'origin' => $fiscal['origin'] ?? 0,
                'pis_situation' => $fiscal['pis_situation'] ?? '07',
                'cofins_situation' => $fiscal['cofins_situation'] ?? '07',
                'taxes' => $fiscal['taxes'] ?? [],
                'ncm' => $fiscal['ncm'] ?? '21069090',
                'cfop' => $fiscal['cfop'] ?? '5102',
                'icms_situation' => $fiscal['icms_situation'] ?? '400',
                'cest' => $fiscal['cest'] ?? null,
                'quantity' => (float) $item->quantity,
                'unit_price' => $model === '55' && $item->quantity > 0 ? round((float) $item->subtotal / $item->quantity, 10) : (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ];
        })->toArray();
    }
}
