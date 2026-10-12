<?php

/*
|--------------------------------------------------------------------------
| Templates de notificação do WhatsApp (Cloud API)
|--------------------------------------------------------------------------
|
| Definição dos templates criados na WABA de cada restaurante. A chave é o
| "evento do template" (ready se desdobra em ready_pickup / ready_delivery).
|
| - name:      nome do template na Meta (minúsculas + underscore, imutável).
| - body:      corpo com variáveis {{n}}. Nunca começar ou terminar com variável.
| - variables: o que cada {{n}} representa, na ordem (ver WhatsAppService::templateVariable).
| - example:   valores de exemplo exigidos pela Meta na aprovação (mesma ordem).
|
| Mudar body/name de um template já aprovado exige um template novo: a Meta
| não permite editar o texto sem reenviar para aprovação.
|
*/

return [

    'language' => 'pt_BR',

    'category' => 'UTILITY',

    'templates' => [

        'new_order' => [
            'name' => 'pedido_recebido',
            'body' => 'Olá, {{1}}! Recebemos seu pedido #{{2}} no valor de {{3}}. Vamos te avisar por aqui a cada atualização.',
            'variables' => ['customer_name', 'order_number', 'total'],
            'example' => ['Maria', '1042', 'R$ 45,90'],
        ],

        'paid' => [
            'name' => 'pedido_pagamento_confirmado',
            'body' => 'Pagamento do pedido #{{1}} confirmado! Ele já está na fila de preparo.',
            'variables' => ['order_number'],
            'example' => ['1042'],
        ],

        'scheduled' => [
            'name' => 'pedido_agendado',
            'body' => 'Seu pedido #{{1}} está agendado para {{2}}. Avisaremos quando começarmos a preparar.',
            'variables' => ['order_number', 'scheduled_at'],
            'example' => ['1042', '25/09/2026 às 19:30'],
        ],

        'preparing' => [
            'name' => 'pedido_em_preparo',
            'body' => 'Seu pedido #{{1}} está sendo preparado. Em breve ficará pronto!',
            'variables' => ['order_number'],
            'example' => ['1042'],
        ],

        'ready_pickup' => [
            'name' => 'pedido_pronto_retirada',
            'body' => 'Seu pedido #{{1}} está pronto para retirada. Estamos te esperando!',
            'variables' => ['order_number'],
            'example' => ['1042'],
        ],

        'ready_delivery' => [
            'name' => 'pedido_pronto_entrega',
            'body' => 'Seu pedido #{{1}} está pronto e logo sai para entrega.',
            'variables' => ['order_number'],
            'example' => ['1042'],
        ],

        'out_for_delivery' => [
            'name' => 'pedido_saiu_entrega',
            'body' => 'Seu pedido #{{1}} saiu para entrega. Fique de olho, está chegando!',
            'variables' => ['order_number'],
            'example' => ['1042'],
        ],

        'delivered' => [
            'name' => 'pedido_entregue',
            'body' => 'Pedido #{{1}} entregue. Obrigado pela preferência e bom apetite!',
            'variables' => ['order_number'],
            'example' => ['1042'],
        ],

        'cancelled' => [
            'name' => 'pedido_cancelado',
            'body' => 'Seu pedido #{{1}} foi cancelado. Se precisar de ajuda, entre em contato com a loja.',
            'variables' => ['order_number'],
            'example' => ['1042'],
        ],

        'refunded' => [
            'name' => 'pedido_reembolsado',
            'body' => 'O reembolso do pedido #{{1}} foi processado. Qualquer dúvida, entre em contato com a loja.',
            'variables' => ['order_number'],
            'example' => ['1042'],
        ],

        // awaiting_payment (código PIX por WhatsApp) fica fora desta entrega — pendência.
    ],

];
