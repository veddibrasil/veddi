<?php

namespace App\Livewire\SuperAdmin\Marketing\Segments;

use App\DTOs\AudienceFilterData;
use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Livewire\SuperAdmin\Marketing\Concerns\EditsAudienceFilters;
use App\Models\EmailSegment;
use App\Services\Marketing\AudienceResolver;
use App\Services\Marketing\DefaultEmailSegments;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Segmentos salvos: filtros de público reutilizáveis, com contagem já sem supressões. */
class Index extends Component
{
    use EditsAudienceFilters;
    use RequiresSuperAdmin;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public ?int $deletingId = null;

    public bool $confirmingDelete = false;

    public ?string $notice = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->name = '';
        $this->resetFilters();
        $this->showForm = true;
    }

    public function edit(int $segmentId): void
    {
        $segment = EmailSegment::findOrFail($segmentId);

        $this->resetErrorBag();
        $this->editingId = $segment->id;
        $this->name = $segment->name;
        $this->resetFilters($segment->filterData());
        $this->showForm = true;
    }

    public function save(AudienceResolver $audience): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            ...AudienceFilterData::rules('filters'),
        ], attributes: ['name' => 'nome']);

        $filters = $this->filterData();
        $segment = $this->editingId ? EmailSegment::findOrFail($this->editingId) : new EmailSegment(['created_by' => auth()->id()]);

        $segment->fill([
            'name' => $this->name,
            'filters' => $filters->toArray(),
            'last_count' => $audience->count($filters),
            'last_counted_at' => now(),
        ])->save();

        $this->showForm = false;
        $this->notice = 'Segmento salvo.';
    }

    /** Adiciona os segmentos prontos que ainda não estão na lista (comparando pelo nome). */
    public function installDefaults(DefaultEmailSegments $defaults): void
    {
        $created = $defaults->install(auth()->id());

        $this->notice = match ($created) {
            0 => 'Os segmentos padrão já estão na lista.',
            1 => '1 segmento padrão adicionado.',
            default => "{$created} segmentos padrão adicionados.",
        };
    }

    public function recount(int $segmentId, AudienceResolver $audience): void
    {
        $segment = EmailSegment::findOrFail($segmentId);
        $segment->update(['last_count' => $audience->count($segment->filterData()), 'last_counted_at' => now()]);
    }

    public function confirmDelete(int $segmentId): void
    {
        $this->deletingId = $segmentId;
        $this->confirmingDelete = true;
    }

    public function delete(): void
    {
        $this->confirmingDelete = false;

        if ($this->deletingId) {
            $segment = EmailSegment::withCount('campaigns')->findOrFail($this->deletingId);

            if ($segment->campaigns_count > 0) {
                $this->notice = 'Segmento usado por campanhas: as campanhas passam a ficar sem segmento. Excluído.';
            } else {
                $this->notice = 'Segmento excluído.';
            }

            $segment->delete();
        }

        $this->deletingId = null;
    }

    #[Computed]
    public function liveCount(): int
    {
        return $this->showForm ? $this->countFilters() : 0;
    }

    public function render()
    {
        return view('livewire.super-admin.marketing.segments.index', [
            'segments' => EmailSegment::withCount('campaigns')->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Super Admin — Segmentos de e-mail']);
    }
}
