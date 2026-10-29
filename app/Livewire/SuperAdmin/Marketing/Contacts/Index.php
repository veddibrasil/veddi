<?php

namespace App\Livewire\SuperAdmin\Marketing\Contacts;

use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\EmailContactList;
use App\Services\Marketing\ContactListService;
use Livewire\Component;
use Livewire\WithPagination;

/** Listas de contatos externos (possíveis clientes): criação com origem e aceite declarados. */
class Index extends Component
{
    use RequiresSuperAdmin;
    use WithPagination;

    public bool $showForm = false;

    public string $name = '';

    public string $source = '';

    public bool $consent = false;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->notice = session('marketing_notice');
    }

    public function create(): void
    {
        $this->reset('name', 'source', 'consent');
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(ContactListService $lists): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'source' => ['required', 'string', 'max:300'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Só dá para enviar para quem pediu para receber e-mails da Veddi.',
        ], [
            'name' => 'nome', 'source' => 'origem',
        ]);

        $list = $lists->create($this->name, $this->source, auth()->user());

        session()->flash('marketing_notice', 'Lista criada. Importe os contatos.');
        $this->redirectRoute('superadmin.marketing.contacts.show', $list, navigate: true);
    }

    public function render()
    {
        return view('livewire.super-admin.marketing.contacts.index', [
            'lists' => EmailContactList::with('creator:id,name')->withCount('contacts')->latest()->paginate(25),
        ])->layout('layouts.app', ['title' => 'Super Admin — Contatos externos']);
    }
}
