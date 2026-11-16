<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de tributação da empresa (valores aprovados pela contabilidade) para aplicar em lote
 * nos produtos. Aplicar copia os valores; não há vínculo vivo com o produto.
 */
class FiscalTemplate extends Model
{
    use BelongsToCompany;

    /** Campos copiados para o perfil fiscal do produto, com o rótulo do cadastro de produtos. */
    public const FIELDS = [
        'ncm' => 'NCM',
        'cfop' => 'CFOP interno',
        'interstate_cfop' => 'CFOP interestadual',
        'icms_situation' => 'CST / CSOSN ICMS',
        'origin' => 'Origem',
        'unit' => 'Unidade comercial',
        'cest' => 'CEST',
        'pis_situation' => 'CST PIS',
        'cofins_situation' => 'CST COFINS',
    ];

    protected $fillable = [
        'company_id', 'name', 'crt', 'state', 'operation', 'valid_from', 'valid_until',
        'data', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'crt' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'data' => 'array',
    ];

    /** Fora da vigência o modelo não pode ser aplicado (os valores podem ter mudado na legislação). */
    public function isValidOn(\DateTimeInterface $date): bool
    {
        $day = \Illuminate\Support\Carbon::instance($date)->startOfDay();

        return (! $this->valid_from || $this->valid_from->lte($day))
            && (! $this->valid_until || $this->valid_until->gte($day));
    }

    /** Só os campos preenchidos: campo vazio no modelo nunca apaga dado do produto. */
    public function filledValues(): array
    {
        $values = array_filter(
            array_intersect_key($this->data ?? [], self::FIELDS),
            fn ($value) => $value !== null && $value !== '',
        );
        if (! empty($this->data['taxes']) && is_array($this->data['taxes'])) {
            $values['taxes'] = $this->data['taxes'];
        }

        return $values;
    }
}
