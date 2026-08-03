<?php

namespace App\Http\Controllers\Admin\Pdv;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * QZ Tray exige que o site assine cada requisição de impressão com uma chave
 * RSA própria, pra não exibir o prompt de "site não confiável" a cada cupom.
 * O par de chaves não é gerado por este código — precisa existir em
 * storage/app/private/qz/ (private-key.pem + digital-certificate.txt), gerado
 * uma vez por ambiente (ex.: `openssl req -x509 -newkey rsa:2048 -keyout
 * storage/app/private/qz/private-key.pem -out
 * storage/app/private/qz/digital-certificate.txt -days 3650 -nodes`).
 * Sem esses arquivos, os dois endpoints retornam 404 e o front trata como
 * "QZ Tray indisponível" sem quebrar o PDV.
 */
class QzTraySignatureController extends Controller
{
    public function certificate()
    {
        $path = storage_path('app/private/qz/digital-certificate.txt');

        abort_unless(is_readable($path), 404);

        return response(file_get_contents($path), 200, ['Content-Type' => 'text/plain']);
    }

    public function sign(Request $request)
    {
        $keyPath = storage_path('app/private/qz/private-key.pem');

        abort_unless(is_readable($keyPath), 404);

        $privateKey = openssl_pkey_get_private(file_get_contents($keyPath));

        abort_if($privateKey === false, 500, 'Chave privada do QZ Tray inválida.');

        openssl_sign($request->input('request', ''), $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature), 200, ['Content-Type' => 'text/plain']);
    }
}
