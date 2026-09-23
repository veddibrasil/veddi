<?php

namespace App\Services\Messaging;

use App\Contracts\WhatsAppManagementInterface;
use App\Exceptions\WhatsAppApiException;
use App\Exceptions\WhatsAppAuthException;
use App\Exceptions\WhatsAppOnboardingException;
use App\Exceptions\WhatsAppRetryableException;
use App\Jobs\CompleteWhatsAppOnboarding;
use App\Jobs\ProvisionWhatsAppTemplates;
use App\Models\Company;
use App\Models\WhatsAppConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Onboarding por Embedded Signup: cada restaurante conecta o próprio número (novo ou em
 * coexistência com o app WhatsApp Business).
 *
 * start() é síncrono e só grava/valida; complete() roda no job. O code do Embedded Signup é de
 * uso único e expira em segundos: a troca por token nunca é retentada, mas tudo que vem depois
 * é retomável (o token já fica salvo) e idempotente.
 */
class WhatsAppOnboardingService
{
    public const MSG_CODE_EXCHANGE = 'Não foi possível concluir a autorização do WhatsApp. O código de autorização vale só uma vez e expira em segundos: clique em "Conectar WhatsApp" para refazer.';

    public const MSG_TOKEN_INVALID = 'A autorização recebida da Meta não é válida para a Veddi. Refaça a conexão.';

    public const MSG_NO_WABA = 'Não encontramos uma conta do WhatsApp Business na autorização. Refaça a conexão e conclua todas as etapas.';

    public const MSG_MULTIPLE_WABA = 'A autorização inclui mais de uma conta do WhatsApp Business e não foi possível identificar qual conectar. Refaça a conexão selecionando apenas uma.';

    public const MSG_WABA_MISMATCH = 'A conta do WhatsApp Business informada não corresponde à autorização recebida. Refaça a conexão.';

    public const MSG_NO_NUMBER = 'Não encontramos nenhum número na conta do WhatsApp Business. Adicione um número e refaça a conexão.';

    public const MSG_MULTIPLE_NUMBERS = 'A conta do WhatsApp Business tem mais de um número e não foi possível identificar qual conectar. Refaça a conexão selecionando o número.';

    public const MSG_ALREADY_LINKED = 'Este número (ou conta do WhatsApp Business) já está conectado a outra empresa na Veddi. Desconecte-o lá antes de conectar aqui ou fale com o suporte.';

    public const MSG_REGISTER_LIMIT = 'O número atingiu o limite de registros da Meta (10 a cada 72 horas). Aguarde e refaça a conexão mais tarde.';

    public const MSG_PIN_MISMATCH = 'O número tem verificação em duas etapas com outro PIN. Desative-a no WhatsApp Manager e refaça a conexão.';

    public const MSG_TOKEN_REVOKED = 'A autorização do WhatsApp foi recusada pela Meta. Refaça a conexão.';

    public const MSG_TEMPORARY = 'Não foi possível concluir a conexão com a Meta agora. Tente novamente em alguns minutos.';

    private const TYPES = [WhatsAppConnection::TYPE_CLOUD_API, WhatsAppConnection::TYPE_COEXISTENCE];

    /** Falhas de registro do número que não adiantam retentar. */
    private const REGISTER_LIMIT_CODE = 133016;

    private const REGISTER_PIN_MISMATCH_CODE = 133005;

    public function __construct(private WhatsAppManagementInterface $meta) {}

