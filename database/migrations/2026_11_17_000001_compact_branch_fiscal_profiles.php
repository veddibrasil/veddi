<?php

use App\Support\Fiscal\ProductFiscalProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perfil fiscal da filial passa a guardar só o que difere do cadastro geral (o resto é herdado,
 * ver ProductFiscalProfile). Perfis antigos eram cópia inteira do cadastro geral, feita ao ligar
 * a chave na tela do produto: com a cópia, mudar o cadastro geral não chegava à filial.
 *
 * Aqui só saem os valores iguais ao cadastro geral de hoje, então o que cada nota usa continua
 * igual. Perfil de outro regime (CST x CSOSN) fica inteiro. Perfil sem nenhuma diferença vira
 * null (a filial usa o cadastro geral). Sem down: o dado efetivo é o mesmo antes e depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('branch_product')
            ->join('products', 'products.id', '=', 'branch_product.product_id')
            ->whereNotNull('branch_product.fiscal_data')
            ->get(['branch_product.branch_id', 'branch_product.product_id', 'branch_product.fiscal_data as branch_data', 'products.fiscal_data as general_data']);

        foreach ($rows as $row) {
            $branch = json_decode((string) $row->branch_data, true);
            if (! is_array($branch)) {
                continue;
            }
            $general = json_decode((string) $row->general_data, true);
            $compact = ProductFiscalProfile::compact(is_array($general) ? $general : [], $branch);
            if ($compact !== null && $compact == $branch) {
                continue;
            }

            DB::table('branch_product')
                ->where('branch_id', $row->branch_id)
                ->where('product_id', $row->product_id)
                ->update(['fiscal_data' => $compact === null ? null : json_encode($compact, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        //
    }
};
