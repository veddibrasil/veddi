import { autoPrintTabOrderTicket } from './pdv-printer.js';

// Fila de cozinha/bar ("Minha fila", fora do PDV) — versão mínima do binding de
// impressão que o Terminal/Mesas usa (pdv-app.js). Sem carrinho, código de barras
// nem atalhos de teclado do PDV: só precisa reagir ao mesmo evento
// 'tab-order-finalized' que o "Finalizar Pedido" do garçom dispara, e mandar pro
// QZ Tray que estiver pareado nesta tela.
let bound = false;

function bindKitchenPrintListenerOnce() {
    if (bound) return;
    bound = true;

    window.addEventListener('tab-order-finalized', (e) => {
        console.log('[auto-print] (fila cozinha/bar) recebeu tab-order-finalized', e.detail);

        autoPrintTabOrderTicket(e.detail.orderId, e.detail.stations, (station, err) => {
            console.error('[auto-print] (fila cozinha/bar) falha ao imprimir', station, err);
        });
    });
}

const registerStationPrintListener = () => {
    Alpine.data('stationPrintListener', () => ({
        init() {
            bindKitchenPrintListenerOnce();
        },
    }));
};

document.addEventListener('alpine:init', registerStationPrintListener, { once: true });
