<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Jobs\IssueFiscalNote;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Fiscal\FiscalConfigResolver;
use App\Services\Fiscal\FiscalPostalCodeLookup;
use App\Services\Fiscal\FiscalRecipient;
use App\Services\Fiscal\FiscalRecipientAutofill;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Compartilhado entre Terminal (HasPaymentFlow) e TabTerminal (HasOpenTabs).
 *
 * No PDV a nota sai na hora quando o caixa marca "Emitir e imprimir nota fiscal". Com a NFC-e
 * automática ligada na filial, a caixa já vem marcada; desmarcar grava orders.fiscal_auto_skip e
 * o pedido fica "sem nota" no Controle de notas fiscais (Admin\Fiscal\IssueControl), onde pode
 * ser emitido depois. Venda a receber ou agendada recebe a NFC-e automática quando for paga.
 *
 * Marcando a emissão aparece o "CPF/CNPJ na nota", que não depende de cliente cadastrado
 * (venda de balcão é a mais comum). O modelo vem da FiscalEmissionPolicy: CPF pode receber
 * NFC-e ou NF-e; CNPJ pode receber NFC-e quando a filial está liberada. Dados do comprador
 * vêm do cadastro do cliente ou da consulta pública do CNPJ, para o caixa conferir.
 */
trait HasCheckoutFiscalNote
{
    /** CPF/CNPJ como o caixa digitou (com máscara) — normalizado só ao montar o destinatário. */
    public string $fiscalDocument = '';

    /** Dados fiscais do comprador (CPF ou CNPJ), no formato FiscalRecipient. */
    public array $fiscalCompany = [];

    public bool $showFiscalCompanyForm = false;

    /** '' = automático pela política; 'nfce' | 'nfe' = escolha do caixa. Vai para o pedido. */
    public string $fiscalDocumentChoice = '';

    /** Grava o destinatário no cadastro do cliente selecionado pra próximas compras. */
    public bool $rememberFiscalData = false;

    /** Aviso da consulta do CNPJ (falhou, situação diferente de ATIVA) — texto montado no servidor. */
    #[Locked]
    public ?string $fiscalCompanyNotice = null;

    /**
     * Filial com a NFC-e automática ligada: "Emitir e imprimir" já vem marcado e desmarcar grava
     * orders.fiscal_auto_skip (o pedido fica sem nota, no Controle). Recalculado ao trocar de filial.
     */
    #[Locked]
    public bool $fiscalAutoIssueOn = false;

    /** Lê a chave da filial selecionada e aplica o padrão do "Emitir e imprimir nota fiscal". */
    private function syncFiscalAutoIssueDefault(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $config = $this->canUseFiscalNotes && $company && $this->selectedBranchId
            ? app(FiscalConfigResolver::class)->forBranch($company, $this->selectedBranchId)
            : null;

        $this->fiscalAutoIssueOn = (bool) $config?->autoIssueActive();
        $this->issueFiscalNote = $this->fiscalAutoIssueOn;
    }

    /**
     * Venda que nasce paga com a NFC-e automática ligada: o PDV decide a nota na hora (emite se
     * marcado, fica sem nota se desmarcado) e grava orders.fiscal_auto_skip para a automática não
     * repetir nem contrariar o caixa. Lê a chave da filial agora, não a da abertura da tela: o
     * disjuntor pode ter pausado no meio do turno. Venda a receber ou agendada não decide aqui:
     * a automática emite quando pagar.
     *
     * @return array<string, mixed>
     */
    private function checkoutFiscalAutoAttributes(string $status): array
    {
        if (! $this->canUseFiscalNotes || $status !== 'paid') {
            return [];
        }

        $company = app('current.company');
        $autoOn = (bool) app(FiscalConfigResolver::class)->forBranch($company, $this->selectedBranchId)?->autoIssueActive();

        return $autoOn ? ['fiscal_auto_skip' => true] : [];
    }

