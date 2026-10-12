<?php

namespace App\Support\Ifood;

/**
 * Nomes em português pros campos e valores da Merchant API mostrados em
 * Configurações → Integração iFood. O nome original do campo continua visível (menor),
 * porque a homologação pede a resposta completa da API.
 */
class IfoodFieldLabels
{
    private const FIELDS = [
        'id' => 'ID',
        'name' => 'Nome',
        'corporateName' => 'Razão social',
        'description' => 'Descrição',
        'averageTicket' => 'Ticket médio',
        'exclusive' => 'Exclusiva do iFood',
        'type' => 'Tipo',
        'status' => 'Situação',
        'createdAt' => 'Criada em',
        'address' => 'Endereço',
        'country' => 'País',
        'state' => 'Estado',
        'city' => 'Cidade',
        'postalCode' => 'CEP',
        'district' => 'Bairro',
        'street' => 'Rua',
        'number' => 'Número',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'operations' => 'Operações',
        'operation' => 'Operação',
        'salesChannels' => 'Canais de venda',
        'salesChannel' => 'Canal de venda',
        'enabled' => 'Habilitado',
        'available' => 'Disponível',
        'validations' => 'Validações',
        'code' => 'Código',
        'message' => 'Mensagem',
        'title' => 'Título',
        'subtitle' => 'Subtítulo',
        'priority' => 'Prioridade',
        'reopenable' => 'Pode reabrir',
        'identifier' => 'Identificador',
        'phone' => 'Telefone',
        'phones' => 'Telefones',
    ];

    private const VALUES = [
        'AVAILABLE' => 'Disponível',
        'UNAVAILABLE' => 'Indisponível',
        'OK' => 'OK',
        'WARNING' => 'Atenção',
        'CLOSED' => 'Fechada',
        'ERROR' => 'Erro',
        'DELIVERY' => 'Entrega',
        'TAKEOUT' => 'Retirada',
        'INDOOR' => 'Consumo no local',
        'IFOOD' => 'iFood',
        'RESTAURANT' => 'Restaurante',
        'is-connected' => 'Loja conectada',
        'opening-hours' => 'Horário de funcionamento',
        'unavailabilities' => 'Pausas',
        'radius-restriction' => 'Área de entrega',
        'payout-blocked' => 'Repasse bloqueado',
        'logistics-blocked' => 'Logística bloqueada',
        'terms-service-violation' => 'Violação dos termos',
        'status-availability' => 'Disponibilidade',
    ];

    public static function field(string|int $key): string
    {
        if (is_int($key)) {
            return 'Registro '.($key + 1);
        }

        return self::FIELDS[$key] ?? $key;
    }

    /** Nome original quando há tradução, pra conferência com a resposta da API. */
    public static function original(string|int $key): ?string
    {
        return is_string($key) && isset(self::FIELDS[$key]) ? $key : null;
    }

    public static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Sim' : 'Não',
            is_string($value) && isset(self::VALUES[$value]) => self::VALUES[$value],
            default => (string) $value,
        };
    }
}
