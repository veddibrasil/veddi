import qz from 'qz-tray';

// QZ Tray roda na maquina do caixa (nao no servidor) e fala direto com a
// impressora via socket raw na rede local. Sem isso, o Laravel remoto nao
// tem como alcancar o hardware fisico do caixa.
let qzConnection = null;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function connectQz() {
    if (qz.websocket.isActive()) {
        return Promise.resolve();
    }

    if (qzConnection) {
        return qzConnection;
    }

    qz.security.setCertificatePromise((resolve, reject) => {
        fetch('/admin/pdv/qz-certificate')
            .then((res) => (res.ok ? res.text() : Promise.reject(new Error('QZ Tray sem certificado configurado'))))
            .then(resolve)
            .catch(reject);
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
            .then(resolve)
            .catch(reject);
    });

    qzConnection = qz.websocket.connect().catch((err) => {
        qzConnection = null;
        throw err;
    });

    return qzConnection;
}

async function sendToPrinter(printer, base64Payload) {
    await connectQz();
    await qz.socket.open(printer.ip, printer.port);

    try {
        await qz.socket.sendData(printer.ip, printer.port, { data: base64Payload, type: 'BASE64' });
    } finally {
        await qz.socket.close(printer.ip, printer.port);
    }
}

async function fetchAndPrint(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!res.ok) {
        // 404 = filial nao tem impressora configurada pra essa estacao/nota —
        // config valida de quem nao quer auto-print, nao e erro.
        return;
    }

    const { printer, payload } = await res.json();
    await sendToPrinter(printer, payload);
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

export function autoPrintFiscalNote(orderId, onError) {
    fetchAndPrint(`/admin/pdv/print/fiscal-note/${orderId}`).catch((err) => {
        console.warn('[pdv-printer] falha ao imprimir nota fiscal', err);
        onError?.('fiscal-note', err);
    });
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
