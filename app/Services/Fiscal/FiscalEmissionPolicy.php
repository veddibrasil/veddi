<?php

namespace App\Services\Fiscal;

use App\DTOs\FiscalEmissionDecision;
use App\Models\Branch;
use App\Models\CompanyFiscalConfig;
use App\Models\Order;

/**
 * Decide o documento fiscal de uma venda (NFC-e modelo 65 ou NF-e modelo 55). Única fonte da
 * regra: tela do pedido, PDV, Controle de notas (individual e lote), pré-checagem e job usam esta
 * classe, nunca o tamanho do documento por conta própria.
 *
 * Regras da versão VERSION (revisar com a contabilidade da filial antes de habilitar):
 * - CNPJ sem habilitação da filial (company_fiscal_configs.nfce_cnpj_enabled): NF-e, como sempre foi.
 * - CNPJ com a filial habilitada: NFC-e só em venda presencial (balcão ou comanda), operação
 *   interna, abaixo do teto do § 4º e comprador não contribuinte com a situação da IE conferida.
 *   Fora disso, NF-e. O operador pode pedir NF-e mesmo quando a NFC-e é permitida. IE ainda não
 *   conferida mantém a NF-e (como antes da política) e sinaliza needs = ie_confirmation: a NFC-e
 *   só sai depois que alguém conferir; pedir NFC-e sem conferir bloqueia.
 * - CPF ou sem documento: NFC-e. A partir do valor do inciso VII, "a", exige identificação;
 *   a partir do § 4º não há modelo suportado (NF-e para CPF não existe no Veddi).
 * - Venda não presencial com CNPJ segue em NF-e: a NFC-e não presencial exige endereço da
 *   entrega no destinatário (inciso VII, "c"), que ainda não é enviado.
 *
 * Só venda a consumidor final é suportada (o builder da NF-e fixa consumidor_final = 1).
 */
class FiscalEmissionPolicy
{
    public const VERSION = '2026-11-16';

    public const PREFERENCES = ['nfce', 'nfe'];

    /** @var array<string, array{0: ?CompanyFiscalConfig, 1: ?Branch}> config e filial emitente por empresa/filial */
    private array $issuers = [];

    public function __construct(private readonly FiscalConfigResolver $resolver) {}

    /** Mesma instância avaliando vários pedidos (lista do Controle de notas) consulta cada filial uma vez. */
    private function issuerFor(?\App\Models\Company $company, ?int $branchId): array
    {
        if (! $company) {
            return [null, null];
        }

        return $this->issuers[$company->id.':'.$branchId] ??= (function () use ($company, $branchId) {
            $config = $this->resolver->forBranch($company, $branchId);
            // Fora de request (job, lote) não há tenant ligado: company_id explícito.
            $issuer = Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($config?->branch_id ?? $branchId);

            return [$config, $issuer];
        })();
    }

    /**
     * @param  string|null  $document  documento digitado agora, substitui o do pedido sem salvar
     * @param  string|null  $preference  'nfce' | 'nfe' | null (automático); sem informar usa o do pedido
     */
    public function forOrder(Order $order, ?string $document = null, ?string $preference = null, bool $usePreference = true): FiscalEmissionDecision
    {
        $order->loadMissing('company');
        [$config, $issuer] = $this->issuerFor($order->company, $order->branch_id);

        $recipient = self::recipientFor($order, $document);
        $presential = self::isPresential($order->order_type, $order->delivery_type);

        return $this->decide(
            config: $config,
            issuerState: $issuer?->state,
            recipient: $recipient,
            total: (float) $order->total,
            presential: $presential,
            // Local da operação: a entrega, quando há endereço; a sede do comprador só na falta dele.
            operationState: $presential ? null : ((($order->delivery_address_id ? $order->deliveryAddressRecord?->state : null) ?: ($recipient['state'] ?? null))),
            preference: $usePreference ? ($preference ?? $order->fiscal_document_preference) : $preference,
        );
    }

    /**
     * Destinatário da emissão: o gravado no pedido (ou do cliente). Documento digitado diferente
     * do gravado vale sozinho — razão social e endereço salvos são de outro documento.
     */
    public static function recipientFor(Order $order, ?string $document): array
    {
        $recipient = $order->fiscal_recipient ?? FiscalRecipient::fromCustomer($order->customer);
        if ($document !== null && FiscalRecipient::normalizeDocument($document) !== FiscalRecipient::normalizeDocument($recipient['document'] ?? '')) {
            return ['document' => $document];
        }

        return $recipient;
    }

    /** PDV antes de criar o pedido: mesma regra, com o carrinho e a filial selecionada. */
    public function forCheckout(int $companyId, ?int $branchId, array $recipient, float $total, string $deliveryType, ?string $preference): FiscalEmissionDecision
    {
        [$config, $issuer] = $this->issuerFor(\App\Models\Company::find($companyId), $branchId);
        $presential = self::isPresential('pdv', $deliveryType);

        return $this->decide($config, $issuer?->state, $recipient, $total, $presential, $presential ? null : ($recipient['state'] ?? null), $preference);
    }