    public function updatedIssueFiscalNote(bool $value): void
    {
        if ($value) {
            $this->prefillFiscalDocumentFromCustomer();
        }
    }

    public function updatedFiscalDocument(): void
    {
        $this->resetValidation('fiscalDocument');
        $this->fiscalDocumentChoice = '';
        $digits = FiscalRecipient::normalizeDocument($this->fiscalDocument);

        if (! FiscalRecipient::isValidDocument($digits)) {
            $this->fiscalCompany = [];
            $this->fiscalCompanyNotice = null;

            return;
        }

        if (($this->fiscalCompany['document'] ?? null) === $digits) {
            return;
        }

        $this->loadFiscalBuyer($digits);
    }

    public function clearFiscalDocument(): void
    {
        $this->fiscalDocument = '';
        $this->fiscalCompany = [];
        $this->fiscalCompanyNotice = null;
        $this->fiscalDocumentChoice = '';
        $this->resetValidation(['fiscalDocument', 'fiscalCompany.*']);
    }

    public function openFiscalCompanyForm(): void
    {
        $this->showFiscalCompanyForm = true;
    }

    public function updatedFiscalDocumentChoice(): void
    {
        $this->resetValidation(['fiscalDocument', 'fiscalCompany.*']);
        if ($this->fiscalDecision?->model === '55' && ! $this->fiscalCompanyComplete()) {
            $this->showFiscalCompanyForm = true;
        }
    }

    public function updatedFiscalCompany(mixed $value, ?string $key = null): void
    {
        if ($key !== 'zip' || strlen(preg_replace('/\D/', '', (string) $value)) !== 8) {
            return;
        }
        $address = app(FiscalPostalCodeLookup::class)->find((string) $value);
        $this->fiscalCompanyNotice = $address === null ? 'CEP não encontrado ou consulta indisponível. Preencha o endereço manualmente.' : null;
        $this->fiscalCompany = array_replace($this->fiscalCompany, $address ?? []);
    }

    /** "Usar estes dados": só fecha quando o destinatário é válido para o documento escolhido. */
    public function confirmFiscalCompany(): void
    {
        // "Usar estes dados" é a conferência do caixa, inclusive da situação da IE.
        $this->fiscalCompany['ie_confirmed'] = true;
        $this->validatedFiscalRecipient();
        $this->resetValidation('fiscalDocument');
        $this->showFiscalCompanyForm = false;
    }

    /** Documento que sai com esta venda (NFC-e/NF-e), pela mesma política do pedido e do job. */
    #[Computed]
    public function fiscalDecision(): ?\App\DTOs\FiscalEmissionDecision
    {
        if (! $this->issueFiscalNote || ! $this->canUseFiscalNotes) {
            return null;
        }
        $recipient = $this->checkoutFiscalBuyer();

        return app(\App\Services\Fiscal\FiscalEmissionPolicy::class)->forCheckout(
            app('current.company')->id,
            $this->selectedBranchId,
            $recipient,
            // Total final (taxas, entrega e desconto), o mesmo que vira orders.total.
            method_exists($this, 'cartTotalAfterDiscount') ? (float) $this->cartTotalAfterDiscount() : 0.0,
            $this->deliveryType ?? 'balcao',
            $this->fiscalDocumentChoice ?: null,
        );
    }

    /** Situação do documento digitado, pro texto embaixo do campo. */
    #[Computed]
    public function fiscalDocumentState(): string
    {
        $digits = FiscalRecipient::normalizeDocument($this->fiscalDocument);
        $type = FiscalRecipient::documentType($digits);

        return match (true) {
            $digits === '' => 'empty',
            $type === null => 'typing',
            ! FiscalRecipient::isValidDocument($digits) => 'invalid',
            $type === FiscalRecipient::TYPE_CPF => 'cpf',
            $this->fiscalCompanyComplete() => 'cnpj',
            default => 'cnpj_incomplete',
        };
    }

