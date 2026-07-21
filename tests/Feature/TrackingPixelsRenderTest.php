<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function trackingPixelsRenderCompany(array $attributes = []): Company
{
    return Company::create(array_merge([
        'name' => 'Empresa Render Pixels',
        'slug' => 'empresa-render-pixels-'.uniqid(),
        'order_prefix' => 'RPX',
        'active' => true,
        'status' => 'ACTIVE',
    ], $attributes))->fresh();
}

test('chat público injeta pixels quando a empresa configurou', function () {
    $company = trackingPixelsRenderCompany([
        'facebook_pixel_id' => '123456789012345',
        'google_analytics_id' => 'G-ABCD12WXYZ',
        'google_ads_id' => 'AW-123456789',
    ]);

    $response = $this->get('/'.$company->slug);

    $response->assertOk();
    $response->assertSee('connect.facebook.net', false);
    $response->assertSee("fbq('init', '123456789012345')", false);
    $response->assertSee('www.googletagmanager.com/gtag/js?id=G-ABCD12WXYZ', false);
    $response->assertSee("gtag('config', 'G-ABCD12WXYZ')", false);
    $response->assertSee("gtag('config', 'AW-123456789')", false);
});

test('chat público não injeta nada quando a empresa não configurou pixels', function () {
    $company = trackingPixelsRenderCompany();

    $response = $this->get('/'.$company->slug);

    $response->assertOk();
    $response->assertDontSee('connect.facebook.net', false);
    $response->assertDontSee('googletagmanager.com', false);
});

test('valor malicioso gravado fora do formulário não é refletido no HTML do chat', function () {
    $company = trackingPixelsRenderCompany();
    // Simula um valor que driblou a validação do Livewire (ex: escrito direto via tinker/seed).
    $company->forceFill(['facebook_pixel_id' => "1'); alert(document.cookie); //"])->save();

    $response = $this->get('/'.$company->slug);

    $response->assertOk();
    $response->assertDontSee('alert(document.cookie)', false);
});
