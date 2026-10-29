<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Relatório CSV dos destinatários de uma campanha (rota do grupo super.admin). */
class MarketingCampaignExportController extends Controller
{
    public function __invoke(Request $request, EmailCampaign $campaign): StreamedResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $filename = 'campanha-'.$campaign->id.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($campaign) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['email', 'nome', 'empresa', 'status', 'variante', 'enviado_em', 'entregue_em', 'aberto_em', 'clicado_em', 'descadastro_em', 'erro'], ';');

            EmailCampaignRecipient::where('campaign_id', $campaign->id)
                ->with('company:id,name')
                ->orderBy('id')
                ->chunk(1000, function ($recipients) use ($out) {
                    foreach ($recipients as $recipient) {
                        fputcsv($out, array_map(self::cell(...), [
                            $recipient->email,
                            $recipient->name,
                            $recipient->company?->name,
                            $recipient->status->label(),
                            $recipient->variant ? strtoupper($recipient->variant) : '',
                            $recipient->sent_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                            $recipient->delivered_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                            $recipient->first_opened_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                            $recipient->first_clicked_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                            $recipient->unsubscribed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                            $recipient->error,
                        ]), ';');
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Nome e empresa vêm do cadastro do restaurante: célula começando com = + - @ vira fórmula no Excel. */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
