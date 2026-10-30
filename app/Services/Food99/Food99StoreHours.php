<?php

namespace App\Services\Food99;

use App\Contracts\Food99GatewayContract;
use App\Models\Branch;
use App\Models\Food99Integration;
use InvalidArgumentException;
use RuntimeException;

/**
 * Horários de funcionamento da loja na 99Food. shop/detail devolve biz_day_time em snake_case
 * (biz_day, biz_time) e shop/update recebe em camelCase (bizDay, bizTime); a tela trabalha com
 * turnos soltos: ['day' => 1..7 (segunda a domingo), 'start' => 'HH:MM', 'end' => 'HH:MM'].
 *
 * A 99 aceita de 00:00 a 23:59 no mesmo dia: turno que passa da meia-noite vira dois.
 */
class Food99StoreHours
{
    public const WEEK_DAYS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    private const LAST_MINUTE = '23:59';

    public function __construct(private readonly Food99GatewayContract $gateway) {}

    /** @return array<int, array{day: int, start: string, end: string}> */
    public function shiftsFrom(?array $bizDayTime): array
    {
        $shifts = [];

        foreach ($bizDayTime ?? [] as $entry) {
            foreach ((array) ($entry['biz_day'] ?? $entry['bizDay'] ?? []) as $day) {
                foreach ((array) ($entry['biz_time'] ?? $entry['bizTime'] ?? []) as $time) {
                    $end = (string) ($time['end'] ?? '');
                    $shifts[] = ['day' => (int) $day, 'start' => (string) ($time['begin'] ?? ''), 'end' => $end === '24:00' ? self::LAST_MINUTE : $end];
                }
            }
        }

        return $this->sorted($shifts);
    }

    /**
     * Turnos a partir do cadastro da filial, pra 99 e painel abrirem e fecharem juntos.
     *
     * @return array<int, array{day: int, start: string, end: string}>
     */
    public function shiftsFromBranch(Branch $branch): array
    {
        $shifts = [];

        // Branch usa o dia da semana do PHP (0 = domingo); na 99, 7 = domingo.
        foreach (range(0, 6) as $phpDay) {
            if ($branch->available_days !== null && ! in_array($phpDay, array_map('intval', $branch->available_days), true)) {
                continue;
            }

            $hours = $branch->hoursForDay($phpDay);
            if (! $hours['opens_at'] || ! $hours['closes_at']) {
                continue;
            }

            $day = $phpDay === 0 ? 7 : $phpDay;
            $start = substr((string) $hours['opens_at'], 0, 5);
            $end = substr((string) $hours['closes_at'], 0, 5);

            if ($end > $start) {
                $shifts[] = ['day' => $day, 'start' => $start, 'end' => $end];

                continue;
            }

            $shifts[] = ['day' => $day, 'start' => $start, 'end' => self::LAST_MINUTE];
            if ($end > '00:00') {
                $shifts[] = ['day' => $day === 7 ? 1 : $day + 1, 'start' => '00:00', 'end' => $end];
            }
        }

        return $this->sorted($shifts);
    }

    /**
     * Substitui a semana da loja. [VALIDAR 99Food] dia sem turno fica fechado (a doc não diz se
     * biz_day_time parcial mantém os dias omitidos).
     *
     * @param  array<int, array{day: int|string, start: string, end: string}>  $shifts
     */
    public function save(Food99Integration $integration, array $shifts): void
    {
        $shifts = $this->validated($shifts);

        $result = $this->gateway->updateShop($integration, ['biz_day_time' => array_map(
            fn (array $entry) => [
                'bizDay' => $entry['days'],
                'bizTime' => array_map(fn (array $time) => ['begin' => $time['start'], 'end' => $time['end']], $entry['times']),
            ],
            $this->groupedByWeek($shifts),
        )]);

        if (($result['biz_day_time'] ?? null) === false) {
            throw new RuntimeException('A 99Food não aceitou estes horários. Confira os turnos e tente de novo.');
        }

        $integration->forceFill(['business_hours' => array_map(
            fn (array $entry) => [
                'biz_day' => $entry['days'],
                'biz_time' => array_map(fn (array $time) => ['begin' => $time['start'], 'end' => $time['end']], $entry['times']),
            ],
            $this->groupedByWeek($shifts),
        )])->save();
    }

    /** @return array<int, array{day: int, start: string, end: string}> */
    private function validated(array $shifts): array
    {
        if ($shifts === []) {
            throw new InvalidArgumentException('Informe ao menos um turno. Para fechar a loja, use a aba Loja.');
        }

        $shifts = $this->sorted(array_map(fn (array $shift) => [
            'day' => (int) $shift['day'],
            'start' => (string) $shift['start'],
            'end' => (string) $shift['end'],
        ], $shifts));

        $previous = null;
        foreach ($shifts as $shift) {
            if (! isset(self::WEEK_DAYS[$shift['day']])
                || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $shift['start'])
                || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $shift['end'])) {
                throw new InvalidArgumentException('Informe abertura e fechamento no formato HH:MM.');
            }
            if ($shift['end'] <= $shift['start']) {
                throw new InvalidArgumentException('A 99Food não aceita turno que passa da meia-noite: feche às 23:59 e abra outro às 00:00 no dia seguinte.');
            }
            if ($previous !== null && $previous['day'] === $shift['day'] && $shift['start'] < $previous['end']) {
                throw new InvalidArgumentException('Os turnos não podem ter horários sobrepostos.');
            }
            $previous = $shift;
        }

        return $shifts;
    }

    /**
     * Dias com os mesmos turnos vão juntos, como a 99 devolve.
     *
     * @return array<int, array{days: array<int, int>, times: array<int, array{start: string, end: string}>}>
     */
    private function groupedByWeek(array $shifts): array
    {
        $days = [];
        foreach ($shifts as $shift) {
            $days[$shift['day']][] = ['start' => $shift['start'], 'end' => $shift['end']];
        }

        $groups = [];
        foreach ($days as $day => $times) {
            $key = json_encode($times);
            $groups[$key]['days'][] = $day;
            $groups[$key]['times'] = $times;
        }

        return array_values($groups);
    }

    private function sorted(array $shifts): array
    {
        usort($shifts, fn (array $a, array $b) => [$a['day'], $a['start']] <=> [$b['day'], $b['start']]);

        return $shifts;
    }
}
