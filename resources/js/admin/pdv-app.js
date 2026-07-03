Alpine.data('pdvApp', () => ({
    selectingProduct: null,
    pendingSelections: {},
    sidebarHidden: false,
    mobileCartOpen: false,
    _prevPdvStep: null,

    // Barcode scanner state
    _barcodeBuffer: '',
    _barcodeLastKeyTime: 0,
    _barcodeClearTimer: null,
    _barcodeThreshold: 50, // ms between keystrokes — USB scanners type at < 30ms

    init() {
        this.sidebarHidden = localStorage.getItem('pdv_sidebar_hidden') === '1';
        this._applySidebarState();
        this._initBarcodeScanner();
        this._initShortcuts();
        this._focusBarcode();
        window.addEventListener('pdv-barcode-processed', () => this._focusBarcode());
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

    _initBarcodeScanner() {
        window.addEventListener('keydown', (e) => {
            // Skip if user is actively typing in a text input/textarea
            const tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (e.target.isContentEditable) return;

            const now = Date.now();
            const gap = now - this._barcodeLastKeyTime;
            this._barcodeLastKeyTime = now;

            if (e.key === 'Enter') {
                if (this._barcodeBuffer.length >= 3) {
                    // Dispatch to Livewire: set barcodeInput + call lookupByBarcode
                    const wire = Livewire.find(document.querySelector('[wire\\:id]')?.getAttribute('wire:id'));
                    if (wire) {
                        wire.set('barcodeInput', this._barcodeBuffer).then(() => {
                            wire.call('lookupByBarcode');
                        });
                    }
                }
                this._barcodeBuffer = '';
                clearTimeout(this._barcodeClearTimer);
                return;
            }

            if (e.key.length === 1) {
                // Fast sequential keys = scanner; slow keys = user typing something else
                if (gap < this._barcodeThreshold || this._barcodeBuffer.length === 0) {
                    this._barcodeBuffer += e.key;
                } else {
                    this._barcodeBuffer = e.key;
                }

                clearTimeout(this._barcodeClearTimer);
                this._barcodeClearTimer = setTimeout(() => {
                    this._barcodeBuffer = '';
                }, 1000);
            }
        });
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

    _initShortcuts() {
        window.addEventListener('keydown', (e) => {
            const tag = e.target.tagName;
            const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable;
            const wire = this._wire();

            if (!wire) return;
            const step = wire.get('step');

            if (e.key === 'Escape') {
                this.selectingProduct = null;
                this.pendingSelections = {};
                if (step === 'payment') {
                    e.preventDefault();
                    wire.call('backToCatalog');
                    this._focusBarcode();
                }
                return;
            }

            if (typing) return;

            if (e.key === '/') {
                e.preventDefault();
                this._focusSearch();
                return;
            }

            if (e.key === 'F2') {
                e.preventDefault();
                wire.call('proceedToPayment');
                return;
            }

            if (e.key === 'F10') {
                e.preventDefault();
                if (step === 'payment') {
                    wire.call('processOrder');
                }
            }
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
        return this.selectingProduct.groups.every(
            group => group.fixed || this.getGroupTotal(group.id) <= group.total_qty
        );
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
