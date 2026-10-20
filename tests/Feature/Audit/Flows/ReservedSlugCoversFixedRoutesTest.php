<?php

use App\Http\Requests\RegisterCompanyRequest;
use App\Rules\ReservedSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — o chat público é `GET /{company}`. Toda rota GET de um segmento só
 * registrada antes dela "rouba" o endereço de uma empresa com o mesmo slug. ReservedSlug não
 * cobre `health`, `up`, `termos-de-uso` e `politica-de-privacidade`: o /cadastro aceita esses
 * slugs e o link do chat da empresa abre o health check / a página legal.
 */
test('todo slug que colide com rota GET fixa de um segmento é recusado no cadastro', function () {
    $fixed = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => $uri !== '/' && ! str_contains($uri, '/') && ! str_contains($uri, '{'))
        ->unique()
        ->values();

    expect($fixed)->not->toBeEmpty();

    $accepted = $fixed->filter(function (string $slug) {
        $rules = ['slug' => (new RegisterCompanyRequest)->rules()['slug']];

        return Validator::make(['slug' => $slug], $rules)->passes();
    })->values()->all();

    // Hoje: ['health', 'politica-de-privacidade', 'termos-de-uso', 'up'] passam na validação.
    expect($accepted)->toBe([]);
});

test('controle: slugs reservados conhecidos continuam recusados', function (string $slug) {
    expect(Validator::make(['slug' => $slug], ['slug' => [new ReservedSlug]])->fails())->toBeTrue();
})->with(['admin', 'cadastro', 'docs', 'settings']);
