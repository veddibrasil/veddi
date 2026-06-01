Alpine.data('pdvApp', () => ({
    selectingProduct: null,
    pendingSelections: {},
    sidebarHidden: false,

    init() {
        this.sidebarHidden = localStorage.getItem('pdv_sidebar_hidden') === '1';
        this._applySidebarState();
    },

    toggleSidebar() {
        this.sidebarHidden = !this.sidebarHidden;
        localStorage.setItem('pdv_sidebar_hidden', this.sidebarHidden ? '1' : '0');
        this._applySidebarState();
    },

    _applySidebarState() {
        document.body.classList.toggle('pdv-fullscreen', this.sidebarHidden);
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