    /**
     * Destinatário gravado no pedido.
     *
     * Emitindo agora: o que o caixa informou, validado — erro trava a venda apontando o campo.
     * Sem emitir agora: só o padrão do cadastro do cliente, sem validar — pendência fiscal não
     * pode travar uma venda que não vai ter nota agora; o Controle de notas mostra o que falta
     * quando alguém for emitir. null = pedido herda do cliente na criação (Order::booted()).
     */
    private function checkoutFiscalRecipient(): ?array
    {
        $customer = $this->checkoutCustomer();

        // Marcado (padrão com a automática ligada) mas escondido porque a venda não é paga agora:
        // a nota sai depois, então nada do formulário fiscal pode travar a venda.
        $hidden = method_exists($this, 'checkoutSettlesNow') && ! $this->fiscalNoteAvailableOnCheckout;
        if (! $this->issueFiscalNote || ! $this->canUseFiscalNotes || $hidden) {
            return $customer ? FiscalRecipient::fromCustomer($customer) : null;
        }

        // Erro de uma tentativa anterior (já corrigido) não pode continuar na tela.
        $this->resetValidation('fiscalDocument');
        $recipient = $this->validatedFiscalRecipient();

        // Documento permitido para esta venda: bloqueio da política vira erro no campo, antes de
        // gravar o pedido (ex.: NFC-e de valor alto sem identificação, IE a conferir).
        $decision = $this->fiscalDecision;
        if ($decision?->blocked()) {
            if (in_array('ie_confirmation', $decision->needs, true)) {
                $this->showFiscalCompanyForm = true;
            }

            throw ValidationException::withMessages(['fiscalDocument' => implode(' ', $decision->reasons)]);
        }

        if ($this->rememberFiscalData && $customer && $recipient['document'] !== '') {
            $customer->update(['fiscal_recipient' => $recipient]);
        }

        return $recipient;
    }

    /** Escolha explícita do caixa gravada no pedido (null = automático ou sem emissão agora). */
    private function checkoutFiscalPreference(): ?string
    {
        return $this->issueFiscalNote && $this->canUseFiscalNotes && in_array($this->fiscalDocumentChoice, ['nfce', 'nfe'], true)
            ? $this->fiscalDocumentChoice
            : null;
    }

