<?php

namespace App\Livewire\SuperAdmin\Marketing\Concerns;

use App\DTOs\AudienceFilterData;
use App\Services\Marketing\AudienceResolver;

/** Formulário de filtros de público (segmentos e campanhas). */
trait EditsAudienceFilters
{
    public array $filters = [];

    protected function resetFilters(?AudienceFilterData $data = null): void
    {
        $this->filters = ($data ?? new AudienceFilterData)->toForm();
    }

    protected function filterData(): AudienceFilterData
    {
        return AudienceFilterData::fromArray($this->filters);
    }

    protected function countFilters(): int
    {
        return app(AudienceResolver::class)->count($this->filterData());
    }
}
