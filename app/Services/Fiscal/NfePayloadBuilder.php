<?php

namespace App\Services\Fiscal;

use App\DTOs\FiscalNoteDTO;

class NfePayloadBuilder
{
    public static function build(FiscalNoteDTO $dto, array $payload): array
    {
        $recipient = $dto->recipient;
        $payload = array_replace($payload, [
            'tipo_documento' => 1,
            'finalidade_emissao' => 1,
            'consumidor_final' => 1,
            'local_destino' => $dto->emitenteUf === $recipient['state'] ? '1' : '2',
            'presenca_comprador' => $dto->orderType === 'delivery' ? '2' : '1',
            'cnpj_destinatario' => $recipient['document'],
            'nome_destinatario' => $dto->environment === 'homologacao'
                ? 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL' : $recipient['name'],
            'indicador_inscricao_estadual_destinatario' => (int) $recipient['ie_indicator'],
            'logradouro_destinatario' => $recipient['street'],
            'numero_destinatario' => $recipient['number'],
            'bairro_destinatario' => $recipient['neighborhood'],
            'municipio_destinatario' => $recipient['city'],
            'uf_destinatario' => $recipient['state'],
            'cep_destinatario' => $recipient['zip'],
            'valor_frete' => $dto->freight,
            'valor_desconto' => $dto->discount,
            'valor_outras_despesas' => $dto->otherExpenses,
            'valor_produtos' => array_sum(array_column($dto->items, 'subtotal')),
            'valor_total' => $dto->total,
        ]);
        if (($recipient['ie_indicator'] ?? '') === '1') {
            $payload['inscricao_estadual_destinatario'] = $recipient['ie'];
        }
        if (! empty($recipient['email'])) {
            $payload['email_destinatario'] = $recipient['email'];
        }
        if (! empty($recipient['complement'])) {
            $payload['complemento_destinatario'] = $recipient['complement'];
        }
        foreach ($payload['items'] as $i => &$item) {
            $source = $dto->items[$i];
            $item['unidade_comercial'] = $source['unit'] ?? 'UN';
            $item['unidade_tributavel'] = $source['unit'] ?? 'UN';
            $item['quantidade_tributavel'] = $source['quantity'];
            $item['valor_unitario_tributavel'] = $source['unit_price'];
            $item['icms_origem'] = $source['origin'];
            $item['pis_situacao_tributaria'] = $source['pis_situation'];
            $item['cofins_situacao_tributaria'] = $source['cofins_situation'];
            if (! empty($source['cest'])) {
                $item['cest'] = $source['cest'];
            }
            foreach (['valor_frete' => $dto->freight, 'valor_desconto' => $dto->discount, 'valor_outras_despesas' => $dto->otherExpenses] as $field => $amount) {
                $item[$field] = self::allocation($dto->items, $i, $amount);
            }
            // Extra tax fields are configured explicitly on the product, never inferred from its name.
            $item = array_replace($item, $source['taxes'] ?? []);
        }
        unset($item);

        return $payload;
    }

    private static function allocation(array $items, int $index, float $amount): float
    {
        $total = array_sum(array_column($items, 'subtotal'));
        $remaining = (int) round($amount * 100);
        foreach ($items as $i => $item) {
            $cents = $i === array_key_last($items) ? $remaining : (int) round($amount * 100 * $item['subtotal'] / $total);
            if ($i === $index) {
                return $cents / 100;
            }
            $remaining -= $cents;
        }

        return 0;
    }
}