    /**
     * Registra a intenção de conectar e despacha a conclusão. Os ids vindos do front (postMessage
     * do Embedded Signup) são só dicas: waba_id é conferido contra os escopos do token e o número
     * contra o acesso do token, antes de qualquer efeito na Meta.
     *
     * @throws WhatsAppOnboardingException
     */
    public function start(Company $company, string $code, ?string $wabaId, ?string $phoneNumberId, string $onboardingType): WhatsAppConnection
    {
        $code = trim($code);
        $wabaId = filled($wabaId) ? trim((string) $wabaId) : null;
        $phoneNumberId = filled($phoneNumberId) ? trim((string) $phoneNumberId) : null;

        if ($code === '') {
            throw new WhatsAppOnboardingException(self::MSG_CODE_EXCHANGE);
        }

        if (! in_array($onboardingType, self::TYPES, true)) {
            throw new WhatsAppOnboardingException('Tipo de conexão do WhatsApp inválido. Refaça a conexão.');
        }

        foreach ([$wabaId, $phoneNumberId] as $id) {
            if ($id !== null && ! preg_match('/^\d{5,32}$/', $id)) {
                throw new WhatsAppOnboardingException(self::MSG_WABA_MISMATCH);
            }
        }

        try {
            $connection = DB::transaction(function () use ($company, $wabaId, $phoneNumberId, $onboardingType) {
                $this->releaseOrRejectLinks($company->id, $wabaId, $phoneNumberId);

                $connection = WhatsAppConnection::withoutGlobalScopes()->firstOrNew(['company_id' => $company->id]);

                // Recomeça do zero (o code novo gera um token novo). O PIN de registro é mantido de
                // propósito: número já registrado com PIN só volta a registrar com o mesmo PIN.
                $connection->fill([
                    'waba_id' => $wabaId,
                    'phone_number_id' => $phoneNumberId,
                    'onboarding_type' => $onboardingType,
                    'access_token' => null,
                    'token_scopes' => null,
                    'status' => WhatsAppConnection::STATUS_PENDING,
                    'last_error' => null,
                    'connected_at' => null,
                    'disconnected_at' => null,
                ])->save();

                // Templates de uma WABA anterior não valem para a nova; o provisionamento relê da Meta.
                $connection->templates()->delete();

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw new WhatsAppOnboardingException(self::MSG_ALREADY_LINKED);
        }

        Log::channel('whatsapp')->info('Onboarding WhatsApp iniciado', [
            'company_id' => $company->id,
            'connection_id' => $connection->id,
            'type' => $onboardingType,
            'waba_informada' => $wabaId !== null,
            'numero_informado' => $phoneNumberId !== null,
        ]);

        CompleteWhatsAppOnboarding::dispatch($connection->id, $code);

        return $connection;
    }

    /**
     * Conclui o onboarding (roda no job). Erros definitivos viram status=error com mensagem
     * amigável; só falha temporária depois da troca do code é relançada (o job retenta e retoma).
     *
     * @throws WhatsAppRetryableException
     */
    public function complete(WhatsAppConnection $connection, string $code): void
    {
        $connection->refresh();

        // Desconectou ou refez a conexão no meio do caminho: este code já não vale.
        if ($connection->status !== WhatsAppConnection::STATUS_PENDING) {
            Log::channel('whatsapp')->info('Onboarding WhatsApp ignorado: conexão não está pendente', [
                'connection_id' => $connection->id,
                'status' => $connection->status,
            ]);

            return;
        }

        try {
            $token = $this->obtainToken($connection, $code);
            $wabaId = $this->resolveWaba($connection, $token);
            $phoneNumberId = $this->resolvePhoneNumber($connection, $wabaId, $token);

            $this->linkIdentifiers($connection, $wabaId, $phoneNumberId);

            $this->meta->subscribeApp($wabaId, $token);

            // Coexistência: o número já vive no app WhatsApp Business; registrar tiraria o número do app.
            if ($connection->onboarding_type === WhatsAppConnection::TYPE_CLOUD_API) {
                $this->registerNumber($connection, $phoneNumberId, $token);
            }

            // Cloud API: dados do número recém-registrado. Coexistência: só confirma que o número existe.
            $this->refreshPhoneInfo($connection);

            $connection->update(['status' => WhatsAppConnection::STATUS_PROVISIONING, 'last_error' => null]);
        } catch (WhatsAppOnboardingException $e) {
            $this->fail($connection, $e->getMessage());

            return;
        } catch (WhatsAppRetryableException $e) {
            Log::channel('whatsapp')->warning('Onboarding WhatsApp: falha temporária, o job vai retomar', [
                'connection_id' => $connection->id,
                'code' => $e->getCode(),
            ]);

            throw $e;
        } catch (WhatsAppAuthException $e) {
            $this->fail($connection, self::MSG_TOKEN_REVOKED, $e);
            WhatsAppCriticalLog::authRefused('onboarding', $connection->id, $connection->company_id, $e);

            return;
        } catch (WhatsAppApiException $e) {
            $this->fail($connection, $this->messageFor($e), $e);

            return;
        }

        ProvisionWhatsAppTemplates::dispatch($connection->id);

        Log::channel('whatsapp')->info('Onboarding WhatsApp: número conectado, provisionando templates', [
            'connection_id' => $connection->id,
            'type' => $connection->onboarding_type,
        ]);
    }

    /**
     * Desconecta: cancela a inscrição do app na WABA (erros, inclusive de token, são ignorados —
     * o restaurante pode já ter revogado o acesso) e descarta o token. O PIN de registro fica:
     * é o que permite reconectar o mesmo número depois.
     */
    public function disconnect(WhatsAppConnection $connection): void
    {
        if (filled($connection->waba_id) && filled($connection->access_token)) {
            try {
                $this->meta->unsubscribeApp($connection->waba_id, $connection->access_token);
            } catch (WhatsAppApiException $e) {
                Log::channel('whatsapp')->warning('Desconexão WhatsApp: não foi possível remover a inscrição do app (seguindo)', [
                    'connection_id' => $connection->id,
                    'code' => $e->getCode(),
                ]);
            }
        }

        $connection->update([
            'access_token' => null,
            'token_scopes' => null,
            'status' => WhatsAppConnection::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
            'last_error' => null,
        ]);

        Log::channel('whatsapp')->info('Conexão WhatsApp desconectada', [
            'company_id' => $connection->company_id,
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Atualiza nome verificado, número, qualidade e limite a partir da Graph API.
     *
     * @throws WhatsAppApiException
     */
    public function refreshPhoneInfo(WhatsAppConnection $connection): void
    {
        $info = $this->meta->getPhoneNumber((string) $connection->phone_number_id, (string) $connection->access_token);

        $connection->update(array_filter($info, fn ($value) => filled($value)));
    }

    // ── etapas do complete() ──────────────────────────────────────────────────

    private function obtainToken(WhatsAppConnection $connection, string $code): string
    {
        // Retomada: o token já foi obtido numa tentativa anterior e o code não vale mais.
        if (filled($connection->access_token)) {
            return $connection->access_token;
        }

        try {
            $token = $this->meta->exchangeCode($code);
        } catch (WhatsAppApiException $e) {
            // Uso único: nem falha temporária é retentada (não dá para saber se a Meta já consumiu o code).
            Log::channel('whatsapp')->warning('Onboarding WhatsApp: troca do code falhou', [
                'connection_id' => $connection->id,
                'code' => $e->getCode(),
                'http_status' => $e->httpStatus,
            ]);

            throw new WhatsAppOnboardingException(self::MSG_CODE_EXCHANGE);
        }

        $connection->update(['access_token' => $token]);

        return $token;
    }

    /** Confere o token (é do nosso app e ainda válido) e a WABA autorizada. */
    private function resolveWaba(WhatsAppConnection $connection, string $token): string
    {
        $info = $this->meta->debugToken($token);

        $ownApp = (string) config('services.meta.app_id');

        if (! $info['is_valid'] || ($info['app_id'] !== null && $ownApp !== '' && $info['app_id'] !== $ownApp)) {
            throw new WhatsAppOnboardingException(self::MSG_TOKEN_INVALID);
        }

        $authorized = $info['waba_ids'];

        if (filled($connection->waba_id)) {
            if (! in_array((string) $connection->waba_id, $authorized, true)) {
                throw new WhatsAppOnboardingException(self::MSG_WABA_MISMATCH);
            }

            $wabaId = (string) $connection->waba_id;
        } else {
            if ($authorized === []) {
                throw new WhatsAppOnboardingException(self::MSG_NO_WABA);
            }

            if (count($authorized) > 1) {
                throw new WhatsAppOnboardingException(self::MSG_MULTIPLE_WABA);
            }

            $wabaId = $authorized[0];
        }

        $connection->update(['waba_id' => $wabaId, 'token_scopes' => $info['granular_scopes']]);

        return $wabaId;
    }

    /** Número informado pelo front, ou descoberto na WABA (fluxo de coexistência não informa). */
    private function resolvePhoneNumber(WhatsAppConnection $connection, string $wabaId, string $token): string
    {
        if (filled($connection->phone_number_id)) {
            return (string) $connection->phone_number_id;
        }

        $numbers = $this->meta->listPhoneNumbers($wabaId, $token);

        if ($numbers === []) {
            throw new WhatsAppOnboardingException(self::MSG_NO_NUMBER);
        }

        if (count($numbers) > 1) {
            throw new WhatsAppOnboardingException(self::MSG_MULTIPLE_NUMBERS);
        }

        return $numbers[0]['id'];
    }

    /** Grava os ids definitivos, garantindo que nenhuma outra empresa esteja com eles. */
    private function linkIdentifiers(WhatsAppConnection $connection, string $wabaId, string $phoneNumberId): void
    {
        try {
            DB::transaction(function () use ($connection, $wabaId, $phoneNumberId) {
                $this->releaseOrRejectLinks($connection->company_id, $wabaId, $phoneNumberId);

                $connection->update(['waba_id' => $wabaId, 'phone_number_id' => $phoneNumberId]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new WhatsAppOnboardingException(self::MSG_ALREADY_LINKED);
        }
    }

    /**
     * Ids (WABA/número) em uso por OUTRA empresa: ativo/pendente/com erro bloqueia; já desconectado
     * é vínculo velho e é liberado (quem conecta agora provou a posse do número à Meta no signup).
     */
    private function releaseOrRejectLinks(int $companyId, ?string $wabaId, ?string $phoneNumberId): void
    {
        if ($wabaId === null && $phoneNumberId === null) {
            return;
        }

        $others = WhatsAppConnection::withoutGlobalScopes()
            ->where('company_id', '!=', $companyId)
            ->where(function ($query) use ($wabaId, $phoneNumberId) {
                if ($wabaId !== null) {
                    $query->orWhere('waba_id', $wabaId);
                }

                if ($phoneNumberId !== null) {
                    $query->orWhere('phone_number_id', $phoneNumberId);
                }
            })
            ->get();

        foreach ($others as $other) {
            if ($other->status !== WhatsAppConnection::STATUS_DISCONNECTED) {
                Log::channel('whatsapp')->warning('Onboarding WhatsApp: WABA/número já vinculados a outra empresa', [
                    'company_id' => $companyId,
                    'other_company_id' => $other->company_id,
                    'other_connection_id' => $other->id,
                ]);

                throw new WhatsAppOnboardingException(self::MSG_ALREADY_LINKED);
            }

            $other->update([
                'waba_id' => $other->waba_id === $wabaId ? null : $other->waba_id,
                'phone_number_id' => $other->phone_number_id === $phoneNumberId ? null : $other->phone_number_id,
            ]);
        }
    }

    /**
     * Registra o número na Cloud API com um PIN de 6 dígitos (verificação em duas etapas).
     * O PIN é gravado antes da chamada e reaproveitado nas tentativas seguintes.
     */
    private function registerNumber(WhatsAppConnection $connection, string $phoneNumberId, string $token): void
    {
        if (blank($connection->registration_pin)) {
            $connection->update(['registration_pin' => (string) random_int(100000, 999999)]);
        }

        try {
            $this->meta->registerPhone($phoneNumberId, $token, (string) $connection->registration_pin);
        } catch (WhatsAppRetryableException $e) {
            throw $e;
        } catch (WhatsAppApiException $e) {
            // 133016 e 133005 não têm o que retentar: precisam de ação do restaurante/suporte.
            if ($e->getCode() === self::REGISTER_LIMIT_CODE) {
                throw new WhatsAppOnboardingException(self::MSG_REGISTER_LIMIT);
            }

            if ($e->getCode() === self::REGISTER_PIN_MISMATCH_CODE) {
                throw new WhatsAppOnboardingException(self::MSG_PIN_MISMATCH);
            }

            throw $e;
        }
    }

    // ── erros ─────────────────────────────────────────────────────────────────

    /** Falha definitiva: status=error, mensagem amigável e token descartado (o retry recomeça do start). */
    public function fail(WhatsAppConnection $connection, string $message, ?WhatsAppApiException $cause = null): void
    {
        Log::channel('whatsapp')->error('Onboarding WhatsApp falhou', array_filter([
            'connection_id' => $connection->id,
            'company_id' => $connection->company_id,
            'reason' => $message,
            'meta_code' => $cause?->getCode(),
            'meta_message' => $cause?->getMessage(),
        ], fn ($value) => $value !== null));

        $connection->update([
            'status' => WhatsAppConnection::STATUS_ERROR,
            'last_error' => $message,
            'access_token' => null,
            'token_scopes' => null,
        ]);
    }

    private function messageFor(WhatsAppApiException $e): string
    {
        return 'Não foi possível concluir a conexão com a Meta (código '.($e->getCode() ?: 'desconhecido').'). Tente novamente ou fale com o suporte.';
    }
}
