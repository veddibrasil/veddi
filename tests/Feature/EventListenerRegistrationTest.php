<?php

use App\Events\CompanyActivated;
use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Listeners\SendWhatsAppOrderNotification;
use App\Listeners\SendWhatsAppStatusNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/** Classes dos listeners registrados para o evento (descoberta automática grava "Classe@handle"). */
function registeredListenerClasses(string $event): Collection
{
    return collect(Event::getRawListeners()[$event] ?? [])
        ->map(fn ($listener) => is_string($listener) ? explode('@', $listener)[0] : $listener)
        ->filter(fn ($listener) => is_string($listener))
        ->values();
}

// Os listeners de app/Listeners são descobertos automaticamente. Registrar de novo com
// Event::listen faz cada um rodar duas vezes (e-mail, nota fiscal, WhatsApp, iFood, Vindi).
test('cada listener é registrado uma única vez por evento', function (string $event) {
    $listeners = registeredListenerClasses($event);

    expect($listeners)->not->toBeEmpty()
        ->and($listeners->duplicates()->all())->toBe([]);
})->with([
    CompanyActivated::class,
    NewOrderPlaced::class,
    OrderStatusUpdated::class,
]);

// Sem o listener o cliente simplesmente não recebe o aviso (nada falha); com ele duas vezes,
// a idempotência segura o envio mas a decisão roda em dobro.
test('os avisos de WhatsApp do pedido estão ligados exatamente uma vez', function (string $event, string $listener) {
    $occurrences = registeredListenerClasses($event)->filter(fn (string $class) => $class === $listener);

    expect($occurrences)->toHaveCount(1);
})->with([
    'novo pedido' => [NewOrderPlaced::class, SendWhatsAppOrderNotification::class],
    'mudança de status' => [OrderStatusUpdated::class, SendWhatsAppStatusNotification::class],
]);
