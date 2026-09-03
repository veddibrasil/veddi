<?php

namespace App\Support\Printing;

class ThermalReceiptPaper
{
    // Altura generosa e fixa (bem maior que qualquer cupom real) evita que o
    // DomPDF pagine conteudo de tamanho variavel (itens, movimentacoes de
    // caixa, produtos mais vendidos) em paginas A6 extras — pagina extra que
    // o navegador corta ou forca reduzir a escala pra caber ao imprimir,
    // deixando o cupom ilegivel ou cortado pela metade. Sobra de papel em
    // branco no rolo e um efeito colateral aceitavel; cupom cortado nao.
    private const HEIGHT_MM = 900.0;

    private const MM_TO_PT = 72 / 25.4;

    public static function forWidthMm(int $widthMm): array
    {
        return [0, 0, $widthMm * self::MM_TO_PT, self::HEIGHT_MM * self::MM_TO_PT];
    }
}
