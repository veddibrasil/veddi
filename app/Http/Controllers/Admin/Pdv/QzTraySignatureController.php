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
 * storage/app/private/qz/digital-certificate.txt -days 3650 -nodes`), OU
 * via QZ_PRIVATE_KEY/QZ_CERTIFICATE (conteudo em base64) em ambientes sem
 * acesso a shell/filesystem persistente pra rodar o openssl (ex.: Laravel
 * Cloud) — env tem prioridade sobre o arquivo.
 * Sem nenhuma das duas fontes, os dois endpoints retornam 404 e o front
 * trata como "QZ Tray indisponível" sem quebrar o PDV.
 */
class QzTraySignatureController extends Controller
{
    public function certificate()
    {
        $certificate = $this->certificateContents();

        abort_unless($certificate !== null, 404);

        return response($certificate, 200, ['Content-Type' => 'text/plain']);
    }

    public function sign(Request $request)
    {
        $privateKey = $this->privateKeyContents();

        abort_unless($privateKey !== null, 404);

        $privateKey = openssl_pkey_get_private($privateKey);

        abort_if($privateKey === false, 500, 'Chave privada do QZ Tray inválida.');

        openssl_sign($request->input('request', ''), $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature), 200, ['Content-Type' => 'text/plain']);
    }

    private function privateKeyContents(): ?string
    {
        if ($encoded = config('services.qz.private_key')) {
            return base64_decode($encoded);
        }

        $path = storage_path('app/private/qz/private-key.pem');

        return is_readable($path) ? file_get_contents($path) : null;
    }

    private function certificateContents(): ?string
    {
        if ($encoded = config('services.qz.certificate')) {
            return base64_decode($encoded);
        }

        $path = storage_path('app/private/qz/digital-certificate.txt');

        return is_readable($path) ? file_get_contents($path) : null;
    }
}