    /** Balcão e comanda. Entrega e retirada combinadas por telefone/chat são não presenciais. */
    public static function isPresential(?string $orderType, ?string $deliveryType): bool
    {
        return $orderType === 'pdv' && in_array($deliveryType, [null, '', 'balcao'], true);
    }

    public function decide(
        ?CompanyFiscalConfig $config,
        ?string $issuerState,
        array $recipient,
        float $total,
        bool $presential,
        ?string $operationState,
        ?string $preference,
    ): FiscalEmissionDecision {
        $document = FiscalRecipient::normalizeDocument($recipient['document'] ?? '');
        $type = $document === '' ? 'none' : (FiscalRecipient::isValidDocument($document) ? FiscalRecipient::documentType($document) : 'invalid');
        $preference = in_array($preference, self::PREFERENCES, true) ? $preference : null;
        $interstate = ! $presential && filled($issuerState) && filled($operationState)
            && mb_strtoupper(trim($operationState)) !== mb_strtoupper(trim($issuerState));
        $threshold = (float) config('fiscal.policy.nfce_identification_threshold', 10000);
        $max = (float) config('fiscal.policy.nfce_max_total', 200000);

        $decision = fn (?string $model, array $allowed, array $reasons, array $needs = []) => new FiscalEmissionDecision(
            $model, $allowed, $reasons, $needs, $type, $interstate, $presential, self::VERSION,
        );

        if ($type === 'invalid') {
            return $decision(null, [], ['CPF ou CNPJ inválido.'], ['document']);
        }

        if ($type === 'none' || $type === FiscalRecipient::TYPE_CPF) {
            if ($preference === 'nfe') {
                return $decision(null, ['65'], [$type === 'none'
                    ? 'NF-e exige o CNPJ do comprador.'
                    : 'NF-e para CPF não é suportada. Emita NFC-e ou informe um CNPJ.'], $type === 'none' ? ['document'] : []);
            }
            if ($total >= $max) {
                return $decision(null, [], ['Venda a partir de R$ '.self::money($max).': NFC-e vedada. Informe o CNPJ do comprador para emitir NF-e.'], ['document']);
            }
            if ($type === 'none' && $total >= $threshold) {
                return $decision(null, [], ['NFC-e a partir de R$ '.self::money($threshold).' exige CPF ou CNPJ do comprador.'], ['document']);
            }

            return $decision('65', ['65'], [$type === 'none' ? 'NFC-e sem identificar o consumidor.' : 'NFC-e com CPF do consumidor.']);
        }

        // CNPJ
        $enabled = (bool) ($config?->nfce_cnpj_enabled ?? false);
        $ie = (string) ($recipient['ie_indicator'] ?? '');
        $blockers = array_values(array_filter([
            ! $enabled ? 'NFC-e com CNPJ não está habilitada nesta filial.' : null,
            ! $presential ? 'Venda não presencial (entrega ou retirada): NFC-e com CNPJ só no balcão ou comanda.' : null,
            $interstate ? 'Operação interestadual: NFC-e só para operação interna.' : null,
            $total >= $max ? 'Venda a partir de R$ '.self::money($max).': NFC-e vedada.' : null,
            in_array($ie, ['1', '2'], true) ? 'Comprador contribuinte ou isento de IE: NFC-e só para não contribuinte.' : null,
        ]));
        $ieConfirmed = $ie === '9' && (bool) ($recipient['ie_confirmed'] ?? false);
        $nfceAllowed = $blockers === [] && $ieConfirmed;
        $needsIe = $blockers === [] && ! $ieConfirmed;
        $allowed = $nfceAllowed ? ['65', '55'] : ['55'];

        if ($preference === 'nfe') {
            return $decision('55', $allowed, ['NF-e solicitada para o CNPJ do comprador.']);
        }
        if ($nfceAllowed) {
            return $decision('65', $allowed, ['NFC-e com CNPJ: venda presencial a comprador não contribuinte.']);
        }
        if ($needsIe && $preference === 'nfce') {
            return $decision(null, $allowed, ['Confirme a situação da inscrição estadual do comprador para emitir NFC-e com CNPJ, ou escolha NF-e.'], ['ie_confirmation']);
        }
        if ($needsIe) {
            return $decision('55', $allowed, ['NF-e para o CNPJ do comprador. Para NFC-e com CNPJ, confirme a situação da inscrição estadual.'], ['ie_confirmation']);
        }
        if ($preference === 'nfce') {
            return $decision(null, $allowed, array_map(fn ($reason) => 'NFC-e indisponível: '.$reason, $blockers));
        }

        return $decision('55', $allowed, ['NF-e para o CNPJ do comprador.'.($enabled ? ' '.$blockers[0] : '')]);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
