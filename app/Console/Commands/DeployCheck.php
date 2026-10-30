<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeployCheck extends Command
{
    /**
     * Cookie limita a sessão a 4 KB: o chat passa disso no primeiro passo, o navegador descarta o
     * cookie e o cliente perde acesso ao próprio pedido antes de ver o PIX ou o formulário do cartão.
     */
    private const SESSION_DRIVERS = ['database', 'database-from-cookie', 'redis'];

    protected $signature = 'deploy:check';

    protected $description = 'Interrompe o deploy quando a configuração de produção quebra sessão ou expõe dados dela.';

    public function handle(): int
    {
        if (! app()->isProduction()) {
            return self::SUCCESS;
        }

        $driver = config('session.driver');

        if (! in_array($driver, self::SESSION_DRIVERS, true)) {
            $this->error("SESSION_DRIVER={$driver} não é aceito em produção. Use database.");

            return self::FAILURE;
        }

        // O chat guarda nome, telefone, endereço e CPF na sessão.
        if (! config('session.encrypt')) {
            $this->error('SESSION_ENCRYPT precisa ser true em produção.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
