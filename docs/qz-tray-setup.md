# QZ Tray — eliminar popup de permissão a cada impressão

## Por que o popup aparece

O front (`resources/js/admin/pdv-printer.js`) já assina toda requisição pro
QZ Tray com o certificado/chave privada de `storage/app/private/qz/` (ou
`QZ_CERTIFICATE`/`QZ_PRIVATE_KEY`), via `QzTraySignatureController`. Isso
prova pro QZ Tray que a requisição não foi adulterada, mas **não** faz o QZ
Tray confiar automaticamente num certificado autoassinado. Sem confiança
explícita, o QZ Tray mostra o dialogo "Action Required" em toda nova conexão
(e, dependendo da versão/config, a cada job de impressão).

Existem duas formas de resolver, e elas não se excluem:

1. **Certificado de entidade final (CA:FALSE)** — o QZ Tray só habilita o
   checkbox "Remember this decision" no próprio diálogo quando o certificado
   usado pra assinar é `CA:FALSE` com `keyUsage=digitalSignature`. Um
   certificado gerado sem esses parâmetros (`openssl req -x509` sem
   `-addext`) sai como `CA:TRUE` por padrão no OpenSSL 3.x — o QZ Tray aceita
   a assinatura, mas deixa o checkbox desabilitado, forçando o operador a
   confirmar toda vez. Regenerar o par com o comando correto (ver
   `QzTraySignatureController`) resolve isso: o operador confirma **uma vez**
   por estação, marcando "Remember", e o QZ Tray não pergunta mais.
2. **`override.crt`** — instalar o certificado direto na pasta do QZ Tray
   faz ele confiar sem diálogo nenhum, nem a primeira confirmação. Mais
   forte, mas exige acesso ao sistema de arquivos da máquina do caixa (passo
   manual, não dá pra automatizar pelo Laravel/JS).

Se o certificado já for `CA:FALSE`, normalmente a opção 1 já basta e é mais
simples de operar (não precisa mexer em pasta de instalação). Use `override.crt`
como reforço se quiser eliminar até a primeira confirmação, ou se o QZ Tray da
estação não estiver persistindo o "Remember" por algum motivo.

## Passo a passo (uma vez por estação) — override.crt

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
