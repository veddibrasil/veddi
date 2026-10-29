<?php

namespace App\DTOs;

use App\Models\Branch;
use App\Models\ProductCategory;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Filtros validados do relatório de itens vendidos. Filial e categoria de outra empresa viram
 * null (o valor chega da URL/cliente), e o período é limitado a sales_insights.max_range_days.
 */
readonly class SoldItemsFilters
{
    public const CHANNELS = ['chat', 'pdv', 'ifood', '99food'];

    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?int $branchId = null,
        public ?string $channel = null,
        public ?int $categoryId = null,
    ) {}

    public static function fromInput(
        int $companyId,
        ?string $dateStart,
        ?string $dateEnd,
        mixed $branchId = null,
        ?string $channel = null,
        mixed $categoryId = null,
    ): self {
        $end = self::parseDate($dateEnd) ?? CarbonImmutable::today();
        $start = self::parseDate($dateStart) ?? $end->subDays(29);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $maxDays = (int) config('sales_insights.max_range_days', 92);
        if ((int) $start->diffInDays($end) + 1 > $maxDays) {
            $start = $end->subDays($maxDays - 1);
        }

        $branchId = (int) $branchId ?: null;
        if ($branchId && ! Branch::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($branchId)->exists()) {
            $branchId = null;
        }

        $categoryId = (int) $categoryId ?: null;
        if ($categoryId && ! ProductCategory::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($categoryId)->exists()) {
            $categoryId = null;
        }

        return new self(
            start: $start->startOfDay(),
            end: $end->endOfDay(),
            branchId: $branchId,
            channel: in_array($channel, self::CHANNELS, true) ? $channel : null,
            categoryId: $categoryId,
        );
    }

    private static function parseDate(?string $value): ?CarbonImmutable
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        // 2026-02-30 não vira 02/03 em silêncio.
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end->startOfDay()) + 1;
    }

    /**
     * Período de mesmo tamanho imediatamente anterior, para as variações. Se o período inclui
     * hoje (ainda em curso), o anterior para no mesmo horário: "hoje até 10h" vs "ontem até 10h".
     */
    public function previous(): self
    {
        $days = $this->days();

        return new self(
            start: $this->start->subDays($days),
            end: $this->includesToday() ? CarbonImmutable::now()->subDays($days) : $this->start->subSecond(),
            branchId: $this->branchId,
            channel: $this->channel,
            categoryId: $this->categoryId,
        );
    }

    /** Estende o início para que a janela tenha pelo menos $days dias (mesma data final). */
    public function withMinimumWindow(int $days): self
    {
        if ($this->days() >= $days) {
            return $this;
        }

        return new self(
            start: $this->end->startOfDay()->subDays($days - 1),
            end: $this->end,
            branchId: $this->branchId,
            channel: $this->channel,
            categoryId: $this->categoryId,
        );
    }

    public function withChannel(?string $channel): self
    {
        return new self($this->start, $this->end, $this->branchId, $channel, $this->categoryId);
    }

    public function includesToday(): bool
    {
        return CarbonImmutable::now()->between($this->start, $this->end);
    }

    /** Quantas vezes cada dia da semana (ISO 1=segunda … 7=domingo) aparece no período. */
    public function weekdayOccurrences(): array
    {
        $occurrences = array_fill(1, 7, 0);

        for ($day = $this->start; $day->lessThanOrEqualTo($this->end); $day = $day->addDay()) {
            $occurrences[$day->dayOfWeekIso]++;
        }

        return $occurrences;
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start->format('Y-m-d H:i:s'),
            'end' => $this->end->format('Y-m-d H:i:s'),
            'branch_id' => $this->branchId,
            'channel' => $this->channel,
            'category_id' => $this->categoryId,
        ];
    }

    public function cacheKey(int $companyId): string
    {
        return "sold-items:v1:{$companyId}:".md5(json_encode($this->toArray()));
    }
}
