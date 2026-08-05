# QZ Tray — eliminar popup de permissão a cada impressão

## Por que o popup aparece

O front (`resources/js/admin/pdv-printer.js`) já assina toda requisição pro
QZ Tray com o certificado/chave privada de `storage/app/private/qz/` (ou
`QZ_CERTIFICATE`/`QZ_PRIVATE_KEY`), via `QzTraySignatureController`. Isso
prova pro QZ Tray que a requisição não foi adulterada, mas **não** faz o QZ
Tray confiar automaticamente num certificado autoassinado. Sem confiança
explícita, o QZ Tray mostra o dialogo "Action Required" em toda nova conexão
(e, dependendo da versão/config, a cada job de impressão).

A correção definitiva é instalar o certificado como `override.crt` na
instalação do QZ Tray de cada estação de caixa — isso faz o QZ Tray confiar
nesse certificado permanentemente, sem diálogo nenhum, sem depender do
operador clicar "Remember this decision".

Esse passo é local à máquina do caixa (QZ Tray desktop), não tem como ser
feito pelo código do Laravel/JS.

## Passo a passo (uma vez por estação)

1. Baixe o conteúdo do certificado da própria empresa: acesse
   `https://SEU-DOMINIO/admin/pdv/qz-certificate` (autenticado, mesma
   sessão do admin) e salve o texto retornado como `override.crt`.
2. Feche o QZ Tray (ícone na bandeja > Exit).
3. Copie `override.crt` para a pasta de instalação do QZ Tray:
   - **Windows**: `C:\Program Files\QZ Tray\` (mesma pasta do `qz-tray.exe`).
   - **macOS**: dentro do bundle, `/Applications/QZ Tray.app/Contents/Resources/`.
   - Confirme o caminho exato em QZ Tray > Advanced > "About" (mostra o
     diretório de instalação) se a versão instalada usar outro local.
4. Abra o QZ Tray de novo. A partir daí, qualquer requisição assinada com a
   chave privada correspondente é aceita sem diálogo.
5. Teste: finalize um pedido no PDV e confirme que nem o cupom nem a nota
   fiscal disparam o popup.

## Se a empresa trocar de certificado

Se `storage/app/private/qz/digital-certificate.txt` (ou
`QZ_CERTIFICATE`) for regenerado, o `override.crt` de cada estação fica
desatualizado e o popup volta a aparecer — repita os passos acima com o
certificado novo em cada máquina.

## Ambientes multi-empresa (múltiplos certificados)

Se cada empresa/filial tiver seu próprio par de chaves, cada estação de
caixa só reconhece o certificado que foi instalado como `override.crt` nela.
Não dá pra confiar em vários certificados diferentes ao mesmo tempo com esse
mecanismo — nesse caso, ou todas as empresas compartilham o mesmo par de
chaves da plataforma (`QZ_CERTIFICATE`/`QZ_PRIVATE_KEY` globais), ou cada
estação física atende só uma empresa.
