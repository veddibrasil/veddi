<?php

namespace App\Livewire\SuperAdmin\Marketing\Templates;

use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\EmailTemplate;
use App\Services\Marketing\DefaultEmailTemplates;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use RequiresSuperAdmin;
    use WithPagination;

    public string $search = '';

    public ?int $deletingId = null;

    public bool $confirmingDelete = false;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->notice = session('marketing_notice');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function duplicate(int $templateId): void
    {
        $template = EmailTemplate::findOrFail($templateId);

        $copy = $template->replicate(['created_at', 'updated_at']);
        $copy->name = 'Cópia de '.$template->name;
        $copy->created_by = auth()->id();
        $copy->save();

        $this->redirectRoute('superadmin.marketing.templates.edit', $copy, navigate: true);
    }

    /** Adiciona os modelos prontos que ainda não estão na lista (comparando pelo nome). */
    public function installDefaults(DefaultEmailTemplates $defaults): void
    {
        $created = $defaults->install(auth()->id());

        $this->notice = match ($created) {
            0 => 'Os modelos padrão já estão na lista.',
            1 => '1 modelo padrão adicionado.',
            default => "{$created} modelos padrão adicionados.",
        };
        $this->resetPage();
    }

    public function confirmDelete(int $templateId): void
    {
        $this->deletingId = $templateId;
        $this->confirmingDelete = true;
    }

    /** Campanhas já criadas guardam o próprio conteúdo: apagar o template não as afeta. */
    public function delete(): void
    {
        $this->confirmingDelete = false;

        if ($this->deletingId) {
            EmailTemplate::whereKey($this->deletingId)->delete();
            $this->notice = 'Template excluído.';
        }

        $this->deletingId = null;
    }

    public function render()
    {
        return view('livewire.super-admin.marketing.templates.index', [
            'templates' => EmailTemplate::withCount('campaigns')
                ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
                ->latest('updated_at')
                ->paginate(20),
        ])->layout('layouts.app', ['title' => 'Super Admin — Templates de e-mail']);
    }
}
