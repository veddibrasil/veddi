<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seu código de acesso</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; color: #333;">
    <div style="max-width: 600px; margin: 40px auto; background: #fff; border-radius: 10px; overflow: hidden;">
        <div style="background: #B91C1C; padding: 32px; text-align: center;">
            <h1 style="color: #fff; margin: 0; font-size: 22px;">{{ $companyName }}</h1>
        </div>
        <div style="padding: 32px; text-align: center;">
            <h2 style="font-size: 20px;">Seu código de acesso</h2>
            <p>Use este código para acessar seus dados salvos no chat da loja.</p>
            <p style="font-size: 36px; font-weight: bold; letter-spacing: 8px; color: #B91C1C;">{{ $code }}</p>
            <p>Ele vale por 5 minutos. Não compartilhe este código.</p>
            <p style="color: #666;">Se não foi você, ignore este e-mail.</p>
        </div>
    </div>
</body>
</html>
