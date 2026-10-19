<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Os simuladores não têm autenticação: APP_DEBUG ligado por engano em produção não pode
// permitir marcar cobrança como paga.
test('simuladores de pagamento ficam fechados em produção mesmo com debug ligado', function (string $uri) {
    config(['app.debug' => true]);
    app()->detectEnvironment(fn () => 'production');

    $this->postJson($uri, ['company_id' => 1, 'order_id' => 1])->assertForbidden();
})->with(['/dev/simulate/asaas-payment', '/dev/simulate/vindi-payment']);
