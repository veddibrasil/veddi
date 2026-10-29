<?php

/*
|--------------------------------------------------------------------------
| Relatório de itens vendidos e sugestões de venda
|--------------------------------------------------------------------------
|
| Limiares das regras de SalesInsightsService. Os mínimos de volume existem
| para não sugerir nada com base em meia dúzia de pedidos (ruído).
|
*/

return [

    // Período máximo do relatório (o mesmo limite do PDF de pedidos).
    'max_range_days' => 92,

    // Períodos curtos não mostram padrão: as regras olham pelo menos estes dias até a data final.
    'min_window_days' => 28,

    // Abaixo disto nenhuma sugestão é gerada.
    'min_orders' => 20,

    'max_per_product' => 2,
    'max_total' => 20,

    // Cache do agregado: curto quando o período inclui hoje, longo para períodos fechados.
    'cache_ttl_today' => 300,
    'cache_ttl_past' => 21600,

    // Sugestões dependem do catálogo/estoque atual: cache só para não recalcular a cada tecla.
    'cache_ttl_insights' => 120,

    // "Comprados juntos": pedidos grandes (mesa, evento) geram pares sem significado.
    'pairs' => [
        'max_products_per_order' => 12,
        'min_support' => 2,
        'cap' => 500,
    ],

    'rules' => [
        'champion' => ['min_orders' => 30, 'revenue_share' => 0.8, 'max_products_share' => 0.2, 'top' => 3],
        'unavailable_champion' => ['top' => 5, 'channel_share' => 0.3],
        'stock_running_out' => ['recent_days' => 14, 'min_daily_avg' => 0.5, 'max_cover_days' => 3],
        'peak_time' => ['top' => 8, 'min_units' => 20, 'weekday_ratio' => 1.5, 'min_weekday_occurrences' => 2, 'block_share' => 0.35, 'lead_hours' => 2, 'discount' => 10],
        'trending_up' => ['min_previous' => 5, 'min_current' => 15, 'ratio' => 1.3],
        'trending_down' => ['min_previous' => 15, 'ratio' => 0.7, 'top' => 10, 'discount' => 10, 'coupon_days' => 7],
        'stale' => ['days' => 14, 'lookback_days' => 90, 'group_above' => 5, 'promo' => 15],
        'slow_mover' => ['min_window_days' => 14, 'min_products' => 10, 'bottom_share' => 0.2, 'max_daily_avg' => 0.3, 'discount' => 15, 'coupon_days' => 7],
        'combo' => ['min_orders' => 8, 'confidence' => 0.25, 'lift' => 1.5, 'top' => 5, 'discount' => 10],
        'weak_hour' => ['min_window_days' => 14, 'min_orders' => 50, 'operating_share' => 0.3, 'weak_ratio' => 0.4, 'blocks' => 2, 'discount' => 15, 'max_uses' => 50],
        'promo_no_effect' => ['min_previous' => 10, 'max_ratio' => 1.05],
        'catalog_presentation' => ['min_units' => 10],
        'ifood_dependency' => ['min_orders' => 30, 'revenue_share' => 0.5, 'product_min_units' => 20, 'product_share' => 0.7, 'discount' => 10, 'code' => 'PEDEDIRETO'],
    ],

];
