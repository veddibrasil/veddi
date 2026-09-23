<?php

namespace App\Concerns;

use App\Models\Company;

trait FindsCompanyByOption
{
    /** Empresa por ID ou slug (opção de comando). */
    protected function findCompany(string $value): ?Company
    {
        // ID só se for numérico: no MySQL, `id = '1-pizzaria'` casaria com o id 1.
        return ctype_digit($value)
            ? Company::find((int) $value)
            : Company::where('slug', $value)->first();
    }
}
