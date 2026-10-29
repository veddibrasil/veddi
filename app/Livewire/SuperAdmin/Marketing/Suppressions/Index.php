<?php

namespace App\Livewire\SuperAdmin\Marketing\Suppressions;

use App\Enums\EmailSuppressionReason;
use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\EmailSuppression;
use App\Services\Marketing\SuppressionCleanup;
use App\Services\Marketing\SuppressionService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Lista global de supressão: busca, inclusão manual, importação CSV, limpeza pronta e remoção com motivo. */
class Index extends Component
{
    use RequiresSuperAdmin;
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'busca')]
    public string $search = '';

    #[Url(as: 'motivo')]
    public string $reason = '';

    public string $newEmail = '';

    public string $newNote = '';

    public $csv = null;

    public string $importNote = '';

    public ?int $removingId = null;

    public bool $confirmingRemove = false;

    public string $removalReason = '';

    public ?string $notice = null;

    public ?string $errorNotice = null;

    /** Limpeza da lista: só varre os usuários quando o super admin pede. */
    public bool $cleanupScanned = false;

    public ?string $cleanupRule = null;

    public bool $confirmingCleanup = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingReason(): void
    {
        $this->resetPage();
    }

    public function add(SuppressionService $suppressions): void
    {
        $this->newEmail = SuppressionService::normalize($this->newEmail);

        $this->validate([
            'newEmail' => ['required', 'email', 'max:255'],
            'newNote' => ['nullable', 'string', 'max:500'],
        ], attributes: ['newEmail' => 'e-mail', 'newNote' => 'observação']);

        $suppression = $suppressions->suppress($this->newEmail, EmailSuppressionReason::Manual, null, $this->newNote ?: null, auth()->id());

        $this->notice = $suppression->wasRecentlyCreated ? 'E-mail suprimido.' : 'Este e-mail já estava na lista.';
        $this->reset('newEmail', 'newNote');
    }

    public function import(SuppressionService $suppressions): void
    {
        $this->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'importNote' => ['nullable', 'string', 'max:500'],
        ], attributes: ['csv' => 'arquivo']);

        $result = $suppressions->importCsv($this->csv->getRealPath(), $this->importNote ?: 'Importação CSV', auth()->user());

        $this->notice = "Importação concluída: {$result['added']} novo(s), {$result['existing']} já existente(s), {$result['invalid']} inválido(s).";
        $this->reset('csv', 'importNote');
    }

    public function scanCleanup(): void
    {
        $this->cleanupScanned = true;
        unset($this->cleanup);
    }

    public function confirmCleanup(string $rule): void
    {
        $this->cleanupRule = $rule;
        $this->confirmingCleanup = true;
    }

    /** Os e-mails são recalculados no servidor: a tela só escolhe a regra. */
    public function runCleanup(SuppressionCleanup $cleanup): void
    {
        $this->confirmingCleanup = false;
        $this->errorNotice = null;
        $rule = $this->cleanupRule;
        $this->cleanupRule = null;

        if (! $rule || ! array_key_exists($rule, $cleanup->rules())) {
            $this->errorNotice = 'Regra de limpeza desconhecida.';

            return;
        }

        $added = $cleanup->suppress($rule, auth()->user());
        $label = $cleanup->rules()[$rule]['label'];

        $this->notice = $added === 1 ? "1 e-mail suprimido ({$label})." : "{$added} e-mails suprimidos ({$label}).";
        unset($this->cleanup);
        $this->resetPage();
    }

    /** @return array<string, array{label: string, description: string, emails: list<string>}> */
    #[Computed]
    public function cleanup(): array
    {
        if (! $this->cleanupScanned) {
            return [];
        }

        $cleanup = app(SuppressionCleanup::class);
        $found = $cleanup->scan();

        return collect($cleanup->rules())->map(fn (array $rule, string $key) => $rule + ['emails' => $found[$key] ?? []])->all();
    }

    public function confirmRemove(int $suppressionId): void
    {
        $this->removingId = $suppressionId;
        $this->removalReason = '';
        $this->resetErrorBag();
        $this->confirmingRemove = true;
    }

    public function remove(SuppressionService $suppressions): void
    {
        $this->validate(['removalReason' => ['required', 'string', 'min:5', 'max:300']], attributes: ['removalReason' => 'motivo']);

        $suppression = EmailSuppression::findOrFail($this->removingId);
        $suppressions->remove($suppression, $this->removalReason, auth()->user());

        $this->confirmingRemove = false;
        $this->removingId = null;
        $this->notice = 'Supressão removida. O motivo ficou registrado.';
    }

    public function render()
    {
        return view('livewire.super-admin.marketing.suppressions.index', [
            'suppressions' => EmailSuppression::with(['sourceCampaign:id,name', 'creator:id,name'])
                ->when($this->search !== '', fn ($query) => $query->where('email', 'like', '%'.mb_strtolower(trim($this->search)).'%'))
                ->when($this->reason !== '', fn ($query) => $query->where('reason', $this->reason))
                ->latest()
                ->paginate(25),
            'reasons' => EmailSuppressionReason::options(),
            'totals' => EmailSuppression::groupBy('reason')->selectRaw('reason, COUNT(*) as total')->pluck('total', 'reason'),
        ])->layout('layouts.app', ['title' => 'Super Admin — Supressões de e-mail']);
    }
}
