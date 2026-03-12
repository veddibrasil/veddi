<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo(a)!</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; color: #333; }
        .container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .header { background: #1a1a1a; padding: 32px 40px; text-align: center; }
        .header h1 { color: #fff; margin: 0; font-size: 22px; }
        .body { padding: 32px 40px; }
        .body h2 { font-size: 18px; margin-top: 0; color: #111; }
        .body p { font-size: 15px; line-height: 1.6; color: #444; }
        .credentials-box { background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 6px; padding: 20px 24px; margin: 24px 0; }
        .credentials-box p { margin: 6px 0; font-size: 14px; }
        .credentials-box .label { color: #888; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        .credentials-box .value { font-weight: bold; font-size: 15px; color: #111; }
        .btn { display: inline-block; margin-top: 8px; background: #f59e0b; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: bold; font-size: 15px; }
        .note { font-size: 13px; color: #888; margin-top: 20px; line-height: 1.5; }
        .footer { padding: 24px 40px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #f0f0f0; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>{{ $company->name }}</h1>
    </div>

    <div class="body">
        <h2>Olá, {{ $user->name }}! 👋</h2>

        <p>
            Você foi cadastrado(a) no painel administrativo da <strong>{{ $company->name }}</strong>.
            Seus dados de acesso estão abaixo:
        </p>

        <div class="credentials-box">
            <p class="label">E-mail</p>
            <p class="value">{{ $user->email }}</p>

            <p class="label" style="margin-top:12px;">Senha temporária</p>
            <p class="value">{{ $temporaryPassword }}</p>
        </div>

        <p>Acesse o painel pelo link abaixo e, se quiser, altere sua senha nas configurações do perfil:</p>

        <a href="{{ url('/admin/dashboard') }}" class="btn">Acessar o painel</a>

        <p class="note">
            Se você não reconhece esse cadastro, ignore este e-mail.<br>
            Por segurança, recomendamos alterar sua senha após o primeiro acesso.
        </p>
    </div>

    <div class="footer">
        © {{ date('Y') }} {{ $company->name }}. Todos os direitos reservados.
    </div>
</div>
</body>
</html>
