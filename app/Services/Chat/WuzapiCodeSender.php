<?php

namespace App\Services\Chat;

use App\Contracts\ChatCodeSender;
use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Dedicated session on the existing WuzAPI server; exclusively for chat authentication. */
class WuzapiCodeSender implements ChatCodeSender
{
    public static function isConfigured(): bool
    {
        $url = parse_url((string) config('services.wuzapi.base_url'));

        return config('chat_security.whatsapp_wuzapi_fallback_enabled')
            && filled(config('services.wuzapi.session_token'))
            && is_array($url)
            && ($url['scheme'] ?? '') === 'https'
            && filled($url['host'] ?? null)
            && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']);
    }

    public function send(int $companyId, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $code, ?string $challengeId = null): void
    {
        if (! config('chat_security.whatsapp_auth_enabled') || ! self::isConfigured()) {
            throw new RuntimeException('Fallback de autenticação indisponível.');
        }
        if (! preg_match('/^\d{10,11}$/', $phone) || ! preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('Dados de autenticação inválidos.');
        }
        $company = Company::findOrFail($companyId);
        $status = $this->request('get', '/session/status');
        if (($status['connected'] ?? false) !== true || ($status['loggedIn'] ?? false) !== true) {
            throw new RuntimeException('Sessão de autenticação desconectada.');
        }

        // An opaque, stable WhatsApp message id for this challenge, without phone or code.
        // There are no automatic HTTP retries; a timeout is ambiguous even with a custom id.
        $messageId = strtoupper(substr(hash_hmac('sha256', $companyId.'|'.($challengeId ?? Str::uuid()->toString()), config('app.key')), 0, 32));
        $companyName = Str::limit(preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $company->name) ?? '', 100);
        $response = $this->request('post', '/chat/send/text', [
            'Phone' => '55'.$phone,
            'Body' => "Seu código de verificação para acessar seus dados na {$companyName} é {$code}. Válido por 5 minutos. Se não foi você, ignore esta mensagem.",
            'Id' => $messageId,
        ]);
        if (! is_string($response['Id'] ?? null) || $response['Id'] === '') {
            throw new RuntimeException('Envio de autenticação não confirmado.');
        }
    }

    private function request(string $method, string $path, #[\SensitiveParameter] array $data = []): array
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('services.wuzapi.base_url'), '/'))
                ->acceptJson()->asJson()
                ->withHeaders(['token' => (string) config('services.wuzapi.session_token')])
                ->withoutRedirecting()->connectTimeout(2)->timeout(4)
                ->send(strtoupper($method), $path, $data === [] ? [] : ['json' => $data]);
        } catch (ConnectionException) {
            // Do not retain a previous exception or raw response: either may contain the OTP.
            throw new RuntimeException('Falha de conexão com o serviço de autenticação.');
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? false) !== true || ! is_array($body['data'] ?? null)) {
            throw new RuntimeException('Serviço de autenticação recusou a solicitação.');
        }

        return $body['data'];
    }
}
