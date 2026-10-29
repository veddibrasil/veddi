<?php

namespace App\DTOs;

/**
 * Sugestão de venda gerada pelas regras de SalesInsightsService.
 *
 * actions: lista de ['label' => string, 'route' => nome da rota, 'params' => array]; os atalhos só
 * pré-preenchem a tela de destino, nada é gravado sem o admin confirmar lá.
 */
readonly class SalesInsight
{
    public const PRIORITY_NOW = 1;

    public const PRIORITY_OPPORTUNITY = 2;

    public const PRIORITY_IDEA = 3;

    public const TYPES = [
        'champion' => 'Campeões de venda',
        'unavailable_champion' => 'Disponibilidade',
        'stock_running_out' => 'Reposição de estoque',
        'peak_time' => 'Picos de venda',
        'trending_up' => 'Produtos em alta',
        'trending_down' => 'Queda de vendas',
        'stale' => 'Produtos parados',
        'slow_mover' => 'Baixo giro',
        'combo' => 'Comprados juntos',
        'weak_hour' => 'Horários fracos',
        'promo_no_effect' => 'Promoções',
        'ifood_dependency' => 'Canais de venda',
        'catalog_presentation' => 'Foto e descrição',
    ];

    public function __construct(
        public string $type,
        public string $scope,
        public int $priority,
        public string $title,
        public string $message,
        public array $evidence = [],
        public ?string $productKey = null,
        public ?int $productId = null,
        public array $actions = [],
        public float $weight = 0.0,
        public array $relatedKeys = [],
    ) {}

    /** A sugestão fala deste produto (como principal ou como par de combo)? */
    public function concerns(string $productKey): bool
    {
        return $this->productKey === $productKey || in_array($productKey, $this->relatedKeys, true);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
