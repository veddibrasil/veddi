import { autoPrintOrderReceipt, autoPrintTabOrderTicket, autoPrintPendingTabItems, listenForFiscalNoteAuthorization } from './pdv-printer.js';

// O terminal usa wire:navigate (SPA, sem reload de pagina) — cada vez que o
// caixa sai e volta pra tela do PDV, o Alpine destroi e recria o <div
// x-data="pdvApp()">, rodando init() de novo. window.addEventListener cru
// (diferente de x-on) nao e limpo automaticamente nessa troca, entao sem
// esse guard cada visita empilha mais um listener em window: um so evento
// 'order-paid' (ou uma so tecla F10) passa a disparar N vezes — reimprime
// cupom/nota fiscal N vezes e pode chamar processOrder() N vezes.
// pdvActiveInstance sempre aponta pro componente Alpine montado no momento;
// os listeners globais sao registrados uma unica vez e delegam pra ele.
let pdvActiveInstance = null;
let pdvGlobalListenersBound = false;

function bindPdvGlobalListenersOnce() {
    if (pdvGlobalListenersBound) return;
    pdvGlobalListenersBound = true;

    window.addEventListener('pdv-barcode-processed', () => pdvActiveInstance?._focusBarcode());
    window.addEventListener('product-added-to-cart', (e) => pdvActiveInstance?.showToast(`${e.detail.name} adicionado ao carrinho`));
    window.addEventListener('order-paid', (e) => pdvActiveInstance?._handleOrderPaid(e.detail));
    window.addEventListener('tab-order-finalized', (e) => pdvActiveInstance?._handleTabOrderFinalized(e.detail));
    window.addEventListener('tab-items-pending', (e) => pdvActiveInstance?._handleTabItemsPending(e.detail));
    window.addEventListener('pdv-toast', (e) => pdvActiveInstance?.showToast(e.detail.message));

    window.addEventListener('keydown', (e) => {
        const instance = pdvActiveInstance;
        if (!instance) return;

        // Skip if user is actively typing in a text input/textarea
        const tag = e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (e.target.isContentEditable) return;

        const now = Date.now();
        const gap = now - instance._barcodeLastKeyTime;
        instance._barcodeLastKeyTime = now;

        if (e.key === 'Enter') {
            if (instance._barcodeBuffer.length >= 3) {
                // Dispatch to Livewire: set barcodeInput + call lookupByBarcode
                const wire = Livewire.find(document.querySelector('[wire\\:id]')?.getAttribute('wire:id'));
                if (wire) {
                    wire.set('barcodeInput', instance._barcodeBuffer).then(() => {
                        wire.call('lookupByBarcode');
                    });
                }
            }
            instance._barcodeBuffer = '';
            clearTimeout(instance._barcodeClearTimer);
            return;
        }

        if (e.key.length === 1) {
            // Fast sequential keys = scanner; slow keys = user typing something else
            if (gap < instance._barcodeThreshold || instance._barcodeBuffer.length === 0) {
                instance._barcodeBuffer += e.key;
            } else {
                instance._barcodeBuffer = e.key;
            }

            clearTimeout(instance._barcodeClearTimer);
            instance._barcodeClearTimer = setTimeout(() => {
                instance._barcodeBuffer = '';
            }, 1000);
        }
    });

    window.addEventListener('keydown', (e) => {
        const instance = pdvActiveInstance;
        if (!instance) return;

        const tag = e.target.tagName;
        const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable;
        const wire = instance._wire();

        if (!wire) return;
        const step = wire.get('step');

        // Esc rodava ANTES desse guard: apertar Esc com foco num campo de texto do
        // modal de pagamento (busca de cliente, desconto, valor recebido, observação
        // — pra limpar o campo, fechar um autocomplete do navegador, ou só por hábito)
        // fechava o modal inteiro na hora, descartando forma de pagamento/desconto já
        // preenchidos. Os outros atalhos (F2/F10//) já respeitavam esse guard — Esc
        // passa a respeitar também, só fecha o modal quando o foco não está num campo.
        if (typing) return;

        if (e.key === 'Escape') {
            instance.selectingProduct = null;
            instance.pendingSelections = {};
            if (step === 'payment') {
                e.preventDefault();
                wire.call('backToCatalog');
                instance._focusBarcode();
            }
            return;
        }

        if (e.key === '/') {
            e.preventDefault();
            instance._focusSearch();
            return;
        }

        if (e.key === 'F2') {
            e.preventDefault();
            wire.call('proceedToPayment');
            return;
        }

        if (e.key === 'F10') {
            e.preventDefault();
            // Botao "Confirmar" fica disabled (wire:loading.attr) enquanto ha uma
            // requisicao em curso — F10 respeita o mesmo estado pra nao disparar
            // processOrder() de novo por cima de um clique/F10 anterior ainda em voo.
            const confirmBtn = document.getElementById('pdv-confirm-order-btn');
            if (step === 'payment' && !confirmBtn?.disabled) {
                wire.call('processOrder');
            }
        }
    });
}

