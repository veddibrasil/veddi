import qz from 'qz-tray';

// QZ Tray roda na maquina do caixa (nao no servidor) e fala direto com a
// impressora via socket raw na rede local. Sem isso, o Laravel remoto nao
// tem como alcancar o hardware fisico do caixa.
let qzConnection = null;
let closedCallbackRegistered = false;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

// O terminal fica aberto por horas (turno de caixa) — se a sessao expirar nesse
// meio tempo, o fetch de /qz-certificate segue o redirect pro /login e devolve
// a PAGINA DE LOGIN em HTML com status 200 ("res.ok" fica true, entao o bug nao
// aparece como erro de rede). Sem essa checagem, esse HTML era repassado pro QZ
// Tray como se fosse o certificado, e o QZ Tray acusava "certificado invalido"
// — mascarando o problema real (sessao expirada) atras de um erro de PKI.
function isPemCertificate(text) {
    return typeof text === 'string' && text.includes('-----BEGIN CERTIFICATE-----');
}

// Mesmo risco do certificado: base64 nunca contem '<', entao uma pagina de
// login/erro em HTML fica facil de distinguir de uma assinatura de verdade.
function isBase64Signature(text) {
    return typeof text === 'string' && text.length > 0 && ! text.includes('<');
}

function connectQz() {
    console.log('[auto-print] connectQz: iniciando (qzConnection em cache?', !!qzConnection, '| websocket ativo?', qz.websocket.isActive(), ')');

    // Sem isso, qzConnection guarda a promise resolvida da PRIMEIRA conexao pra
    // sempre — se o QZ Tray fechar ou a rede cair depois, o cache continua
    // "verdadeiro" e o proximo print pula reconexao, batendo direto num socket
    // morto (erro "connection ... has not been established yet" dentro do
    // proprio qz.print). O closedCallback zera o cache quando o socket cai de
    // verdade, forcando reconexao no proximo print.
    if (!closedCallbackRegistered) {
        qz.websocket.setClosedCallbacks(() => {
            console.warn('[auto-print] QZ Tray: socket fechou, limpando cache de conexao');
            qzConnection = null;
        });
        closedCallbackRegistered = true;
    }

    // Checa qzConnection (promise em andamento) antes de isActive(): o socket
    // fica em readyState CONNECTING logo apos criado, e isActive() considera
    // isso "ativo" tambem — se checarmos isActive() primeiro, uma segunda
    // impressao disparada em paralelo (varias estacoes) resolve na hora sem
    // esperar o handshake terminar, e connection.sendData ainda nao existe
    // nesse ponto (so e definido apos o "open" completar).
    if (qzConnection) {
        console.log('[auto-print] connectQz: reusando conexao em andamento/estabelecida');

        return qzConnection;
    }

    if (qz.websocket.isActive()) {
        console.log('[auto-print] connectQz: websocket ja ativo, sem reconectar');

        return Promise.resolve();
    }

    console.log('[auto-print] connectQz: sem conexao ativa, abrindo websocket com QZ Tray...');

    qz.security.setCertificatePromise((resolve, reject) => {
        fetch('/admin/pdv/qz-certificate')
            .then((res) => (res.ok ? res.text() : Promise.reject(new Error('QZ Tray sem certificado configurado'))))
            .then((text) => {
                if (!isPemCertificate(text)) {
                    throw new Error('Sessao expirada — atualize a pagina para reconectar a impressora.');
                }

                return text;
            })
            .then(resolve)
            .catch((err) => {
                console.error('[auto-print] connectQz: falha ao buscar certificado', err);
                reject(err);
            });
    });

    qz.security.setSignatureAlgorithm('SHA512');
    qz.security.setSignaturePromise((toSign) => (resolve, reject) => {
        fetch('/admin/pdv/qz-sign', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: new URLSearchParams({ request: toSign }),
        })
            .then((res) => (res.ok ? res.text() : Promise.reject(new Error('falha ao assinar requisicao QZ Tray'))))
            .then((text) => {
                if (!isBase64Signature(text)) {
                    throw new Error('Sessao expirada — atualize a pagina para reconectar a impressora.');
                }

                return text;
            })
            .then(resolve)
            .catch((err) => {
                console.error('[auto-print] connectQz: falha ao assinar requisicao', err);
                reject(err);
            });
    });

    qzConnection = qz.websocket.connect()
        .then((res) => {
            console.log('[auto-print] connectQz: websocket conectado com sucesso');

            return res;
        })
        .catch((err) => {
            console.error('[auto-print] connectQz: falha ao conectar no websocket do QZ Tray (QZ Tray fechado/nao instalado?)', err);
            qzConnection = null;
            throw err;
        });

    return qzConnection;
}

