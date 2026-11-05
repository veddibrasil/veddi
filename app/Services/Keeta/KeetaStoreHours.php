<?php

namespace App\Services\Keeta;

use App\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * Horários da filial no formato da Keeta (serviceHours.weekHours do Open Delivery): sempre em
 * UTC, endTime > startTime, turno que vira o dia em UTC é quebrado em dois e 24:00 vai como
 * 23:59:00.000Z. Turnos com o mesmo horário são agrupados em dayOfWeek.
 */
class KeetaStoreHours
{
    public const DAYS = [1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 7 => 'SUNDAY'];

    private const LAST_MINUTE = '23:59';

    /**
     * Turnos locais da filial: ['day' => 1..7 (segunda a domingo), 'start' => 'HH:MM', 'end' => 'HH:MM'].
     * Turno que passa da meia-noite local vira dois.
     *
     * @return array<int, array{day: int, start: string, end: string}>
     */
    public function localShifts(Branch $branch): array
    {
        $shifts = [];

        // Branch usa o dia da semana do PHP (0 = domingo); aqui 7 = domingo.
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

        return $shifts;
    }

    /**
     * Turnos locais → weekHours em UTC.
     *
     * @param  array<int, array{day: int, start: string, end: string}>  $shifts
     * @return array<int, array{dayOfWeek: array<int, string>, timePeriods: array{startTime: string, endTime: string}}>
     */
    public function toUtcWeekHours(array $shifts, ?string $timezone = null): array
    {
        $timezone ??= (string) config('keeta.timezone', config('app.timezone'));
        // Semana de referência (segunda 2024-01-01) no fuso da loja; o Brasil não tem horário de verão.
        $monday = CarbonImmutable::parse('2024-01-01 00:00', $timezone);
        $periods = [];

        foreach ($shifts as $shift) {
            $dayStart = $monday->addDays($shift['day'] - 1);
            $start = $dayStart->setTimeFromTimeString($shift['start'])->utc();
            $end = $shift['end'] === self::LAST_MINUTE || $shift['end'] === '24:00'
                ? $dayStart->addDay()->utc()
                : $dayStart->setTimeFromTimeString($shift['end'])->utc();

            if ($end->lte($start)) {
                continue;
            }

            if ($start->isSameDay($end) || $end->format('H:i') === '00:00') {
                $periods[] = [$this->utcDay($start), $start->format('H:i'), $start->isSameDay($end) ? $end->format('H:i') : '24:00'];

                continue;
            }

            $periods[] = [$this->utcDay($start), $start->format('H:i'), '24:00'];
            $periods[] = [$this->utcDay($end), '00:00', $end->format('H:i')];
        }

        $grouped = [];
        foreach ($periods as [$day, $startTime, $endTime]) {
            $key = $startTime.'-'.$endTime;
            $grouped[$key]['days'][$day] = true;
            $grouped[$key]['start'] = $startTime;
            $grouped[$key]['end'] = $endTime;
        }

        ksort($grouped);

        return array_values(array_map(function (array $group) {
            $days = array_keys($group['days']);
            sort($days);

            return [
                'dayOfWeek' => array_map(fn (int $day) => self::DAYS[$day], $days),
                'timePeriods' => [
                    'startTime' => $group['start'].':00.000Z',
                    'endTime' => ($group['end'] === '24:00' ? self::LAST_MINUTE : $group['end']).':00.000Z',
                ],
            ];
        }, $grouped));
    }

    /** @return array<int, array{dayOfWeek: array<int, string>, timePeriods: array{startTime: string, endTime: string}}> */
    public function forBranch(Branch $branch): array
    {
        return $this->toUtcWeekHours($this->localShifts($branch));
    }

    private function utcDay(CarbonImmutable $moment): int
    {
        return (int) $moment->format('N');
    }
}
