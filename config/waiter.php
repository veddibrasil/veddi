<?php

return [

    // Mensalidade do módulo Garçom, cobrado por cima do plano atual da empresa (exige PDV ativo).
    'addon_monthly_price' => (float) env('WAITER_ADDON_PRICE', 99.00),

];