Alpine.data('pdvApp', () => ({
    selectingProduct: null,
    pendingSelections: {},
    sidebarHidden: false,
    mobileCartOpen: false,
    _prevPdvStep: null,
    toastMessage: '',
    _toastTimer: null,

    // Barcode scanner state
    _barcodeBuffer: '',
    _barcodeLastKeyTime: 0,
    _barcodeClearTimer: null,
    _barcodeThreshold: 50, // ms between keystrokes — USB scanners type at < 30ms

    init() {
        pdvActiveInstance = this;
        bindPdvGlobalListenersOnce();

        this.sidebarHidden = localStorage.getItem('pdv_sidebar_hidden') === '1';
        this._applySidebarState();
        this._focusBarcode();
    },

    // Cupom imprime na hora (config auto_print da filial); a nota fiscal so
    // fica pronta depois (emissao assincrona), entao so assina o canal do
    // pedido e imprime quando o evento de autorizacao chegar — e so faz isso
    // se o operador marcou o checkbox "Imprimir nota fiscal" no pagamento.
    _handleOrderPaid({ orderId, stations, printFiscalNote }) {
        autoPrintOrderReceipt(orderId, stations, () => this.showToast('Falha ao imprimir cupom automaticamente'));

        if (printFiscalNote) {
            listenForFiscalNoteAuthorization(orderId, () => this.showToast('Falha ao imprimir nota fiscal automaticamente'));
        }

        // Terminal nao entra mais em step 'success' (card flutuante mostra o resultado
        // por cima do catalogo) — no mobile, garante que a tela volta pro catalogo em
        // vez de ficar presa no carrinho em tela cheia.
        this.mobileCartOpen = false;
    },

    // Garçom manda a comanda pra produção (botão "Finalizar Pedido") — a via
    // completa (sem filtro por categoria) vai pra CADA impressora configurada da
    // filial (geral, cozinha, bar), servindo tanto de ticket de preparo quanto de
    // guia de entrega pro garçom.
    _handleTabOrderFinalized({ orderId, stations }) {
        autoPrintTabOrderTicket(orderId, stations, () => this.showToast('Falha ao imprimir pedido da mesa'));
    },

    // Garçom lançou item novo na comanda — sai sozinho na cozinha/bar (se a filial
    // tiver impressora com auto_print), sem precisar de "Finalizar Pedido".
    _handleTabItemsPending({ orderId, stations }) {
        autoPrintPendingTabItems(orderId, stations, () => this.showToast('Falha ao imprimir item novo da comanda'));
    },

    showToast(message) {
        this.toastMessage = message;
        clearTimeout(this._toastTimer);
        this._toastTimer = setTimeout(() => { this.toastMessage = ''; }, 2200);
    },

    // Alça de arrasto genérica pra redimensionar uma seção do carrinho (QA/teste de
    // responsividade) — troca a seção pra altura fixa em px e segue o ponteiro/dedo.
    // O footer é protegido de forma ESTRUTURAL (fica fora da região rolável no blade),
    // não por conta de altura — uma versão anterior calculava um teto a partir da altura
    // "atual" dos irmãos, mas um dos irmãos é flex-1 (preenche o espaço que sobra), então
    // esse teto sempre dava ≈ a própria altura de partida: dava pra encolher mas nunca
    // crescer de novo (ou vice-versa, dependendo da seção) — travava numa direção só.
    // Sem teto calculado, isso não existe mais: a região do meio tem overflow-y-auto e
    // absorve qualquer excesso rolando por dentro.
    //
    // Tenta Pointer Capture (mouse/touch soltos fora da janela nunca disparam mouseup, o
    // listener de mousemove fica grudado pra sempre e qualquer movimento depois mexe na
    // seção de novo — trava o carrinho). Mas setPointerCapture pode lançar exceção em
    // alguns navegadores/pointerId; sem o try/catch a função abortava ANTES de anexar
    // pointermove/pointerup — a seção ficava travada logo após o 1º solto, sem mais reagir
    // a nada. Guard de reentrância evita 2 arrastos simultâneos na mesma seção.
    startResize(el, event) {
        if (el._resizing) return;
        event.preventDefault();

        const handle = event.currentTarget;
        const pointerY = event.clientY;
        const startHeight = el.offsetHeight;
        el.style.flex = 'none';
        el._resizing = true;

        let captured = false;
        try {
            handle.setPointerCapture(event.pointerId);
            captured = true;
        } catch (e) {
            captured = false;
        }
        const target = captured ? handle : window;

        const move = (e) => {
            const desired = startHeight + (e.clientY - pointerY);
            el.style.height = Math.max(48, desired) + 'px';
        };
        const stop = () => {
            el._resizing = false;
            if (captured) {
                try {
                    handle.releasePointerCapture(event.pointerId);
                } catch (e) {
                    // já liberado (ex.: pointercancel) — ignora
                }
            }
            target.removeEventListener('pointermove', move);
            target.removeEventListener('pointerup', stop);
            target.removeEventListener('pointercancel', stop);
            target.removeEventListener('lostpointercapture', stop);
        };

        target.addEventListener('pointermove', move);
        target.addEventListener('pointerup', stop);
        target.addEventListener('pointercancel', stop);
        target.addEventListener('lostpointercapture', stop);
    },

    toggleSidebar() {
        this.sidebarHidden = !this.sidebarHidden;
        localStorage.setItem('pdv_sidebar_hidden', this.sidebarHidden ? '1' : '0');
        this._applySidebarState();
    },

    _applySidebarState() {
        document.body.classList.toggle('pdv-fullscreen', this.sidebarHidden);
    },

    // Auto-abre o carrinho em tela cheia (mobile) ao concluir pedido,
    // auto-fecha ao sair da tela de sucesso (ex: "Novo pedido").
    watchPdvStep(step) {
        if (step === 'success' && this._prevPdvStep !== 'success') {
            this.mobileCartOpen = true;
        } else if (this._prevPdvStep === 'success' && step !== 'success') {
            this.mobileCartOpen = false;
        }
        this._prevPdvStep = step;
    },

    _wire() {
        return Livewire.find(document.querySelector('[wire\\:id]')?.getAttribute('wire:id'));
    },

    _focusSearch() {
        requestAnimationFrame(() => {
            document.getElementById('pdv-product-search')?.focus();
        });
    },

    _focusBarcode() {
        requestAnimationFrame(() => {
            document.getElementById('pdv-barcode-input')?.focus();
        });
    },

    openOptionSelector(product) {
        this.selectingProduct = product;
        this.pendingSelections = {};
        product.groups.forEach(group => {
            this.pendingSelections[group.id] = {};
            group.options.forEach(opt => {
                this.pendingSelections[group.id][opt.id] = opt.prefilledQty || 0;
            });
        });
    },

    addOrOpenOptionSelector(product, wire) {
        if (product.allFixed) {
            const selections = {};
            product.groups.forEach(group => {
                selections[group.id] = { group_name: group.name, total_qty: group.total_qty, selections: {} };
                group.options.forEach(opt => {
                    if (opt.prefilledQty > 0) {
                        selections[group.id].selections[opt.id] = {
                            name: opt.name, qty: opt.prefilledQty, additional_price: opt.additional_price,
                        };
                    }
                });
            });
            wire.addProductWithOptions(product.id, selections);
        } else {
            this.openOptionSelector(product);
        }
    },

    getGroupTotal(groupId) {
        const sels = this.pendingSelections[groupId] || {};
        return Object.values(sels).reduce((sum, qty) => sum + (parseInt(qty) || 0), 0);
    },

    canConfirm() {
        if (!this.selectingProduct) return false;
        return this.selectingProduct.groups.every(group => {
            if (group.fixed) return true;
            const total = this.getGroupTotal(group.id);

            return total <= group.total_qty && total >= (group.min_qty || 0);
        });
    },

    getTotalWithOptions() {
        if (!this.selectingProduct) return 0;
        let base = this.selectingProduct.price || 0;
        let extra = 0;
        this.selectingProduct.groups.forEach(group => {
            group.options.forEach(opt => {
                const qty = group.fixed
                    ? (opt.prefilledQty || 0)
                    : (parseInt(this.pendingSelections[group.id]?.[opt.id]) || 0);
                extra += qty * (opt.additional_price || 0);
            });
        });
        return base + extra;
    },

    confirmOptions(wire) {
        if (!this.canConfirm()) return;
        const selections = {};
        this.selectingProduct.groups.forEach(group => {
            selections[group.id] = {
                group_name: group.name,
                total_qty: group.total_qty,
                selections: {},
            };
            group.options.forEach(opt => {
                const qty = group.fixed
                    ? (opt.prefilledQty || 0)
                    : (parseInt(this.pendingSelections[group.id]?.[opt.id]) || 0);
                if (qty > 0) {
                    selections[group.id].selections[opt.id] = {
                        name: opt.name,
                        qty: qty,
                        additional_price: opt.additional_price,
                    };
                }
            });
        });
        wire.addProductWithOptions(this.selectingProduct.id, selections);
        this.selectingProduct = null;
        this.pendingSelections = {};
    },
}));
