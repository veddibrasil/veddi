<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atenção com o WhatsApp</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="font-family: 'Nunito Sans', Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; color: #333;">

<div style="max-width: 600px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 16px rgba(0,0,0,.10);">

    {{-- Header --}}
    <div style="background: linear-gradient(135deg, #5c0079 0%, #7A00A3 60%, #9B10C8 100%); padding: 36px 40px; text-align: center;">
        <div style="display: inline-block; background: #fff; border-radius: 50%; padding: 10px; margin-bottom: 16px; line-height: 0; box-shadow: 0 2px 8px rgba(0,0,0,.20);">
            <img src="{{ url('/logo_roxa.png') }}" alt="Veddi" style="height: 48px; width: 48px; display: block; border-radius: 50%; object-fit: contain;">
        </div>
        <h1 style="color: #fff; margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.3px;">Veddi</h1>
        <p style="color: rgba(255,255,255,0.70); margin: 6px 0 0; font-size: 13px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase;">Aviso sobre o WhatsApp</p>
    </div>

    {{-- Body --}}
    <div style="padding: 36px 40px;">
        <h2 style="font-size: 22px; margin-top: 0; margin-bottom: 8px; color: #111; font-weight: 800;">
            Olá, {{ $user->name }}!
        </h2>

        <p style="font-size: 15px; line-height: 1.7; color: #555; margin-bottom: 24px;">
            Identificamos {{ count($alerts) > 1 ? 'pontos de atenção' : 'um ponto de atenção' }} no WhatsApp conectado à loja
            <strong>{{ $company->name }}</strong>:
        </p>

        @foreach($alerts as $alert)
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 18px 22px; margin-bottom: 16px;">
                <p style="margin: 0 0 6px; font-size: 16px; font-weight: 800; color: #1a1a1a;">{{ $alert['title'] }}</p>
                <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #555;">{{ $alert['message'] }}</p>
            </div>
        @endforeach

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ url('/admin/settings/whatsapp') }}"
               style="display: inline-block; background: #7A00A3; color: #fff; text-decoration: none; padding: 15px 40px; border-radius: 8px; font-weight: 800; font-size: 15px; letter-spacing: 0.2px; box-shadow: 0 4px 12px rgba(122,0,163,.30);">
                Abrir configurações do WhatsApp →
            </a>
        </div>

        <p style="font-size: 13px; color: #aaa; line-height: 1.6; margin: 0;">
            Precisa de ajuda? Fale com a gente em
            <a href="mailto:contato@veddi.com.br" style="color: #7A00A3; text-decoration: none; font-weight: 600;">contato@veddi.com.br</a>.
        </p>
    </div>

    {{-- Footer --}}
    <div style="padding: 20px 40px; text-align: center; font-size: 12px; color: #aaa; border-top: 1px solid #f0f0f0; background: #fafafa;">
        <p style="margin: 0 0 4px;">&copy; {{ date('Y') }} Veddi. Todos os direitos reservados.</p>
        <p style="margin: 0;">
            <a href="mailto:contato@veddi.com.br" style="color: #9333ea; text-decoration: none;">contato@veddi.com.br</a>
        </p>
    </div>

</div>
</body>
</html>
