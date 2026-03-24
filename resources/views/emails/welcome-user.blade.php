<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo(a)!</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; color: #333;">
@php
    $primary      = $company->primary_color      ?? '#B91C1C';
    $primaryDark  = $company->primary_color_dark  ?? '#991B1B';
    $primaryLight = $company->primary_color_light ?? '#DC2626';
    $secondary    = $company->secondary_color     ?? '#D97706';
    $logoUrl      = $company->logo_url;
@endphp

<div style="max-width: 600px; margin: 40px auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.10);">

    {{-- Header --}}
    <div style="background: linear-gradient(135deg, {{ $primaryDark }} 0%, {{ $primary }} 60%, {{ $primaryLight }} 100%); padding: 32px 40px; text-align: center;">
        @if($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $company->name }}" style="height: 56px; width: 56px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(255,255,255,0.4); margin-bottom: 12px; display: block; margin-left: auto; margin-right: auto;">
        @endif
        <h1 style="color: #fff; margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.3px;">{{ $company->name }}</h1>
        <p style="color: rgba(255,255,255,0.75); margin: 4px 0 0; font-size: 13px;">Painel Administrativo</p>
    </div>

    {{-- Body --}}
    <div style="padding: 32px 40px;">
        <h2 style="font-size: 20px; margin-top: 0; color: #111;">Olá, {{ $user->name }}! 👋</h2>

        <p style="font-size: 15px; line-height: 1.6; color: #555;">
            Você foi cadastrado(a) no painel administrativo da <strong style="color: #111;">{{ $company->name }}</strong>.
            Seus dados de acesso estão abaixo:
        </p>

        {{-- Credentials box --}}
        <div style="background: #f9f9f9; border: 1px solid #e5e5e5; border-left: 4px solid {{ $primary }}; border-radius: 6px; padding: 20px 24px; margin: 24px 0;">
            <p style="margin: 0 0 4px; font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .5px;">E-mail</p>
            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #111;">{{ $user->email }}</p>

            <p style="margin: 0 0 4px; font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .5px;">Senha temporária</p>
            <p style="margin: 0; font-size: 18px; font-weight: 800; color: {{ $primary }}; letter-spacing: 1px;">{{ $temporaryPassword }}</p>
        </div>

        <p style="font-size: 15px; line-height: 1.6; color: #555;">
            Acesse o painel pelo link abaixo e, se quiser, altere sua senha nas configurações do perfil:
        </p>

        <div style="text-align: center; margin: 28px 0;">
            <a href="{{ url('/admin/dashboard') }}"
               style="display: inline-block; background: {{ $primary }}; color: #fff; text-decoration: none; padding: 14px 36px; border-radius: 8px; font-weight: 700; font-size: 15px; letter-spacing: 0.2px;">
                Acessar o painel →
            </a>
        </div>

        <p style="font-size: 13px; color: #999; line-height: 1.6; margin-top: 20px;">
            Se você não reconhece esse cadastro, ignore este e-mail.<br>
            Por segurança, recomendamos alterar sua senha após o primeiro acesso.
        </p>
    </div>

    {{-- Footer --}}
    <div style="padding: 20px 40px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #f0f0f0; background: #fafafa;">
        <p style="margin: 0;">&copy; {{ date('Y') }} {{ $company->name }}. Todos os direitos reservados.</p>
    </div>

</div>
</body>
</html>
