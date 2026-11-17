<?php

use App\Support\Fiscal\ProductFiscalProfile;

const PFP_GENERAL = ['ncm' => '19059090', 'cfop' => '5102', 'icms_situation' => '400', 'origin' => '0', 'unit' => 'UN', 'pis_situation' => '07', 'cofins_situation' => '07', 'taxes' => []];

test('sem exceção a filial usa o cadastro geral', function () {
    $resolved = ProductFiscalProfile::resolve(PFP_GENERAL, null);

    expect($resolved['data'])->toBe(PFP_GENERAL)
        ->and($resolved['has_branch'])->toBeFalse()
        ->and($resolved['sources']['pis_situation'])->toBe('general');
});

test('exceção troca só o que tem e herda o resto do cadastro geral', function () {
    $resolved = ProductFiscalProfile::resolve(PFP_GENERAL, ['icms_situation' => '500', 'cfop' => '5405', 'pis_situation' => '']);

    expect($resolved['standalone'])->toBeFalse()
        ->and($resolved['data'])->toMatchArray(['icms_situation' => '500', 'cfop' => '5405', 'ncm' => '19059090', 'pis_situation' => '07', 'cofins_situation' => '07'])
        ->and($resolved['sources'])->toMatchArray(['icms_situation' => 'branch', 'cfop' => 'branch', 'ncm' => 'general', 'pis_situation' => 'general']);
});

test('exceção de outro regime vale sozinha e não herda PIS, COFINS nem alíquotas', function () {
    $general = [...PFP_GENERAL, 'taxes' => ['icms_aliquota' => 4]];
    $resolved = ProductFiscalProfile::resolve($general, ['icms_situation' => '00', 'cfop' => '5102']);

    expect($resolved['standalone'])->toBeTrue()
        ->and($resolved['data'])->toBe(['icms_situation' => '00', 'cfop' => '5102'])
        ->and(ProductFiscalProfile::isStandalone($general, ['cfop' => '5405']))->toBeFalse();
});

test('alíquota do cadastro geral acompanha o CST: só é herdada se a filial não troca o CST', function () {
    $general = [...PFP_GENERAL, 'icms_situation' => '900', 'taxes' => ['icms_aliquota' => 18, 'pis_aliquota_porcentual' => 0.65, 'ipi_codigo_enquadramento' => '999']];

    $sameCst = ProductFiscalProfile::resolve($general, ['cfop' => '5405']);
    $otherCst = ProductFiscalProfile::resolve($general, ['icms_situation' => '500', 'taxes' => ['icms_base_calculo_retido_st' => 1]]);

    expect($sameCst['data']['taxes'])->toBe($general['taxes'])
        ->and($otherCst['data']['taxes'])->toBe(['pis_aliquota_porcentual' => 0.65, 'ipi_codigo_enquadramento' => '999', 'icms_base_calculo_retido_st' => 1]);
});

test('compactar tira da exceção o que é igual ao cadastro geral', function () {
    $legacyCopy = ['ncm' => '19059090', 'cfop' => '5102', 'unit' => 'un', 'origin' => '0', 'icms_situation' => '500', 'interstate_cfop' => '6404', 'taxes' => []];

    expect(ProductFiscalProfile::compact(PFP_GENERAL, $legacyCopy))->toBe(['interstate_cfop' => '6404', 'icms_situation' => '500', 'taxes' => []])
        ->and(ProductFiscalProfile::compact(PFP_GENERAL, ['ncm' => '19059090', 'taxes' => []]))->toBeNull();
});

test('compactar mantém CST igual quando as alíquotas daquele imposto são diferentes', function () {
    $general = [...PFP_GENERAL, 'icms_situation' => '900', 'taxes' => ['icms_aliquota' => 18]];
    $branch = ['icms_situation' => '900', 'taxes' => ['icms_percentual_reducao' => 10]];

    $compact = ProductFiscalProfile::compact($general, $branch);

    expect($compact)->toBe(['icms_situation' => '900', 'taxes' => ['icms_percentual_reducao' => 10]])
        ->and(ProductFiscalProfile::resolve($general, $compact)['data']['taxes'])->toBe(ProductFiscalProfile::resolve($general, $branch)['data']['taxes']);
});

test('compactar não mexe em exceção de outro regime', function () {
    $branch = ['icms_situation' => '00', 'ncm' => '19059090', 'pis_situation' => '07', 'taxes' => ['icms_aliquota' => 18]];

    expect(ProductFiscalProfile::compact(PFP_GENERAL, $branch))->toBe(['ncm' => '19059090', 'icms_situation' => '00', 'pis_situation' => '07', 'taxes' => ['icms_aliquota' => 18]]);
});
