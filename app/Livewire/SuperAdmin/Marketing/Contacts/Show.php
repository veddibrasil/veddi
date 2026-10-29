<?php

namespace App\Livewire\SuperAdmin\Marketing\Contacts;

use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\EmailContact;
use App\Models\EmailContactList;
use App\Models\EmailSuppression;
use App\Services\Marketing\AudienceResolver;
use App\Services\Marketing\ContactListService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Uma lista de contatos externos: importação (CSV ou colar), busca, remoção e números de quem recebe. */
class Show extends Component
{
    use RequiresSuperAdmin;
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public int $listId;

    #[Url(as: 'busca')]
    public string $search = '';

    public $csv = null;

    public string $pasted = '';

    public string $pasteFormat = 'emails';

    #[Locked]
    public ?string $pasteNotice = null;

    public bool $confirmingDelete = false;

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(EmailContactList $list): void
    {
        $this->listId = $list->id;
        $this->notice = session('marketing_notice');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function import(ContactListService $lists): void
    {
        $this->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:5120']], attributes: ['csv' => 'arquivo']);

        $this->notice = $this->summary($lists->importFile($this->list(), $this->csv->getRealPath()));
        $this->reset('csv');
        $this->refreshNumbers();
    }

    public function importPasted(ContactListService $lists): void
    {
        $this->validate([
            'pasted' => ['required', 'string', 'max:200000'],
            'pasteFormat' => ['required', 'in:emails,table'],
        ], attributes: ['pasted' => 'contatos', 'pasteFormat' => 'formato']);

        $preview = $this->pastePreview;
        if ($preview['new'] === 0) {
            $this->addError('pasted', $preview['existing'] > 0 ? 'Os contatos válidos já estão nesta lista. Cole novos e-mails para adicionar.' : 'Nenhum contato válido para adicionar. Confira o formato e os itens em revisão.');

            return;
        }

        $this->pasteNotice = $this->summary($lists->importText($this->list(), $this->pasted, $this->pasteFormat));
        if ($preview['invalid'] + $preview['skipped'] === 0) {
            $this->reset('pasted');
        } else {
            $this->pasteNotice .= ' O texto foi mantido para revisar os itens ignorados.';
        }
        unset($this->pastePreview);
        $this->refreshNumbers();
    }

    public function clearPasted(): void
    {
        $this->reset('pasted', 'pasteNotice');
        $this->resetValidation('pasted');
        unset($this->pastePreview);
    }

    public function updatedPasted(): void
    {
        $this->pasteNotice = null;
        $this->resetValidation('pasted');
        unset($this->pastePreview);
    }

    public function updatedPasteFormat(): void
    {
        $this->updatedPasted();
    }

    #[Computed]
    public function pastePreview(): array
    {
        // O limite também vale antes da validação do submit, durante a digitação.
        if (mb_strlen($this->pasted) > 200000) {
            return ['too_long' => true, 'total' => 0, 'new' => 0, 'existing' => 0, 'duplicates' => 0, 'invalid' => 0, 'skipped' => 0, 'sample' => [], 'issues' => []];
        }

        return ['too_long' => false] + app(ContactListService::class)->previewText($this->list(), $this->pasted, $this->pasteFormat);
    }

    public function removeContact(int $contactId): void
    {
        // Apaga também o destinatário de campanha desse contato (FK em cascata).
        if (EmailContact::where('list_id', $this->listId)->whereKey($contactId)->delete()) {
            $this->notice = 'Contato removido.';
            $this->refreshNumbers();
        }
    }

    public function deleteList(): void
    {
        $list = $this->list();
        $list->delete();

        Log::channel('marketing')->info('Marketing: lista de contatos excluída', ['list_id' => $list->id, 'actor_id' => auth()->id()]);

        session()->flash('marketing_notice', 'Lista excluída.');
        $this->redirectRoute('superadmin.marketing.contacts.index', navigate: true);
    }

    /** @return array{total: int, eligible: int, suppressed: int, users: int} */
    #[Computed]
    public function numbers(): array
    {
        $contacts = DB::table('email_contacts')->where('list_id', $this->listId);

        return [
            'total' => (clone $contacts)->count(),
            'eligible' => app(AudienceResolver::class)->count($this->list()->filterData()),
            'suppressed' => (clone $contacts)->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('email_suppressions')->whereColumn('email_suppressions.email', 'email_contacts.email'))->count(),
            'users' => (clone $contacts)->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('users')->whereRaw('LOWER(TRIM(users.email)) = email_contacts.email'))->count(),
        ];
    }

    public function render()
    {
        $contacts = EmailContact::where('list_id', $this->listId)
            ->when($this->search !== '', function ($query) {
                $term = '%'.mb_strtolower(trim($this->search)).'%';

                $query->where(fn ($inner) => $inner->where('email', 'like', $term)->orWhere('name', 'like', $term)->orWhere('company_name', 'like', $term));
            })
            ->orderBy('email')
            ->paginate(50);

        $emails = $contacts->pluck('email')->all();

        return view('livewire.super-admin.marketing.contacts.show', [
            'list' => $this->list(),
            'contacts' => $contacts,
            'suppressed' => array_flip(EmailSuppression::whereIn('email', $emails)->pluck('email')->all()),
            'registered' => array_flip(DB::table('users')->whereIn(DB::raw('LOWER(TRIM(email))'), $emails)->pluck(DB::raw('LOWER(TRIM(email))'))->all()),
        ])->layout('layouts.app', ['title' => 'Super Admin — Contatos externos']);
    }

    private function list(): EmailContactList
    {
        return EmailContactList::with('creator:id,name')->findOrFail($this->listId);
    }

    private function refreshNumbers(): void
    {
        unset($this->numbers);
        $this->resetPage();
    }

    /** @param  array{added: int, existing: int, invalid: int, skipped: int}  $result */
    private function summary(array $result): string
    {
        $parts = ["{$result['added']} novo(s)", "{$result['existing']} já na lista"];

        if ($result['invalid']) {
            $parts[] = "{$result['invalid']} inválido(s)";
        }
        if ($result['skipped']) {
            $parts[] = "{$result['skipped']} ignorado(s) (teste, temporário, domínio errado ou automático)";
        }
        if ($result['duplicates'] ?? 0) {
            $parts[] = "{$result['duplicates']} repetido(s) no texto ou arquivo";
        }

        return 'Importação concluída: '.implode(', ', $parts).'.';
    }
}
