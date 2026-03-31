<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class DiscordWebhookTap
{
    public function __invoke(Logger $logger): void
    {
        $webhookUrl = config('logging.channels.discord.url', '');
        $level = config('logging.channels.discord.level', 'critical');

        if (empty($webhookUrl)) {
            return;
        }

        $logger->getLogger()->pushHandler(
            new DiscordWebhookHandler($webhookUrl, $level)
        );
    }
}
