<?php

use App\Events\FiscalNoteAuthorized;
use App\Models\Company;
use App\Models\FiscalNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function fiscalNoteObserverTestCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Observer',
        'slug' => 'observer-'.uniqid(),
        'order_prefix' => 'OBS',
        'active' => true,
    ]);
}

test('transição para authorized dispara FiscalNoteAuthorized', function () {
    Event::fake([FiscalNoteAuthorized::class]);

    $company = fiscalNoteObserverTestCompany();

    $note = FiscalNote::create([
        'company_id' => $company->id,
        'status' => 'pending',
    ]);

    $note->update(['status' => 'authorized']);

    Event::assertDispatched(FiscalNoteAuthorized::class, fn ($event) => $event->note->is($note));
});

test('transição para rejected não dispara FiscalNoteAuthorized', function () {
    Event::fake([FiscalNoteAuthorized::class]);

    $company = fiscalNoteObserverTestCompany();

    $note = FiscalNote::create([
        'company_id' => $company->id,
        'status' => 'pending',
    ]);

    $note->update(['status' => 'rejected']);

    Event::assertNotDispatched(FiscalNoteAuthorized::class);
});

test('atualizar outro campo sem mudar status não dispara o evento', function () {
    Event::fake([FiscalNoteAuthorized::class]);

    $company = fiscalNoteObserverTestCompany();

    $note = FiscalNote::create([
        'company_id' => $company->id,
        'status' => 'authorized',
    ]);

    Event::fake([FiscalNoteAuthorized::class]);
    $note->update(['provider_reference' => 'ref-123']);

    Event::assertNotDispatched(FiscalNoteAuthorized::class);
});
