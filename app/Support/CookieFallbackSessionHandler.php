<?php

namespace App\Support;

use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Session\CookieSessionHandler;
use SessionHandlerInterface;

/**
 * Transição de SESSION_DRIVER=cookie para um driver no servidor sem deslogar ninguém: a sessão que
 * ainda só existe no cookie é lida uma vez e passa a ser gravada no destino, com o mesmo id e o
 * mesmo token CSRF. Estende CookieSessionHandler para o Store entregar o request ao handler.
 *
 * Provisório: depois de session.lifetime sem cookie novo, nenhuma sessão antiga segue válida e o
 * driver volta a ser `database` puro. Só nessa janela um cookie antigo copiado ainda reabre a
 * sessão depois do logout, como já acontecia com o driver cookie.
 */
class CookieFallbackSessionHandler extends CookieSessionHandler
{
    /**
     * @param  Encrypter|null  $encrypter  Presente quando session.encrypt está ligado: o cookie antigo
     *                                     guarda a sessão em texto, e o EncryptedStore espera conteúdo cifrado.
     */
    public function __construct(
        private SessionHandlerInterface $target,
        CookieJar $cookie,
        $minutes,
        $expireOnClose = false,
        private ?Encrypter $encrypter = null,
    ) {
        parent::__construct($cookie, $minutes, $expireOnClose);
    }

    public function read($sessionId): string|false
    {
        $data = $this->target->read($sessionId);

        if ($data !== '' && $data !== false) {
            return $data;
        }

        $legacy = parent::read($sessionId);

        if ($legacy === '' || $legacy === false) {
            return $legacy;
        }

        // Daqui em diante a sessão mora no destino: o cookie só pesaria no request.
        parent::destroy($sessionId);

        return $this->encrypter?->encrypt($legacy) ?? $legacy;
    }

    public function write($sessionId, $data): bool
    {
        return $this->target->write($sessionId, $data);
    }

    public function destroy($sessionId): bool
    {
        parent::destroy($sessionId);

        return $this->target->destroy($sessionId);
    }

    public function gc($lifetime): int
    {
        return $this->target->gc($lifetime);
    }
}
