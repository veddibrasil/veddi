<?php

namespace App\Console\Commands;

use App\Contracts\KeetaGatewayContract;
use App\Exceptions\KeetaApiException;
use App\Models\KeetaIntegration;
use App\Services\Keeta\KeetaClient;
use Illuminate\Console\Command;
use Throwable;

class KeetaHomologationCheck extends Command
{
    protected $signature = 'keeta:homologation-check {--live : Consulta autorização e token na API real} {--json : Retorna relatório JSON sem credenciais}';

    protected $description = 'Verifica os pré-requisitos Keeta sem alterar lojas, pedidos ou enviar notificações.';

    public function handle(KeetaClient $client, KeetaGatewayContract $gateway): int
    {
        $checks = [];
        $add = function (string $id, bool $ok, string $message) use (&$checks): void {
            $checks[] = ['check' => $id, 'status' => $ok ? 'pass' : 'blocked', 'message' => $message];
        };

        $configured = filled(config('keeta.client_id')) && filled(config('keeta.client_secret'));
        $add('enabled', (bool) config('keeta.enabled'), 'KEETA_ENABLED deve estar habilitado no ambiente SIT.');
        $add('credentials', $configured, $configured ? 'Client ID e secret presentes (valores omitidos).' : 'Preencha KEETA_CLIENT_ID e KEETA_CLIENT_SECRET.');
        $add('app_id', filled(config('keeta.app_id')), 'KEETA_APP_ID confere o remetente dos webhooks de pedidos (a Keeta manda "Keeta").');
        $add('public_url', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL deve usar HTTPS público; a acessibilidade precisa ser validada externamente.');

        $integrations = KeetaIntegration::withoutGlobalScopes()->with('company')->get();
        $linked = $integrations->filter(fn (KeetaIntegration $integration) => $integration->isOperational());
        $add('store_mapping', $linked->isNotEmpty(), $linked->isNotEmpty()
            ? $linked->count().' loja(s) operacional(is).'
            : 'Nenhuma loja operacional: crie a loja de teste, autorize o app e conclua o vínculo na tela Keeta.');
        $add('catalog', $linked->contains(fn (KeetaIntegration $integration) => $integration->hasPublishedCatalog()), 'Ao menos uma loja de teste precisa de cardápio publicado.');

        if ($this->option('live') && $configured) {
            foreach ([
                'authorization_url' => fn () => $gateway->authorizationUrl(),
                'token' => fn () => $client->accessToken(force: true),
            ] as $id => $request) {
                try {
                    $request();
                    $add($id, true, $id === 'token' ? 'Token emitido; valor omitido.' : 'URL de autorização e assinatura aceitas pela Keeta.');
                } catch (Throwable $e) {
                    $message = $e instanceof KeetaApiException ? $e->userMessage() : 'Não foi possível concluir a consulta à Keeta.';
                    if ($id === 'token' && $e instanceof KeetaApiException && $e->httpStatus === 401 && str_contains((string) $e->title, 'no authorized Merchant')) {
                        $message = 'HTTP 401: o app ainda não possui nenhuma loja autorizada. Autorize a loja antes de emitir o token.';
                    }
                    $add($id, false, $message);
                }
            }
        }

        $ready = collect($checks)->every(fn (array $check) => $check['status'] === 'pass');
        if ($this->option('json')) {
            $this->line(json_encode(['ready' => $ready, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Verificação', 'Resultado', 'Evidência / próximo passo'], array_map(fn (array $check) => array_values($check), $checks));
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
