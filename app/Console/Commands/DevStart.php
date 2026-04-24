<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DevStart extends Command
{
    protected $signature = 'dev:start';

    protected $description = 'Inicia todos os serviços de desenvolvimento: schedule, reverb, queue e npm dev';

    /** @var Process[] */
    private array $processes = [];

    public function handle(): int
    {
        $services = [
            'schedule' => ['./vendor/bin/sail', 'artisan', 'schedule:work'],
            'reverb' => ['./vendor/bin/sail', 'artisan', 'reverb:start', '--debug'],
            'queue' => ['./vendor/bin/sail', 'artisan', 'queue:work',  '--queue=critical,default,low --sleep=10 --tries=3'],
            'npm' => ['npm', 'run', 'dev'],
        ];

        $this->info('Iniciando serviços...');

        foreach ($services as $name => $cmd) {
            $process = new Process($cmd, base_path(), null, null, null);
            $process->start(function (string $type, string $output) use ($name) {
                $prefix = match ($type) {
                    Process::ERR => "<fg=red>[{$name}]</>",
                    default => "<fg=cyan>[{$name}]</>",
                };
                $this->output->write("{$prefix} {$output}");
            });

            $this->processes[$name] = $process;
            $this->line("<info>{$name}</info> iniciado (PID: {$process->getPid()})");
        }

        $this->info('Todos os serviços estão rodando. Pressione Ctrl+C para encerrar.');

        $this->trapSignals();

        while (true) {
            foreach ($this->processes as $name => $process) {
                if (! $process->isRunning()) {
                    $this->warn("{$name} encerrou inesperadamente (código: {$process->getExitCode()}).");
                }
            }

            usleep(500_000);
        }
    }

    private function trapSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        $stop = function () {
            $this->newLine();
            $this->info('Encerrando serviços...');

            foreach ($this->processes as $name => $process) {
                $process->stop(3);
                $this->line("<info>{$name}</info> encerrado.");
            }

            exit(0);
        };

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }
}
