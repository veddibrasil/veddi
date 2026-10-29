<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaignRecipient;
use App\Services\Marketing\TemplateRenderer;
use App\Services\Marketing\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pixel de abertura e redirect de clique do e-mail marketing. O redirect só vai para um link
 * que está no HTML da campanha deste destinatário (nada de redirect aberto) e exige assinatura.
 */
class MarketingTrackingController extends Controller
{
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(Request $request, int $recipient, TrackingService $tracking): Response
    {
        $target = $request->hasValidSignature() ? EmailCampaignRecipient::find($recipient) : null;

        if ($target) {
            rescue(fn () => $tracking->recordOpen($target, $request), report: false);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function click(Request $request, int $recipient, TrackingService $tracking, TemplateRenderer $renderer): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 404);

        $target = EmailCampaignRecipient::with('campaign')->find($recipient);
        $url = TrackingService::decodeUrl($request->query('u'));

        abort_unless($target && $target->campaign && $url, 404);

        // Campanha: o HTML é congelado no disparo, então o destino precisa estar nele. Passo de
        // automação: o conteúdo muda quando a automação é salva de novo e e-mails antigos ficariam
        // com link quebrado; ali vale a assinatura (só o servidor gera a URL) + http(s).
        $allowed = $target->campaign->isAutomation()
            ? in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            : in_array($url, $renderer->trackableLinks($target->campaign, $target), true);

        abort_unless($allowed, 404);

        rescue(fn () => $tracking->recordClick($target, $url, $request), report: false);

        return redirect()->away($url);
    }
}
