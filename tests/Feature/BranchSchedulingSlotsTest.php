<?php

use App\Models\Branch;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSchedulingBranch(array $overrides = []): Branch
{
    $company = Company::create([
        'name' => 'Empresa Agendamento',
        'slug' => 'empresa-agendamento-'.uniqid(),
        'order_prefix' => 'TST',
        'active' => true,
    ]);

    return Branch::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'name' => 'Filial Teste',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'opens_at' => '08:00',
        'closes_at' => '20:00',
        'available_days' => [0, 1, 2, 3, 4, 5, 6],
    ], $overrides));
}

it('falls back to the general opening hours when no scheduling slots are configured', function () {
    $branch = makeSchedulingBranch();

    expect($branch->schedulingSlotsForDay(1))->toBe([
        ['opens_at' => '08:00', 'closes_at' => '20:00'],
    ]);
});

it('returns the configured scheduling slots for the day', function () {
    $branch = makeSchedulingBranch([
        'scheduling_slots' => [
            1 => [
                ['opens_at' => '09:30', 'closes_at' => '11:30'],
                ['opens_at' => '13:30', 'closes_at' => '15:30'],
            ],
        ],
    ]);

    expect($branch->schedulingSlotsForDay(1))->toBe([
        ['opens_at' => '09:30', 'closes_at' => '11:30'],
        ['opens_at' => '13:30', 'closes_at' => '15:30'],
    ]);
});

it('accepts a time inside any configured slot', function () {
    $branch = makeSchedulingBranch([
        'scheduling_slots' => [
            1 => [
                ['opens_at' => '09:30', 'closes_at' => '11:30'],
                ['opens_at' => '13:30', 'closes_at' => '15:30'],
            ],
        ],
    ]);

    expect($branch->isWithinSchedulingSlot(1, '10:00'))->toBeTrue();
    expect($branch->isWithinSchedulingSlot(1, '14:00'))->toBeTrue();
});

it('rejects a time in the gap between configured slots even though it is inside the general opening hours', function () {
    $branch = makeSchedulingBranch([
        'scheduling_slots' => [
            1 => [
                ['opens_at' => '09:30', 'closes_at' => '11:30'],
                ['opens_at' => '13:30', 'closes_at' => '15:30'],
            ],
        ],
    ]);

    expect($branch->isWithinSchedulingSlot(1, '12:00'))->toBeFalse();
});

it('generates deduplicated and sorted time slots across overlapping windows', function () {
    $branch = makeSchedulingBranch([
        'scheduling_slots' => [
            1 => [
                ['opens_at' => '09:30', 'closes_at' => '11:30'],
                ['opens_at' => '11:00', 'closes_at' => '13:30'],
            ],
        ],
    ]);

    $monday = Carbon::parse('2026-08-10'); // segunda-feira
    $now = Carbon::parse('2026-08-01 00:00');

    $slots = $branch->scheduleTimeSlotsForDate($monday, 60, $now);

    expect($slots)->toBe(['09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30']);
});

it('excludes time slots before the minimum advance window', function () {
    $branch = makeSchedulingBranch([
        'scheduling_slots' => [
            1 => [['opens_at' => '09:00', 'closes_at' => '11:00']],
        ],
    ]);

    $monday = Carbon::parse('2026-08-10');
    $now = Carbon::parse('2026-08-10 09:30');

    $slots = $branch->scheduleTimeSlotsForDate($monday, 60, $now);

    expect($slots)->toBe(['11:00']);
});

it('returns no slots when the branch does not operate on that day of week', function () {
    $branch = makeSchedulingBranch([
        'available_days' => [1, 2, 3, 4, 5],
        'scheduling_slots' => [
            0 => [['opens_at' => '09:00', 'closes_at' => '11:00']],
        ],
    ]);

    $sunday = Carbon::parse('2026-08-09');
    $now = Carbon::parse('2026-08-01 00:00');

    expect($branch->scheduleTimeSlotsForDate($sunday, 60, $now))->toBe([]);
});