    private function validatedFiscalRecipient(): array
    {
        $this->resetValidation(['fiscalDocument', 'fiscalCompany.*']);
        $recipient = $this->checkoutFiscalBuyer();

        try {
            return FiscalRecipient::validate($recipient, $this->fiscalDecision?->model);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (array_diff_key($errors, ['document' => true]) !== []) {
                $this->showFiscalCompanyForm = true;
            }

            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($messages, $field) => [
                $field === 'document' ? 'fiscalDocument' : 'fiscalCompany.'.$field => $messages,
            ])->all());
        }
    }

    private function fiscalCompanyComplete(): bool
    {
        try {
            FiscalRecipient::validate($this->checkoutFiscalBuyer(), $this->fiscalDecision?->model);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** Não reaproveita nome, endereço ou IE de outro CPF/CNPJ digitado anteriormente. */
    private function checkoutFiscalBuyer(): array
    {
        $document = FiscalRecipient::normalizeDocument($this->fiscalDocument);

        return FiscalRecipient::normalizeDocument($this->fiscalCompany['document'] ?? '') === $document
            ? [...$this->fiscalCompany, 'document' => $document]
            : ['document' => $document];
    }

    /**
     * Marcar a emissão (ou escolher o cliente com ela marcada) traz o documento do cadastro pro
     * campo — visível ao lado do checkbox, pro caixa confirmar com o cliente ou apagar.
     */
    private function prefillFiscalDocumentFromCustomer(): void
    {
        if (! $this->issueFiscalNote || $this->fiscalDocument !== '' || ! ($customer = $this->checkoutCustomer())) {
            return;
        }

        $digits = FiscalRecipient::normalizeDocument(FiscalRecipient::fromCustomer($customer)['document'] ?? '');

        if (! FiscalRecipient::isValidDocument($digits)) {
            return;
        }

        $this->fiscalDocument = FiscalRecipient::formatDocument($digits);

        $this->loadFiscalBuyer($digits);
    }

    /** Cadastro do mesmo CPF/CNPJ; consulta pública somente para CNPJ. */
    private function loadFiscalBuyer(string $document): void
    {
        $this->resetValidation('fiscalCompany.*');
        $result = app(FiscalRecipientAutofill::class)->forDocument(
            app('current.company')->id, $document, $this->checkoutCustomer(), $this->fiscalCompany,
        );
        $this->fiscalCompany = FiscalRecipient::draft($result['recipient']);
        $this->fiscalCompanyNotice = $result['notice'];
        if ($result['notice'] !== null && ! $this->fiscalCompanyComplete()) {
            $this->showFiscalCompanyForm = true;
        }
    }

    private function checkoutCustomer(): ?Customer
    {
        return $this->customerId
            ? Customer::where('company_id', app('current.company')->id)->find($this->customerId)
            : null;
    }

    private function resetCheckoutFiscalState(): void
    {
        // Relê a chave a cada venda: pausada pelo disjuntor ou desligada, a próxima vem desmarcada.
        $this->syncFiscalAutoIssueDefault();
        $this->fiscalDocument = '';
        $this->fiscalCompany = [];
        $this->fiscalCompanyNotice = null;
        $this->showFiscalCompanyForm = false;
        $this->rememberFiscalData = false;
        $this->fiscalDocumentChoice = '';
    }

    /**
     * Enfileira a emissão quando o caixa pediu. Só pedido 'paid' de verdade: agendado fica
     * 'scheduled' até a hora combinada e é emitido pelo Controle de notas depois.
     *
     * Roda depois do commit, com o pedido já pago — falha aqui (fila fora do ar) nunca pode
     * virar "erro ao processar" e induzir o operador a refazer a venda; só registra no log.
     *
     * @return bool se a emissão foi enfileirada (o JS só espera a autorização pra imprimir nesse caso)
     */
    private function issueFiscalNoteIfRequested(Order $order): bool
    {
        if (! $this->issueFiscalNote || ! $this->canUseFiscalNotes || $order->status !== 'paid' || $order->isStaffConsumption()) {
            return false;
        }

        try {
            $config = app(FiscalConfigResolver::class)->forBranch(app('current.company'), $order->branch_id);

            if (! $config?->enabled) {
                $this->dispatch('pdv-toast',
                    message: 'Nota fiscal não emitida: a filial não tem configuração fiscal ativa. O pedido ficou no Controle de notas.',
                    duration: 6000,
                );

                return false;
            }

            // Mesma política no pedido gravado, comparada com o que o caixa viu na tela: bloqueio
            // ou modelo diferente (valor, entrega, filial) não emite outro documento em silêncio.
            $seen = $this->fiscalDecision?->model;
            $decision = app(\App\Services\Fiscal\FiscalEmissionPolicy::class)->forOrder($order->fresh());
            if ($decision->blocked() || ($seen !== null && $seen !== $decision->model)) {
                $this->dispatch('pdv-toast',
                    message: 'Nota fiscal não emitida: '.($decision->blocked()
                        ? ($decision->reasons[0] ?? 'confira o documento')
                        : 'o documento da venda mudou para '.\App\DTOs\FiscalEmissionDecision::label($decision->model).'.')
                        .' O pedido ficou no Controle de notas para conferência.',
                    duration: 6000,
                );

                return false;
            }

            IssueFiscalNote::dispatch($order->id, null, auth()->id(), $seen ?? $decision->model);

            return true;
        } catch (\Throwable $e) {
            Log::channel('fiscal')->error('PDV: falha ao enfileirar emissão de nota fiscal (pedido já pago)', [
                'order_id' => $order->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
