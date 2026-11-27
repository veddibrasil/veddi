<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalEmissionDecision;
use App\DTOs\FiscalNoteDTO;
use App\Exceptions\FiscalNoteAlreadyIssuedException;
use App\Exceptions\FiscalPreflightException;
use App\Models\Branch;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Support\Fiscal\ProductFiscalProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FiscalNoteService
{
    /** Campos do produto exigidos pela NF-e, com o rótulo usado no cadastro de produtos. */
    private const NFE_PRODUCT_FIELDS = [
        'ncm' => 'NCM',
        'cfop' => 'CFOP interno',
        'icms_situation' => 'CST / CSOSN ICMS',
        'origin' => 'Origem',
        'pis_situation' => 'CST PIS',
        'cofins_situation' => 'CST COFINS',
    ];

    /**
     * Valores usados na NFC-e ao consumidor (CPF ou sem documento) quando o produto não tem
     * dados fiscais. A Tributação em lote oferece este conjunto como sugestão a conferir.
     */
    public const NFCE_CONSUMER_FALLBACK = [
        'ncm' => '21069090',
        'cfop' => '5102',
        'icms_situation' => '400',
        'origin' => '0',
        'unit' => 'UN',
        'pis_situation' => '07',
        'cofins_situation' => '07',
    ];

    public function __construct(
        private readonly FiscalNoteProviderInterface $provider,
        private readonly FiscalConfigResolver $resolver,
        private readonly FiscalEmissionPolicy $policy,
        private readonly FiscalDeliveryAddress $deliveryAddresses,
    ) {}

    /**
     * @param  string|null  $confirmedModel  modelo que o operador viu e confirmou na tela; se a
     *                                       política agora decidir outro, a emissão para e pede nova conferência
     * @param  bool  $automatic  emissão automática (AutoIssueFiscalNote): marca a nota para o disjuntor e o resumo de falhas
     */
    public function issue(Order $order, ?string $customerDocument = null, ?int $requestedBy = null, ?string $confirmedModel = null, bool $automatic = false): FiscalNote
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
            'decision' => $decision,
            'delivery_address' => $deliveryAddress,
        ] = $this->prepare($order, $customerDocument, $confirmedModel);

        $provider = $this->resolveProvider($companyConfig, $effectiveEnvironment);
        $reference = 'order_'.$order->id.'_'.Str::uuid();

        $dto = new FiscalNoteDTO(
            emitenteCnpj: $companyConfig->issuer_cnpj ?: ($branch?->cnpj ?: $company->owner_cpf_cnpj ?? ''),
            emitenteNome: $companyConfig->legal_name ?: $company->name,
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
            // Uma forma por pagamento recebido (pagamento dividido no PDV, por exemplo); o envio só
            // usa a lista quando a soma bate com o total, senão manda a forma do pedido.
            payments: $order->payments()->where('status', 'paid')->get()->map(fn ($payment) => [
                'method' => match ($payment->payment_gateway) {
                    'cash' => 'cash', 'card_machine' => 'credit_card', 'pix_manual' => 'pix',
                    default => $order->payment_method,
                },
                'amount' => (float) ($payment->original_amount ?? $payment->amount),
            ])->all(),
            interstate: $decision->interstate,
            presential: $decision->presential,
            deliveryAddress: $deliveryAddress,
        );

        $fiscalNote = $this->createPendingNoteExclusively($order, $company->id, $order->branch_id, [
            'model' => $model,
            'fiscal_config_id' => $companyConfig->id,
            'environment' => $effectiveEnvironment,
            'issuer_cnpj' => FiscalRecipient::normalizeDocument($dto->emitenteCnpj),
            'series' => (int) $dto->nfceSerie,
            'provider_reference' => $reference,
            'data' => [
                'recipient' => $recipient,
                'delivery_address' => $deliveryAddress,
                'requested_by' => $requestedBy ?? auth()->id(),
                'automatic' => $automatic,
                // Decisão da política gravada antes do envio: qual versão escolheu o modelo e por quê.
                'policy' => $decision->toArray(),
                'issuer' => [
                    'name' => $dto->emitenteNome,
                    'cnpj' => FiscalRecipient::normalizeDocument($dto->emitenteCnpj),
                    'street' => $dto->emitenteLogradouro,
                    'number' => $dto->emitenteNumero,
                    'neighborhood' => $dto->emitenteBairro,
                    'city' => $dto->emitenteMunicipio,
                    'state' => $dto->emitenteUf,
                    'zip' => $dto->emitenteCep,
                ],
            ],
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
     * @return string|null todas as pendências num texto, ou null se o pedido pode ser emitido
     */
    public function preflight(Order $order, ?string $customerDocument = null, ?string $confirmedModel = null): ?string
    {
        $issues = $this->check($order, $customerDocument, $confirmedModel)['issues'];

        return $issues === [] ? null : (new FiscalPreflightException($issues))->getMessage();
    }

    /**
     * Pré-checagem para a tela: decisão da política (modelo e alternativas) e pendências
     * agrupadas (emitente, documento, destinatário, produtos), sem efeito colateral.
     *
     * @param  string|null  $preference  escolha que o operador está fazendo agora ('nfce'|'nfe'|null = automático);
     *                                   sem informar, usa a gravada no pedido
     * @return array{decision: FiscalEmissionDecision, issues: array<int, array<string, mixed>>}
     */
    public function check(Order $order, ?string $customerDocument = null, ?string $confirmedModel = null, ?string $preference = null, bool $usePreference = true): array
    {
        $decision = $this->policy->forOrder($order, $customerDocument, $preference, $usePreference);

        try {
            $this->prepare($order, $customerDocument, $confirmedModel, $decision);

            return ['decision' => $decision, 'issues' => []];
        } catch (FiscalPreflightException $e) {
            return ['decision' => $decision, 'issues' => $e->issues];
        } catch (\RuntimeException $e) {
            return ['decision' => $decision, 'issues' => [['group' => 'order', 'message' => $e->getMessage()]]];
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
     * Valida módulo, configuração fiscal da filial, política de emissão, destinatário e itens e
     * monta o que a emissão precisa. Problema de emitente interrompe na hora (não há o que
     * conferir sem ele); documento, destinatário e produtos são reunidos numa só
     * FiscalPreflightException, para o lojista corrigir tudo de uma vez.
     *
     * @return array{config: CompanyFiscalConfig, environment: string, branch: ?Branch, recipient: array, model: string, items: array, decision: FiscalEmissionDecision, delivery_address: ?array}
     */
    private function prepare(Order $order, ?string $customerDocument = null, ?string $confirmedModel = null, ?FiscalEmissionDecision $decision = null): array
    {
        $company = $order->company;

        if ($order->isStaffConsumption()) {
            throw FiscalPreflightException::single('order', 'Consumo de funcionário não é venda: não emite nota fiscal.');
        }

        if (! $company->canUseFiscalNotes()) {
            throw FiscalPreflightException::single('issuer', 'Módulo de nota fiscal não habilitado para esta empresa.');
        }

        $companyConfig = $this->resolver->forBranch($company, $order->branch_id);

        if (! $companyConfig) {
            throw FiscalPreflightException::single('issuer', 'Nenhuma configuração fiscal encontrada para esta filial. Configure o emitente desta filial antes de emitir.');
        }

        if (! $companyConfig->enabled) {
            throw FiscalPreflightException::single('issuer', 'Configuração fiscal não habilitada para esta filial.');
        }

        $effectiveEnvironment = self::resolveEmissionEnvironment($companyConfig->emission_environment);
        $token = $companyConfig->tokenFor($effectiveEnvironment) ?: config('fiscal.focus_nfe.token');

        if (! $token) {
            throw FiscalPreflightException::single('issuer', 'Token do provedor fiscal não configurado. Contate o suporte.');
        }

        $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($companyConfig->branch_id ?? $order->branch_id);
        $branchCnpj = FiscalRecipient::normalizeDocument($branch?->cnpj ?: ($company->owner_cpf_cnpj ?? ''));
        if ($companyConfig->issuer_cnpj && $companyConfig->issuer_cnpj !== $branchCnpj) {
            throw FiscalPreflightException::single('issuer', 'O CNPJ da filial diverge do emitente fiscal registrado. Revise a configuração fiscal.');
        }
        if (strlen($companyConfig->issuer_cnpj ?: $branchCnpj) !== 14) {
            throw FiscalPreflightException::single('issuer', 'Complete o CNPJ do emitente desta filial antes de emitir.');
        }

        $decision ??= $this->policy->forOrder($order, $customerDocument);
        $model = $decision->model;
        $issues = [];

        if ($decision->blocked()) {
            foreach ($decision->reasons as $reason) {
                $issues[] = ['group' => 'policy', 'message' => $reason];
            }
        } elseif ($confirmedModel !== null && $confirmedModel !== $model) {
            // Algo mudou entre a conferência e a execução (configuração da filial, destinatário,
            // valor): nunca trocar o modelo em silêncio.
            $issues[] = ['group' => 'policy', 'message' => 'A emissão foi conferida como '.FiscalEmissionDecision::label($confirmedModel)
                .', mas agora o documento indicado é '.FiscalEmissionDecision::label($model).'. Confira o pedido e emita novamente.'];
        }

        $recipient = FiscalEmissionPolicy::recipientFor($order, $customerDocument);
        try {
            $recipient = FiscalRecipient::validate($recipient, $model);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (! in_array('document', $decision->needs, true)) {
                $issues[] = ['group' => 'recipient', 'message' => 'Dados fiscais pendentes: '.implode(' ', $e->validator->errors()->all()), 'fields' => array_keys($e->errors())];
            }
        }
        if ($model === '55' && (blank($branch?->state) || blank($branch?->cnpj ?: $company->owner_cpf_cnpj))) {
            throw FiscalPreflightException::single('issuer', 'Complete o CNPJ e a UF do emitente antes de emitir NF-e.');
        }

        // NFC-e de entrega a domicílio (presença 4): a SEFAZ rejeita sem o endereço do
        // destinatário (788). Barra aqui, antes de gastar uma tentativa na SEFAZ.
        $deliveryAddress = null;
        if (FiscalDeliveryAddress::required($order, $model)) {
            $orderBranch = $order->branch_id && $order->branch_id !== $branch?->id
                ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($order->branch_id)
                : null;
            ['address' => $deliveryAddress, 'missing' => $missing] = $this->deliveryAddresses->resolve($order, $recipient, array_filter([$orderBranch, $branch]));
            $issuerState = mb_strtoupper(trim((string) $branch?->state));
            if ($missing !== []) {
                $issues[] = ['group' => 'order', 'message' => 'NFC-e de entrega a domicílio exige o endereço da entrega, e falta '.implode(', ', $missing)
                    .'. Preencha o endereço do destinatário nos dados fiscais do pedido ou emita NF-e.'];
            } elseif ($issuerState !== '' && $deliveryAddress['state'] !== $issuerState) {
                $issues[] = ['group' => 'order', 'message' => 'Entrega em outra UF ('.$deliveryAddress['state'].'): NFC-e só para operação interna. Emita NF-e.'];
            }
        }

        $items = [];
        if ($model !== null) {
            try {
                $items = $this->buildItems($order, $model, $decision->interstate, (int) ($companyConfig->crt ?: config('fiscal.crt')), $decision->documentType === FiscalRecipient::TYPE_CNPJ);
            } catch (FiscalPreflightException $e) {
                $issues = [...$issues, ...$e->issues];
            }
        }

        if ($issues !== []) {
            throw new FiscalPreflightException($issues);
        }

        if ($model === '55') {
            $calculated = array_sum(array_column($items, 'subtotal')) + (float) $order->delivery_fee
                + (float) $order->service_fee + (float) $order->couvert_fee
                - (float) $order->discount - (float) $order->manual_discount;
            if (round($calculated * 100) !== round((float) $order->total * 100) || $calculated <= 0) {
                throw FiscalPreflightException::single('order', 'Totais fiscais divergentes. Confira itens, descontos e taxas do pedido.');
            }
        }

        return [
            'config' => $companyConfig,
            'environment' => $effectiveEnvironment,
            'branch' => $branch,
            'recipient' => $recipient,
            'model' => $model,
            'items' => $items,
            'decision' => $decision,
            'delivery_address' => $deliveryAddress,
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
            // Cancelado entre a checagem e o envio (emissão automática ou fila atrasada): o
            // cancelamento só cancela nota que já existe, então aqui ela não pode nascer.
            if ($lockedOrder->isClosed()) {
                throw new \RuntimeException('Pedido cancelado: a nota fiscal não foi emitida.');
            }
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
        if (! $companyConfig) {
            throw new \RuntimeException('Configuração do emitente original da nota não encontrada.');
        }
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
        if (! $companyConfig) {
            throw new \RuntimeException('Configuração do emitente original da nota não encontrada.');
        }
        $environment = $note->environment ?: ($companyConfig?->environment ?: config('fiscal.environment'));
        $effectiveEnvironment = $this->effectiveEnvironment($environment);

        $provider = $this->resolveProvider($companyConfig, $effectiveEnvironment);
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
     * Consultas e eventos de notas existentes respeitam o ambiente da emissão,
     * mantendo a proteção contra acesso à produção fora de APP_ENV=production.
     */
    private function effectiveEnvironment(string $configuredEnvironment): string
    {
        return self::resolveEffectiveEnvironment($configuredEnvironment);
    }

    private function baseUrlFor(string $effectiveEnvironment): string
    {
        return self::resolveBaseUrl($effectiveEnvironment);
    }

    public static function resolveEmissionEnvironment(?string $branchEnvironment = null): string
    {
        return self::resolveEffectiveEnvironment($branchEnvironment ?: (app()->isProduction() ? 'producao' : 'homologacao'));
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

    /**
     * Itens da nota com o perfil fiscal efetivo de cada produto (ProductFiscalProfile): o
     * cadastro geral vale para todas as filiais e o perfil da filial só troca o que ela tem de
     * diferente. Perfil de regime diferente do cadastro geral vale sozinho.
     *
     * NF-e e NFC-e com CNPJ exigem os campos do produto preenchidos; só a NFC-e de consumidor
     * (CPF ou sem documento) mantém os padrões históricos. Pendências de todos os itens saem
     * juntas, cada uma com o cadastro onde corrigir.
     */
    private function buildItems(Order $order, string $model, bool $interstate, int $crt = 1, bool $strict = false): array
    {
        $order->loadMissing(['items.product', 'branch']);
        $strict = $strict || $model === '55';

        $profiles = DB::table('branch_product')
            ->join('branches', 'branches.id', '=', 'branch_product.branch_id')
            ->where('branches.company_id', $order->company_id)
            ->where('branch_product.branch_id', $order->branch_id)
            ->whereIn('branch_product.product_id', $order->items->pluck('product_id')->filter())
            ->whereNotNull('branch_product.fiscal_data')
            ->pluck('branch_product.fiscal_data', 'branch_product.product_id');

        $problems = [];
        $required = self::NFE_PRODUCT_FIELDS + ($interstate ? ['interstate_cfop' => 'CFOP interestadual'] : []);
        $branchName = trim((string) $order->branch?->name);

        $items = $order->items->map(function ($item) use ($order, $model, $interstate, $crt, $strict, $profiles, $required, $branchName, &$problems) {
            if ($item->product && (int) $item->product->company_id !== (int) $order->company_id) {
                throw FiscalPreflightException::single('order', 'Produto não pertence à empresa do pedido.');
            }
            $branchRaw = $profiles->get($item->product_id);
            $branchProfile = $branchRaw !== null ? json_decode($branchRaw, true) : null;
            $general = $item->product?->fiscal_data;
            $resolved = is_array($branchProfile) || $branchRaw === null
                ? ProductFiscalProfile::resolve(is_array($general) ? $general : [], $branchProfile)
                : null;
            // $fix: onde o lojista corrige. Campo que falta em todo lugar vai para o cadastro geral
            // (vale para todas as filiais); perfil de regime diferente se corrige na própria filial.
            $problem = function (string $fix, array $fields = [], ?string $text = null) use ($item, $order, $branchRaw, $resolved, $branchName, &$problems) {
                $problems[$item->product_id ? 'product_'.$item->product_id : 'item_'.$item->id] = [
                    'product_id' => $item->product_id,
                    'product_name' => trim((string) $item->product_name),
                    'fields' => $fields,
                    'problem' => $text,
                    'source' => $fix,
                    'branch_id' => $fix === 'branch' ? $order->branch_id : null,
                    'branch_name' => $fix === 'branch' ? $branchName : null,
                    'has_branch_profile' => $branchRaw !== null,
                    'standalone' => (bool) ($resolved['standalone'] ?? false),
                ];
            };
            if ($resolved === null) {
                $problem('branch', text: 'perfil fiscal da filial inválido; abra o produto e salve o perfil da filial novamente');

                return [];
            }
            $fiscal = $resolved['data'];
            $sourceOf = fn (string $field) => $resolved['standalone'] ? 'branch' : ($resolved['sources'][$field] ?? 'general');
            if (! is_array($fiscal['taxes'] ?? [])) {
                $problem('general', text: 'campos tributários adicionais inválidos');

                return [];
            }
            $invalidTaxes = function (mixed $taxes): bool {
                foreach (is_array($taxes) ? $taxes : [] as $key => $value) {
                    if (! preg_match('/^(icms_|pis_|cofins_|ipi_|ibs_|cbs_|is_)/', (string) $key) || is_array($value) || is_object($value)
                        || in_array($key, ['icms_situacao_tributaria', 'icms_origem', 'pis_situacao_tributaria', 'cofins_situacao_tributaria'], true)) {
                        return true;
                    }
                }

                return false;
            };
            if ($invalidTaxes($fiscal['taxes'] ?? [])) {
                $problem($invalidTaxes($branchProfile['taxes'] ?? []) ? 'branch' : 'general', text: 'campos tributários adicionais inválidos; use os campos próprios para CST/CSOSN e origem');

                return [];
            }
            if ($strict) {
                $absent = array_filter($required, fn ($label, $field) => ! ProductFiscalProfile::filled($fiscal[$field] ?? null), ARRAY_FILTER_USE_BOTH);
                if ($absent !== []) {
                    // Falta no perfil efetivo = falta também no cadastro geral (ou o perfil não herda).
                    $problem($resolved['standalone'] ? 'branch' : 'general', array_values($absent));

                    return [];
                }
            }
            $situation = (string) ($fiscal['icms_situation'] ?? ($crt === 1 ? '400' : ''));
            if (! preg_match($crt === 1 ? '/^[0-9]{3}$/' : '/^[0-9]{2}$/', $situation)) {
                $problem(...$this->regimeProblem($order, $crt, $situation, $sourceOf('icms_situation'), $branchName));

                return [];
            }
            if ($strict) {
                $cfopField = $interstate ? 'interstate_cfop' : 'cfop';
                $cfop = (string) ($fiscal[$cfopField] ?? '');
                if (! preg_match($interstate ? '/^6[0-9]{3}$/' : '/^5[0-9]{3}$/', $cfop)) {
                    $problem($sourceOf($cfopField), text: ($interstate ? 'CFOP interestadual' : 'CFOP interno').' '.$cfop.' não serve para esta venda (use '.($interstate ? '6xxx, venda para outro estado' : '5xxx, venda dentro do estado').')');

                    return [];
                }
                $fiscal['cfop'] = $cfop;
                if (! preg_match('/^[0-9]{8}$/', (string) $fiscal['ncm'])) {
                    $problem($sourceOf('ncm'), text: 'NCM '.$fiscal['ncm'].' inválido (precisa ter 8 dígitos)');

                    return [];
                }
                if (! in_array((string) $fiscal['origin'], array_map('strval', range(0, 8)), true)) {
                    $problem($sourceOf('origin'), text: 'origem '.$fiscal['origin'].' inválida (use 0 a 8)');

                    return [];
                }
                if ($item->quantity <= 0 || (float) $item->subtotal <= 0) {
                    $problem('general', text: 'quantidade ou valor do item zerado no pedido');

                    return [];
                }
            }

            return [
                'name' => $item->product_name,
                'unit' => $fiscal['unit'] ?? self::NFCE_CONSUMER_FALLBACK['unit'],
                'origin' => $fiscal['origin'] ?? (int) self::NFCE_CONSUMER_FALLBACK['origin'],
                'pis_situation' => $fiscal['pis_situation'] ?? self::NFCE_CONSUMER_FALLBACK['pis_situation'],
                'cofins_situation' => $fiscal['cofins_situation'] ?? self::NFCE_CONSUMER_FALLBACK['cofins_situation'],
                'taxes' => $fiscal['taxes'] ?? [],
                'ncm' => $fiscal['ncm'] ?? self::NFCE_CONSUMER_FALLBACK['ncm'],
                'cfop' => $fiscal['cfop'] ?? self::NFCE_CONSUMER_FALLBACK['cfop'],
                'icms_situation' => $fiscal['icms_situation'] ?? self::NFCE_CONSUMER_FALLBACK['icms_situation'],
                'cest' => $fiscal['cest'] ?? null,
                'quantity' => (float) $item->quantity,
                'unit_price' => $model === '55' && $item->quantity > 0 ? round((float) $item->subtotal / $item->quantity, 10) : (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ];
        })->toArray();

        if ($problems !== []) {
            $document = $model === '55' ? 'NF-e' : ($strict ? 'NFC-e com CNPJ' : 'NFC-e');

            throw new FiscalPreflightException([[
                'group' => 'products',
                'title' => 'Dados fiscais pendentes para '.$document.'.',
                'message' => 'Dados fiscais pendentes para '.$document.'. '
                    .collect($problems)->map(fn ($entry) => self::describeProductProblem($entry))->implode('; ').'.',
                'products' => array_values($problems),
            ]]);
        }

        return $items;
    }

    /** "Coxinha: faltam NCM, CST PIS (cadastro geral do produto #12, vale para todas as filiais)". */
    public static function describeProductProblem(array $entry): string
    {
        $detail = ($entry['fields'] ?? []) !== [] ? 'faltam '.implode(', ', $entry['fields']) : (string) ($entry['problem'] ?? '');

        return trim((string) ($entry['product_name'] ?? 'Item')).': '.$detail.' ('.self::describeFixTarget($entry).')';
    }

    /** Onde corrigir a pendência de produto, como aparece para o lojista. */
    public static function describeFixTarget(array $entry): string
    {
        $product = ! empty($entry['product_id']) ? ' do produto #'.$entry['product_id'] : '';

        return ($entry['source'] ?? 'general') === 'branch'
            ? 'perfil próprio da filial '.($entry['branch_name'] ?: '#'.$entry['branch_id']).$product
            : 'cadastro geral'.$product.', vale para todas as filiais';
    }

    /**
     * CST/CSOSN que não combina com o regime do emitente. Se o valor vem do cadastro geral e
     * outra filial da empresa usa o regime dele, quem precisa de valor próprio é esta filial;
     * senão, o cadastro geral está errado para a empresa toda.
     *
     * @return array{0: string, 1: array, 2: string}
     */
    private function regimeProblem(Order $order, int $crt, string $situation, string $source, string $branchName): array
    {
        $expected = $crt === 1 ? 'CSOSN de 3 dígitos (Simples Nacional)' : 'CST de 2 dígitos (regime normal)';
        if ($situation === '') {
            return [$source, [], 'falta o CST ICMS: a filial '.$branchName.' emite com '.$expected];
        }
        if ($source === 'general') {
            $family = ProductFiscalProfile::regimeFamily($situation);
            $otherBranchUsesGeneral = CompanyFiscalConfig::where('company_id', $order->company_id)
                ->whereNotNull('branch_id')->where('branch_id', '!=', $order->branch_id)->whereNotNull('crt')->pluck('crt')
                ->contains(fn ($otherCrt) => ((int) $otherCrt === 1 ? 'simples' : 'normal') === $family);
            if ($otherBranchUsesGeneral) {
                return ['branch', [], 'CST/CSOSN '.$situation.' do cadastro geral incompatível com o regime da filial '.$branchName.': informe nesta filial o '.$expected];
            }

            return ['general', [], 'CST/CSOSN '.$situation.' incompatível com o regime do emitente: use '.$expected];
        }

        return ['branch', [], 'CST/CSOSN '.$situation.' do perfil da filial incompatível com o regime do emitente: use '.$expected];
    }
}
