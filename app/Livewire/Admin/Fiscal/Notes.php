<?php

namespace App\Livewire\Admin\Fiscal;

use App\Jobs\IssueFiscalNote;
use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalNoteService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Notes extends Component
{
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $modelFilter = '';

    public bool $showCorrectionModal = false;

    public ?int $correctionNoteId = null;

    public string $correctionText = '';

    public bool $showInvalidationModal = false;

    public string $invalidationConfigId = '';

    public array $invalidation = ['serie' => '1', 'numero_inicial' => '', 'numero_final' => '', 'justificativa' => ''];

    private function authorizeFiscalWrite(): void
    {
        $company = app('current.company');
        abort_unless($company->canUseFiscalNotes() && auth()->user()->hasPermission('fiscal.issue', $company), 403);
    }

    public function updatedModelFilter(): void
    {
        $this->resetPage();
    }

    public function openCorrection(int $noteId): void
    {
        $this->authorizeFiscalWrite();
        $note = FiscalNote::where('company_id', app('current.company')->id)->findOrFail($noteId);
        abort_unless($note->model === '55' && $note->status === 'authorized', 422);
        $this->correctionNoteId = $note->id;
        $this->correctionText = '';
        $this->showCorrectionModal = true;
    }

    public function confirmCorrection(): void
    {
        $this->authorizeFiscalWrite();
        $this->validate(['correctionText' => 'required|string|min:15|max:1000']);
        $note = FiscalNote::where('company_id', app('current.company')->id)->findOrFail($this->correctionNoteId);
        try {
            $event = app(\App\Services\Fiscal\FiscalEventService::class)->correction($note, $this->correctionText);
            $this->showCorrectionModal = false;
            session()->flash($event->status === 'error' ? 'error' : 'status', $event->status === 'authorized' ? 'Carta de correção autorizada.' : 'Evento registrado: '.$event->status.'. Consulte o histórico.');
        } catch (\RuntimeException $e) {
            $this->addError('correctionText', $e->getMessage());
        }
    }

    public function openInvalidation(): void
    {
        $this->authorizeFiscalWrite();
        $this->showInvalidationModal = true;
    }

    public function confirmInvalidation(): void
    {
        $this->authorizeFiscalWrite();
        $this->validate(['invalidationConfigId' => 'required|integer']);
        $config = \App\Models\CompanyFiscalConfig::where('company_id', app('current.company')->id)->findOrFail($this->invalidationConfigId);
        try {
            $event = app(\App\Services\Fiscal\FiscalEventService::class)->invalidate($config, $this->invalidation);
            $this->showInvalidationModal = false;
            session()->flash($event->status === 'error' ? 'error' : 'status', 'Inutilização registrada: '.$event->status.'. Consulte o histórico.');
        } catch (\RuntimeException $e) {
            $this->addError('invalidationConfigId', $e->getMessage());
        }
    }

    public function consultEvent(int $eventId): void
    {
        $this->authorizeFiscalWrite();
        $event = \App\Models\FiscalNoteEvent::where('company_id', app('current.company')->id)->findOrFail($eventId);
        app(\App\Services\Fiscal\FiscalEventService::class)->reconcile($event);
        session()->flash('status', 'Consulta do evento concluída.');
    }

    public function consult(int $noteId): void
    {
        $this->authorizeFiscalWrite();
        $note = FiscalNote::where('company_id', app('current.company')->id)->findOrFail($noteId);
        app(FiscalNoteService::class)->reconcile($note);
        session()->flash('status', 'Consulta fiscal concluída.');
    }

    public bool $canView = false;

    public bool $canIssue = false;

    public bool $showCancelModal = false;

    public ?int $cancelNoteId = null;

    public string $cancelJustification = '';

    public bool $showErrorModal = false;

    public ?int $errorNoteId = null;

    public bool $showReissueModal = false;

    /** Locked: a nota confirmada é a que o servidor abriu no modal, não uma trocada pelo navegador. */
    #[Locked]
    public ?int $reissueNoteId = null;

    public function mount(): void
    {
        $company = app('current.company');
        $user = auth()->user();

        // Mesmo padrão dos outros add-ons pagos (ver Config::mount()) — sem isso, a tela
        // de notas ficava acessível mesmo pra empresa que nunca contratou o módulo.
        abort_unless($company->fiscal_notes_enabled, 403, 'Módulo de nota fiscal não está habilitado para esta empresa.');

        $this->canView = $user->hasPermission('fiscal.view', $company);
        $this->canIssue = $user->hasPermission('fiscal.issue', $company);

        abort_unless($this->canView, 403);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openCancelModal(int $noteId): void
    {
        $this->authorizeFiscalWrite();

        $this->cancelNoteId = $noteId;
        $this->cancelJustification = '';
        $this->showCancelModal = true;
    }

    public function closeCancelModal(): void
    {
        $this->showCancelModal = false;
        $this->cancelNoteId = null;
        $this->cancelJustification = '';
    }

    public function confirmCancel(): void
    {
        $this->authorizeFiscalWrite();

        $this->validate([
            'cancelJustification' => ['required', 'string', 'min:15', 'max:255'],
        ]);

        $note = FiscalNote::find($this->cancelNoteId);

        if (! $note || ! $note->isCancellable()) {
            session()->flash('error', 'Nota não encontrada ou não pode ser cancelada.');
            $this->closeCancelModal();

            return;
        }

        app(FiscalNoteService::class)->cancel($note, $this->cancelJustification);

        $this->closeCancelModal();
        session()->flash('status', 'Cancelamento solicitado.');
    }

    public function openErrorModal(int $noteId): void
    {
        $this->errorNoteId = $noteId;
        $this->showErrorModal = true;
    }

    public function closeErrorModal(): void
    {
        $this->showErrorModal = false;
        $this->errorNoteId = null;
    }

    public function openReissueModal(int $noteId): void
    {
        $this->authorizeFiscalWrite();

        if (! $this->findReissuableNote($noteId)) {
            session()->flash('error', 'Nota não pode ser reemitida.');

            return;
        }

        $this->reissueNoteId = $noteId;
        $this->showReissueModal = true;
    }

    public function closeReissueModal(): void
    {
        $this->showReissueModal = false;
        $this->reissueNoteId = null;
    }

    public function confirmReissue(): void
    {
        $this->authorizeFiscalWrite();

        $note = $this->reissueNoteId ? $this->findReissuableNote($this->reissueNoteId) : null;
        $this->closeReissueModal();

        if (! $note) {
            session()->flash('error', 'Nota não pode ser reemitida.');

            return;
        }

        // Nota "error" pode ter sido timeout de rede depois que a Focus (e por trás
        // dela a SEFAZ) já recebeu a tentativa anterior — reemitir sem checar arrisca
        // reciclar o mesmo número de NFC-e e cair em "Duplicidade de NF-e" na SEFAZ.
        // Reconsulta pelo ref antes de decidir se ainda há o que reemitir.
        if ($note->provider_reference) {
            $note = app(FiscalNoteService::class)->reconcile($note);

            if (! in_array($note->status, ['error', 'rejected'], true)) {
                session()->flash('status', 'A tentativa anterior já havia sido processada pela Focus NFe — status atualizado, nada foi reemitido.');

                return;
            }
        }

        // FiscalNoteService::issue() já garante (via lock) que não cria uma nota
        // ativa duplicada caso outra emissão para o mesmo pedido esteja em andamento.
        IssueFiscalNote::dispatch($note->order_id, null, auth()->id());

        session()->flash('status', 'Reemissão de nota fiscal enfileirada.');
    }

    private function findReissuableNote(int $noteId): ?FiscalNote
    {
        $note = FiscalNote::where('company_id', app('current.company')->id)->with('order')->find($noteId);

        return $note && $note->order_id && in_array($note->status, ['error', 'rejected'], true) ? $note : null;
    }

    public function render()
    {
        $query = FiscalNote::with(['order'])
            ->orderByDesc('created_at');

        if ($this->modelFilter) {
            $query->where('model', $this->modelFilter);
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('access_key', 'like', '%'.$this->search.'%')
                    ->orWhere('provider_reference', 'like', '%'.$this->search.'%')
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', '%'.$this->search.'%'));
            });
        }

        return view('livewire.admin.fiscal.notes', [
            'notes' => $query->paginate(15),
            'fiscalEvents' => \App\Models\FiscalNoteEvent::latest()->limit(20)->get(),
            'emitterConfigs' => \App\Models\CompanyFiscalConfig::where('company_id', app('current.company')->id)->where('nfe_enabled', true)->with('branch')->get(),
            'errorNote' => $this->errorNoteId ? FiscalNote::find($this->errorNoteId) : null,
            'reissueNote' => $this->showReissueModal && $this->reissueNoteId ? $this->findReissuableNote($this->reissueNoteId) : null,
        ])->layout('layouts.app', ['title' => 'Notas Fiscais']);
    }
}