async function sendToPrinter(printer, base64Payload) {
    console.log('[auto-print] sendToPrinter: iniciando', { connection_type: printer.connection_type, ip: printer.ip, port: printer.port, name: printer.name });

    await connectQz();

    if (printer.connection_type === 'usb') {
        // Impressora USB instalada como impressora do SO (driver do fabricante) —
        // QZ Tray manda os mesmos bytes ESC/POS pelo nome da impressora, sem IP/porta.
        // forceRaw evita que o driver do Windows tente interpretar os bytes ESC/POS
        // como documento normal — sem isso o job "conclui" no QZ Tray mas nao sai
        // nada fisicamente na impressora.
        const config = qz.configs.create(printer.name, { forceRaw: true });

        console.log('[auto-print] sendToPrinter: enviando via USB pra', printer.name);
        await qz.print(config, [{ type: 'raw', format: 'command', flavor: 'base64', data: base64Payload }]);
        console.log('[auto-print] sendToPrinter: qz.print concluido (USB)', printer.name);

        return;
    }

    console.log('[auto-print] sendToPrinter: abrindo socket de rede', printer.ip, printer.port);
    await qz.socket.open(printer.ip, printer.port);

    try {
        console.log('[auto-print] sendToPrinter: enviando dados pro socket', printer.ip, printer.port);
        await qz.socket.sendData(printer.ip, printer.port, { data: base64Payload, type: 'BASE64' });
        console.log('[auto-print] sendToPrinter: dados enviados com sucesso', printer.ip, printer.port);
    } finally {
        await qz.socket.close(printer.ip, printer.port);
    }
}

async function fetchAndPrint(url) {
    console.log('[auto-print] fetchAndPrint: buscando payload em', url);

    const res = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!res.ok) {
        // 404 = filial nao tem impressora configurada pra essa estacao/nota —
        // config valida de quem nao quer auto-print, nao e erro.
        console.warn('[auto-print] fetchAndPrint: resposta não-ok, abortando sem imprimir', url, res.status);

        return;
    }

    const { printer, payload } = await res.json();
    console.log('[auto-print] fetchAndPrint: payload recebido, mandando pra impressora', url, { printer });
    await sendToPrinter(printer, payload);
    console.log('[auto-print] fetchAndPrint: impressão concluída sem erro', url);
}

// Falha de impressao (QZ Tray fechado, impressora offline, etc.) nunca deve
// travar o caixa — so avisa via onError pra UI mostrar um toast, e os botoes
// manuais de impressao continuam disponiveis como fallback.
export function autoPrintOrderReceipt(orderId, stations, onError) {
    (stations || []).forEach((station) => {
        fetchAndPrint(`/admin/pdv/print/receipt/${orderId}/${station}`).catch((err) => {
            console.warn('[pdv-printer] falha ao imprimir cupom', station, err);
            onError?.(station, err);
        });
    });
}

// "Finalizar Pedido" da mesa: manda a via COMPLETA (sem filtro por categoria) pra
// cada impressora configurada da filial — cozinha/bar recebem o pedido inteiro,
// não só os itens deles, porque a mesma via serve de guia de entrega pro garçom.
export function autoPrintTabOrderTicket(orderId, stations, onError) {
    console.log('[auto-print] autoPrintTabOrderTicket: chamado', { orderId, stations });

    (stations || []).forEach((station) => {
        fetchAndPrint(`/admin/pdv/print/receipt/${orderId}/${station}?full=1`).catch((err) => {
            console.error('[auto-print] falha ao imprimir via da mesa', station, err);
            onError?.(station, err);
        });
    });
}

export function autoPrintFiscalNote(orderId, onError) {
    fetchAndPrint(`/admin/pdv/print/fiscal-note/${orderId}`).catch((err) => {
        console.warn('[pdv-printer] falha ao imprimir nota fiscal', err);
        onError?.('fiscal-note', err);
    });
}

// Botao manual de "imprimir cupom" (fila de cozinha/bar/entrega e fechamento
// do dia): tenta imprimir sozinho na impressora pareada da estacao; se nao
// tiver impressora configurada (404) ou o QZ Tray falhar por qualquer motivo,
// cai pro comportamento antigo (abre a tela/PDF de impressao manual) em vez
// de deixar o operador sem cupom nenhum.
async function printOrFallback(url, fallbackUrl) {
    let res;

    try {
        res = await fetch(url, { headers: { Accept: 'application/json' } });
    } catch (err) {
        console.warn('[print] falha de rede ao buscar payload, abrindo impressao manual', url, err);
        window.open(fallbackUrl, '_blank');

        return;
    }

    if (!res.ok) {
        console.warn('[print] sem impressora configurada, abrindo impressao manual', url, res.status);
        window.open(fallbackUrl, '_blank');

        return;
    }

    try {
        const { printer, payload } = await res.json();
        await sendToPrinter(printer, payload);
    } catch (err) {
        console.warn('[print] falha ao imprimir (QZ Tray fechado/offline?), abrindo impressao manual', url, err);
        window.open(fallbackUrl, '_blank');
    }
}

export function printStationReceipt(orderId, station, fallbackUrl) {
    return printOrFallback(`/admin/pdv/print/receipt/${orderId}/${station}`, fallbackUrl);
}

export function printClosingReport(query, fallbackUrl) {
    return printOrFallback(`/admin/pdv/print/closing${query}`, fallbackUrl);
}

// Assina o canal do pedido so ate a nota autorizar (ou por no maximo 15min,
// pra nao acumular inscricoes indefinidamente num turno longo de caixa).
export function listenForFiscalNoteAuthorization(orderId, onError) {
    if (!window.Echo) {
        return;
    }

    const channelName = 'order.' + orderId;
    const channel = window.Echo.channel(channelName);

    const stopListening = () => window.Echo.leaveChannel(channelName);
    const timeout = setTimeout(stopListening, 15 * 60 * 1000);

    channel.listen('.FiscalNoteAuthorized', (event) => {
        clearTimeout(timeout);
        autoPrintFiscalNote(event.order_id, onError);
        stopListening();
    });
}
