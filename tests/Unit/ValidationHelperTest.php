<?php

use App\Helpers\Validation;

// ─── CPF ─────────────────────────────────────────────────────────────────────

test('CPF válido é aceito', function () {
    expect(Validation::isValidCpf('529.982.247-25'))->toBeTrue();
});

test('CPF válido sem formatação é aceito', function () {
    expect(Validation::isValidCpf('52998224725'))->toBeTrue();
});

test('CPF com dígito verificador errado é rejeitado', function () {
    expect(Validation::isValidCpf('529.982.247-26'))->toBeFalse();
});

test('CPF com sequência repetida é rejeitado', function () {
    expect(Validation::isValidCpf('111.111.111-11'))->toBeFalse();
    expect(Validation::isValidCpf('00000000000'))->toBeFalse();
    expect(Validation::isValidCpf('99999999999'))->toBeFalse();
});

test('CPF com comprimento incorreto é rejeitado', function () {
    expect(Validation::isValidCpf('123.456.789'))->toBeFalse();  // curto
    expect(Validation::isValidCpf('1234567890123'))->toBeFalse(); // longo
});

test('CPF com letras é rejeitado', function () {
    expect(Validation::isValidCpf('abc.def.ghi-jk'))->toBeFalse();
});

// ─── CNPJ ────────────────────────────────────────────────────────────────────

test('CNPJ válido é aceito', function () {
    expect(Validation::isValidCnpj('11.222.333/0001-81'))->toBeTrue();
});

test('CNPJ válido sem formatação é aceito', function () {
    expect(Validation::isValidCnpj('11222333000181'))->toBeTrue();
});

test('CNPJ com dígito verificador errado é rejeitado', function () {
    expect(Validation::isValidCnpj('11.222.333/0001-82'))->toBeFalse();
});

test('CNPJ com sequência repetida é rejeitado', function () {
    expect(Validation::isValidCnpj('11.111.111/1111-11'))->toBeFalse();
    expect(Validation::isValidCnpj('00000000000000'))->toBeFalse();
});

test('CNPJ com comprimento incorreto é rejeitado', function () {
    expect(Validation::isValidCnpj('11.222.333/0001'))->toBeFalse();  // curto
    expect(Validation::isValidCnpj('11.222.333/0001-8100'))->toBeFalse(); // longo
});

// ─── Telefone ─────────────────────────────────────────────────────────────────

test('telefone celular válido é aceito', function () {
    expect(Validation::isValidPhone('(11) 99999-9999'))->toBeTrue();
    expect(Validation::isValidPhone('11999999999'))->toBeTrue();
    expect(Validation::isValidPhone('11 99999-9999'))->toBeTrue();
});

test('telefone fixo válido é aceito', function () {
    expect(Validation::isValidPhone('(11) 3333-4444'))->toBeTrue();
    expect(Validation::isValidPhone('1133334444'))->toBeTrue();
});

test('telefone com DDD inválido é rejeitado', function () {
    expect(Validation::isValidPhone('1 999999999'))->toBeFalse(); // DDD com 1 dígito
});

test('telefone vazio é rejeitado', function () {
    expect(Validation::isValidPhone(''))->toBeFalse();
});

// ─── CEP ─────────────────────────────────────────────────────────────────────

test('CEP válido com hífen é aceito', function () {
    expect(Validation::isValidCep('01310-100'))->toBeTrue();
});

test('CEP válido sem hífen é aceito', function () {
    expect(Validation::isValidCep('01310100'))->toBeTrue();
});

test('CEP com comprimento incorreto é rejeitado', function () {
    expect(Validation::isValidCep('0131010'))->toBeFalse();  // 7 dígitos
    expect(Validation::isValidCep('013101000'))->toBeFalse(); // 9 dígitos
});

test('CEP com letras é rejeitado', function () {
    expect(Validation::isValidCep('0131A-100'))->toBeFalse();
});

// ─── Slug ─────────────────────────────────────────────────────────────────────

test('slug válido é aceito', function () {
    expect(Validation::isValidSlug('minha-empresa'))->toBeTrue();
    expect(Validation::isValidSlug('empresa123'))->toBeTrue();
    expect(Validation::isValidSlug('a'))->toBeTrue();
});

test('slug com letras maiúsculas é rejeitado', function () {
    expect(Validation::isValidSlug('Minha-Empresa'))->toBeFalse();
});

test('slug com espaços é rejeitado', function () {
    expect(Validation::isValidSlug('minha empresa'))->toBeFalse();
});

test('slug com caracteres especiais é rejeitado', function () {
    expect(Validation::isValidSlug('minha_empresa'))->toBeFalse(); // underscore não permitido
    expect(Validation::isValidSlug('empresa.com'))->toBeFalse();
});

// ─── Normalização ─────────────────────────────────────────────────────────────

test('normalizeCpf remove pontuação', function () {
    expect(Validation::normalizeCpf('529.982.247-25'))->toBe('52998224725');
});

test('normalizeCnpj remove pontuação', function () {
    expect(Validation::normalizeCnpj('11.222.333/0001-81'))->toBe('11222333000181');
});

test('normalizePhone remove caracteres não numéricos', function () {
    expect(Validation::normalizePhone('(11) 99999-9999'))->toBe('11999999999');
});

test('normalizeCep remove hífen', function () {
    expect(Validation::normalizeCep('01310-100'))->toBe('01310100');
});
