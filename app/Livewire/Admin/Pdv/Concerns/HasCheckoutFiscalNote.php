<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Contracts\CnpjLookupInterface;
use App\Jobs\IssueFiscalNote;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Fiscal\FiscalConfigResolver;
use App\Services\Fiscal\FiscalRecipient;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Compartilhado entre Terminal (HasPaymentFlow) e TabTerminal (HasOpenTabs).
 *
 * A nota fiscal não é mais emitida automaticamente quando o pedido vira 'paid': no PDV ela só
 * sai quando o caixa marca "Emitir e imprimir nota fiscal". Sem a marcação, o pedido fica
 * "sem nota" no Controle de notas fiscais (Admin\Fiscal\IssueControl), onde pode ser emitido
 * depois — individualmente ou em lote.
 *
 * Marcando a emissão aparece o "CPF/CNPJ na nota", que não depende de cliente cadastrado
 * (venda de balcão é a mais comum). CPF ou vazio = NFC-e; CNPJ = NF-e, com os dados da
 * empresa vindos do cadastro do cliente ou da consulta pública do CNPJ, pro caixa conferir.
 */
trait HasCheckoutFiscalNote
{
    /** CPF/CNPJ como o caixa digitou (com máscara) — normalizado só ao montar o destinatário. */
    public string $fiscalDocument = '';

    /** Dados da empresa pra NF-e (formato FiscalRecipient), preenchidos quando o documento é um CNPJ. */
    public array $fiscalCompany = [];

    public bool $showFiscalCompanyForm = false;

    /** Grava o destinatário no cadastro do cliente selecionado pra próximas compras. */
    public bool $rememberFiscalData = false;

    /** Aviso da consulta do CNPJ (falhou, situação diferente de ATIVA) — texto montado no servidor. */
    #[Locked]
    public ?string $fiscalCompanyNotice = null;

    public function updatedIssueFiscalNote(bool $value): void
    {
        if ($value) {
            $this->prefillFiscalDocumentFromCustomer();
        }
    }

    public function updatedFiscalDocument(): void
    {
        $this->resetValidation('fiscalDocument');
        $digits = preg_replace('/\D/', '', $this->fiscalDocument);

        if (strlen($digits) !== 14) {
            $this->fiscalCompany = [];
            $this->fiscalCompanyNotice = null;

            return;
        }

        if (! FiscalRecipient::isValidDocument($digits) || ($this->fiscalCompany['document'] ?? null) === $digits) {
            return;
        }

        $this->loadFiscalCompany($digits);
    }

    public function clearFiscalDocument(): void
    {
        $this->fiscalDocument = '';
        $this->fiscalCompany = [];
        $this->fiscalCompanyNotice = null;
        $this->resetValidation(['fiscalDocument', 'fiscalCompany.*']);
    }

    public function openFiscalCompanyForm(): void
    {
        $this->showFiscalCompanyForm = true;
    }

    /** "Usar estes dados" do painel da empresa: só fecha se o destinatário já for válido pra NF-e. */
    public function confirmFiscalCompany(): void
    {
        $this->validatedFiscalRecipient();
        $this->showFiscalCompanyForm = false;
    }

    /** Situação do documento digitado, pro texto embaixo do campo. */
    #[Computed]
    public function fiscalDocumentState(): string
    {
        $digits = preg_replace('/\D/', '', $this->fiscalDocument);

        return match (true) {
            $digits === '' => 'empty',
            ! in_array(strlen($digits), [11, 14], true) => 'typing',
            ! FiscalRecipient::isValidDocument($digits) => 'invalid',
            strlen($digits) === 11 => 'cpf',
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

        if (! $this->issueFiscalNote || ! $this->canUseFiscalNotes) {
            return $customer ? FiscalRecipient::fromCustomer($customer) : null;
        }

        $recipient = $this->validatedFiscalRecipient();

        if ($this->rememberFiscalData && $customer && $recipient['document'] !== '') {
            $customer->update(['fiscal_recipient' => $recipient]);
        }

        return $recipient;
    }

    private function validatedFiscalRecipient(): array
    {
        $digits = preg_replace('/\D/', '', $this->fiscalDocument);
        $isCompany = strlen($digits) === 14;
        $recipient = $isCompany ? [...$this->fiscalCompany, 'document' => $digits] : ['document' => $digits];

        try {
            return FiscalRecipient::validate($recipient);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if ($isCompany && array_diff_key($errors, ['document' => true]) !== []) {
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
            FiscalRecipient::validate([...$this->fiscalCompany, 'document' => preg_replace('/\D/', '', $this->fiscalDocument)]);

            return true;
        } catch (ValidationException) {
            return false;
        }
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

        $digits = preg_replace('/\D/', '', FiscalRecipient::fromCustomer($customer)['document'] ?? '');

        if (! FiscalRecipient::isValidDocument($digits)) {
            return;
        }

        $this->fiscalDocument = FiscalRecipient::formatDocument($digits);

        if (strlen($digits) === 14) {
            $this->loadFiscalCompany($digits);
        }
    }

    /** Dados da empresa: do cadastro do cliente quando é o mesmo CNPJ, senão da consulta pública. */
    private function loadFiscalCompany(string $cnpj): void
    {
        $this->resetValidation('fiscalCompany.*');
        $saved = FiscalRecipient::fromCustomer($this->checkoutCustomer());

        if (preg_replace('/\D/', '', $saved['document'] ?? '') === $cnpj && filled($saved['name'] ?? null)) {
            $this->fiscalCompany = [...$saved, 'document' => $cnpj];
            $this->fiscalCompanyNotice = null;

            return;
        }

        $found = app(CnpjLookupInterface::class)->find($cnpj);

        $this->fiscalCompany = [
            'document' => $cnpj,
            'name' => $found['name'] ?? '',
            'email' => $found['email'] ?? '',
            'street' => $found['street'] ?? '',
            'number' => $found['number'] ?? '',
            'complement' => $found['complement'] ?? '',
            'neighborhood' => $found['neighborhood'] ?? '',
            'city' => $found['city'] ?? '',
            'state' => $found['state'] ?? '',
            'zip' => $found['zip'] ?? '',
            // Venda de restaurante pra empresa é quase sempre consumo final sem ICMS a recolher.
            'ie_indicator' => '9',
            'ie' => '',
        ];

        $this->fiscalCompanyNotice = match (true) {
            $found === null => 'Não foi possível consultar o CNPJ. Preencha os dados da empresa.',
            filled($found['status'] ?? null) && mb_strtoupper($found['status']) !== 'ATIVA' => "Situação do CNPJ na Receita: {$found['status']}. Confira antes de emitir.",
            default => null,
        };

        if ($found === null) {
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
        $this->issueFiscalNote = false;
        $this->fiscalDocument = '';
        $this->fiscalCompany = [];
        $this->fiscalCompanyNotice = null;
        $this->showFiscalCompanyForm = false;
        $this->rememberFiscalData = false;
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
        if (! $this->issueFiscalNote || ! $this->canUseFiscalNotes || $order->status !== 'paid') {
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

            IssueFiscalNote::dispatch($order->id, null, auth()->id());

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
