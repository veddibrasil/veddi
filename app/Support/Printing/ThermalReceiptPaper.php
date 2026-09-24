<?php

namespace App\Support\Printing;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Dompdf\Frame;

class ThermalReceiptPaper
{
    // Altura máxima da página. Serve de teto para a sondagem de medida e de fallback quando não
    // dá pra medir. Continua bem maior que qualquer cupom real: se o DomPDF paginasse conteúdo de
    // tamanho variável (itens, movimentações de caixa, produtos mais vendidos) em páginas extras,
    // o navegador cortaria ou reduziria a escala ao imprimir, deixando o cupom ilegível.
    private const MAX_HEIGHT_MM = 900.0;

    // Folga depois do último elemento (além do padding do body), pra não gerar 2ª página por
    // arredondamento de ponto flutuante e dar um respiro antes do corte.
    private const TRAILING_SLACK_PT = 6.0;

    private const MM_TO_PT = 72 / 25.4;

    /**
     * Página de largura fixa e altura máxima. Só use direto se não puder medir o conteúdo —
     * o normal é {@see self::pdf()}, que ajusta a altura ao cupom.
     */
    public static function forWidthMm(int $widthMm): array
    {
        return [0, 0, $widthMm * self::MM_TO_PT, self::MAX_HEIGHT_MM * self::MM_TO_PT];
    }

    /**
     * PDF de cupom térmico com a altura ajustada ao conteúdo. Com a página fixa em 900 mm o rolo
     * (ou o visualizador de PDF) mostrava/avançava um metro de papel em branco depois do fim do
     * cupom. Aqui o HTML é renderizado uma vez numa página alta só para medir onde o último
     * elemento termina, e o PDF final usa exatamente essa altura.
     *
     * @param  array<string, mixed>  $data
     */
    public static function pdf(string $view, array $data, int $widthMm): DomPdfWrapper
    {
        $html = view($view, $data)->render();

        $height = self::measureContentHeightPt($html, $widthMm);

        return Pdf::loadHTML($html)->setPaper(
            $height === null
                ? self::forWidthMm($widthMm)
                : [0, 0, $widthMm * self::MM_TO_PT, $height]
        );
    }

    /** Altura (pt) até o fim do conteúdo, ou null se não der pra medir com segurança. */
    private static function measureContentHeightPt(string $html, int $widthMm): ?float
    {
        $bottom = 0.0;

        $probe = Pdf::loadHTML($html)->setPaper(self::forWidthMm($widthMm));
        $dompdf = $probe->getDomPDF();

        $dompdf->setCallbacks([[
            'event' => 'end_frame',
            'f' => function (Frame $frame) use (&$bottom) {
                $box = $frame->get_border_box();
                $bottom = max($bottom, $box['y'] + $box['h']);
            },
        ]]);

        $probe->render();

        // Passou de uma página até na sondagem alta: conteúdo gigante, medir não vale — mantém o
        // comportamento antigo (página máxima) em vez de arriscar cortar o cupom.
        if ($dompdf->getCanvas()->get_page_count() > 1 || $bottom <= 0.0) {
            return null;
        }

        return ceil($bottom + self::TRAILING_SLACK_PT);
    }
}
