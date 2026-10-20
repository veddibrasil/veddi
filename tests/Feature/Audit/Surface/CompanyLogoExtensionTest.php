<?php

use App\Livewire\Admin\Settings\CompanySettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Auditoria 15/10 (superfície/uploads) — CompanySettings grava o logo com a extensão que o
 * navegador informou (getClientOriginalExtension). A regra `image` só olha o conteúdo, então um
 * GIF poliglota chamado logo.html passa e vira logos/company_{id}.html no bucket; o Flysystem S3
 * define o Content-Type pela extensão (text/html) quando o corpo é stream.
 */
test('logo é gravado com extensão derivada do conteúdo, não do nome enviado pelo navegador', function () {
    Storage::fake('s3');

    $company = Company::create([
        'name' => 'Loja Logo',
        'slug' => 'loja-logo-'.uniqid(),
        'order_prefix' => 'LGO',
        'active' => true,
        'status' => 'ACTIVE',
        'plan' => 'pro',
        'email' => 'contato@lojalogo.com',
        'primary_color' => '#5c347f',
        'primary_color_dark' => '#19273c',
        'primary_color_light' => '#5c347f',
        'secondary_color' => '#e36831',
        'secondary_color_light' => '#D97706',
        'accent_color' => '#cad1d8',
    ]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    $this->actingAs($admin);

    // GIF válido (passa em `image`) com HTML embutido, enviado como "logo.html".
    $polyglot = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"
        .'<script>alert(document.domain)</script>';
    $upload = UploadedFile::fake()->createWithContent('logo.html', $polyglot)->mimeType('image/gif'); // em produção o finfo do conteúdo devolve image/gif

    Livewire::test(CompanySettings::class)
        ->set('logo', $upload)
        ->call('save')
        ->assertHasNoErrors();

    $path = (string) $company->fresh()->logo_path;

    expect($path)->not->toBe('')
        ->and(strtolower(pathinfo($path, PATHINFO_EXTENSION)))->toBeIn(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
});
