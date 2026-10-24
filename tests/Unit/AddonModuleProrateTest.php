<?php

use App\Services\Company\AddonModulePricing;
use Carbon\CarbonImmutable;

test('proporcional do módulo pelos dias que faltam até o vencimento', function (string $today, string $dueDate, float $expected) {
    expect(AddonModulePricing::prorate(99.0, CarbonImmutable::parse($today), CarbonImmutable::parse($dueDate)))->toBe($expected);
})->with([
    'ciclo inteiro (dia seguinte ao vencimento anterior)' => ['2026-10-24 12:25', '2026-11-24', 99.0],
    'metade do ciclo de 31 dias' => ['2026-11-09 18:00', '2026-11-24', 47.90],
    'véspera do vencimento' => ['2026-11-23 08:00', '2026-11-24', 3.19],
    'dia do vencimento' => ['2026-11-24 08:00', '2026-11-24', 0.0],
    'vencimento já passou' => ['2026-11-30', '2026-11-24', 0.0],
    'ciclo 28/01 → 28/02 (31 dias)' => ['2027-02-14', '2027-02-28', 44.71],
    'ciclo 01/02 → 01/03 (28 dias)' => ['2027-02-15', '2027-03-01', 49.5],
    'vencimento distante não passa do mês cheio' => ['2026-10-24', '2026-12-24', 99.0],
]);
