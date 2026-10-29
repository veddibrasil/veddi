<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaignRecipient;
use App\Services\Marketing\SuppressionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Descadastro do e-mail marketing, sem login: o link é assinado por destinatário.
 * GET mostra a página de preferências; POST sem "action" é o One-Click (List-Unsubscribe-Post),
 * feito pelo próprio cliente de e-mail.
 */
class MarketingUnsubscribeController extends Controller
{
    public function show(Request $request, int $recipient, SuppressionService $suppressions): View|Response
    {
        $target = $this->resolve($request, $recipient);

        if (! $target) {
            return response()->view('marketing.unsubscribe', ['invalid' => true], 403);
        }

        return view('marketing.unsubscribe', [
            'invalid' => false,
            'email' => SuppressionService::mask($target->email),
            'subscribed' => ! $suppressions->isSuppressed($target->email),
            'actionUrl' => $request->fullUrl(),
            'done' => session('marketing_preferences'),
        ]);
    }

    public function store(Request $request, int $recipient, SuppressionService $suppressions)
    {
        $target = $this->resolve($request, $recipient);

        if (! $target) {
            abort(403);
        }

        if ($request->input('action') === 'resubscribe') {
            $done = $suppressions->resubscribe($target->email) ? 'resubscribed' : 'blocked';

            return redirect()->to($request->fullUrl())->with('marketing_preferences', $done);
        }

        $suppressions->unsubscribe($target);

        // One-Click (RFC 8058): o cliente de e-mail só precisa de 2xx, sem redirecionamento.
        if (! $request->has('action')) {
            return response('Descadastro registrado.', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return redirect()->to($request->fullUrl())->with('marketing_preferences', 'unsubscribed');
    }

    private function resolve(Request $request, int $recipient): ?EmailCampaignRecipient
    {
        // Assinatura cobre só a query string (o POST One-Click manda corpo próprio).
        if (! $request->hasValidSignature()) {
            return null;
        }

        return EmailCampaignRecipient::find($recipient);
    }
}
