<?php

namespace App\Livewire\SuperAdmin\WhatsApp;

use App\Jobs\ProvisionWhatsAppTemplates;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Connections extends Component
{
    use WithPagination;

    /** Status em que ainda há templates a criar/sincronizar (o job ignora os demais). */
    private const SYNCABLE = [
        WhatsAppConnection::STATUS_PROVISIONING,
        WhatsAppConnection::STATUS_TEMPLATES_PENDING,
        WhatsAppConnection::STATUS_ACTIVE,
    ];

    #[Url(as: 'busca')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    public ?string $notice = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Refaz o provisionamento dos templates da conexão (cria os que faltam e traz o status atual
     * da Meta). Vai para a fila: são várias chamadas à Graph API, lentas demais para a requisição.
     */
    public function syncTemplates(int $connectionId): void
    {
        $connection = WhatsAppConnection::withoutGlobalScopes()->findOrFail($connectionId);

        if (! in_array($connection->status, self::SYNCABLE, true) || blank($connection->access_token) || blank($connection->waba_id)) {
            $this->notice = 'Esta conexão não pode ser sincronizada agora (sem autorização ativa da Meta).';

            return;
        }

        ProvisionWhatsAppTemplates::dispatch($connection->id);

        $this->notice = 'Sincronização de templates enfileirada. O status é atualizado em instantes.';
    }

    public function render()
    {
        $connections = WhatsAppConnection::withoutGlobalScopes()
            ->with('company:id,name,slug')
            ->withCount([
                'templates as approved_templates_count' => fn ($query) => $query->where('status', WhatsAppTemplate::STATUS_APPROVED),
            ])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(function ($inner) use ($term) {
                    $inner->whereHas('company', fn ($company) => $company->where('name', 'like', $term))
                        ->orWhere('display_phone_number', 'like', $term)
                        ->orWhere('verified_name', 'like', $term);
                });
            })
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('livewire.super-admin.whatsapp.connections', [
            'connections' => $connections,
            'syncable' => self::SYNCABLE,
            'requiredTemplates' => count(config('whatsapp_templates.templates')),
        ])->layout('layouts.app', ['title' => 'Super Admin — WhatsApp']);
    }
}
