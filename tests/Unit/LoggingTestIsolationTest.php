<?php

use Tests\TestCase;

uses(TestCase::class);

// O DiscordWebhookHandler envia via curl direto (fora do Http::fake/preventStrayRequests).
// Se a URL do .env vazar para a suíte, cada log de erro dos testes vai parar no Discord real.
it('não deixa a suíte de testes enviar logs ao Discord', function () {
    expect(config('logging.channels.discord_target.handler_with.webhookUrl'))->toBe('')
        ->and(config('logging.channels.discord_pix_target.handler_with.webhookUrl'))->toBe('');
});
