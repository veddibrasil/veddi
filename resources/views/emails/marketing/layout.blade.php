{{--
    Layout do e-mail marketing. $body é HTML já sanitizado pelo TemplateRenderer (nunca Blade do
    usuário); $unsubscribeUrl e $pixelTag chegam como marcadores e são trocados por destinatário.
--}}
<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $appName }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .mk-container { width: 100% !important; }
            .mk-container td { padding-left: 20px !important; padding-right: 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#3f3f46;-webkit-text-size-adjust:100%;">
    @if(filled($preheader))
        <span style="display:none !important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;max-height:0;max-width:0;">{{ $preheader }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</span>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f5;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" class="mk-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="height:6px;background:#7A00A3;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    {!! $body !!}
                    <tr>
                        <td style="padding:8px 32px 32px;"></td>
                    </tr>
                </table>

                <table role="presentation" class="mk-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
                    <tr>
                        <td style="padding:20px 32px;font-size:12px;line-height:1.6;color:#71717a;text-align:center;">
                            <p style="margin:0 0 8px;">
                                Você recebeu este e-mail porque usa o {{ $appName }} na sua empresa.
                                <a href="{{ $unsubscribeUrl }}" target="_blank" style="color:#71717a;text-decoration:underline;">Descadastrar ou gerenciar preferências</a>.
                            </p>
                            @if(filled($legal['company_name'] ?? null))
                                <p style="margin:0;">
                                    {{ $legal['company_name'] }}@if(filled($legal['document'] ?? null)) · CNPJ {{ $legal['document'] }}@endif
                                    @if(filled($legal['address'] ?? null))<br>{{ $legal['address'] }}@endif
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
                {!! $pixelTag !!}
            </td>
        </tr>
    </table>
</body>
</html>
