<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class DiscordWebhookHandler extends AbstractProcessingHandler
{
    /** Maximum messages per minute per unique message signature */
    private const RATE_LIMIT = 5;
    private const RATE_WINDOW = 60; // seconds

    /** In-memory counter keyed by message signature (resets each process/request) */
    private static array $counts = [];
    private static array $windows = [];

    public function __construct(
        private readonly string $webhookUrl,
        int|string|Level $level = Level::Critical,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (empty($this->webhookUrl)) {
            return;
        }

        if ($this->isRateLimited($record)) {
            return;
        }

        $payload = json_encode([
            'embeds' => [$this->buildEmbed($record)],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->sendToDiscord($payload);
    }

    private function buildEmbed(LogRecord $record): array
    {
        $fields = [
            ['name' => 'Channel', 'value' => $record->channel, 'inline' => true],
            ['name' => 'Environment', 'value' => app()->environment(), 'inline' => true],
        ];

        if (!empty($record->context)) {
            $exception = $record->context['exception'] ?? null;
            $ctx = array_filter($record->context, fn ($k) => $k !== 'exception', ARRAY_FILTER_USE_KEY);

            if (!empty($ctx)) {
                $fields[] = [
                    'name'  => 'Context',
                    'value' => substr('```json' . "\n" . json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```", 0, 1024),
                ];
            }

            if ($exception instanceof \Throwable) {
                $fields[] = [
                    'name'  => 'Exception',
                    'value' => substr(
                        get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine(),
                        0,
                        1024
                    ),
                ];
            }
        }

        return [
            'title'       => '[' . $record->level->getName() . '] ' . substr($record->message, 0, 256),
            'description' => substr($record->message, 0, 4096),
            'color'       => $this->colorForLevel($record->level),
            'fields'      => $fields,
            'footer'      => ['text' => config('app.name', 'Laravel')],
            'timestamp'   => $record->datetime->format(\DateTimeInterface::ATOM),
        ];
    }

    private function colorForLevel(Level $level): int
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical => 0xCC0000,
            Level::Error    => 0xFF4500,
            Level::Warning  => 0xFF8C00,
            Level::Notice   => 0x1E90FF,
            Level::Info     => 0x2ECC71,
            default         => 0x95A5A6,
        };
    }

    private function isRateLimited(LogRecord $record): bool
    {
        $key = md5($record->channel . ':' . substr($record->message, 0, 100));
        $now = time();

        if (! isset(self::$windows[$key]) || ($now - self::$windows[$key]) >= self::RATE_WINDOW) {
            self::$windows[$key] = $now;
            self::$counts[$key]  = 0;
        }

        self::$counts[$key]++;

        return self::$counts[$key] > self::RATE_LIMIT;
    }

    private function sendToDiscord(string $payload): void
    {
        try {
            $ch = curl_init($this->webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Discord indisponível não deve quebrar a aplicação
        }
    }
}
