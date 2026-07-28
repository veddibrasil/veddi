Alpine.data('chatApp', () => ({
    copied: false,
    cepLoading: false,

    formatPhone(v) {
        v = v.replace(/\D/g, '').slice(0, 11);
        if (v.length === 0) return '';
        if (v.length <= 2) return '(' + v;
        if (v.length <= 6) return '(' + v.slice(0,2) + ') ' + v.slice(2);
        if (v.length <= 10) return '(' + v.slice(0,2) + ') ' + v.slice(2,6) + '-' + v.slice(6);
        return '(' + v.slice(0,2) + ') ' + v.slice(2,7) + '-' + v.slice(7);
    },

    formatCep(v) {
        v = v.replace(/\D/g, '').slice(0, 8);
        return v.length > 5 ? v.slice(0,5) + '-' + v.slice(5) : v;
    },

    formatCpf(v) {
        v = v.replace(/\D/g, '').slice(0, 11);
        if (v.length <= 3) return v;
        if (v.length <= 6) return v.slice(0,3) + '.' + v.slice(3);
        if (v.length <= 9) return v.slice(0,3) + '.' + v.slice(3,6) + '.' + v.slice(6);
        return v.slice(0,3) + '.' + v.slice(3,6) + '.' + v.slice(6,9) + '-' + v.slice(9);
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
            wire.addToCartWithOptions(product.id, selections);
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
        wire.addToCartWithOptions(this.selectingProduct.id, selections);
        this.selectingProduct = null;
        this.pendingSelections = {};
    },

    async lookupCep(cep, wire) {
        const digits = cep.replace(/\D/g, '');
        if (digits.length !== 8) return;
        this.cepLoading = true;
        try {
            const res = await fetch('https://viacep.com.br/ws/' + digits + '/json/');
            const data = await res.json();
            if (!data.erro) {
                if (data.logradouro) wire.set('address', data.logradouro);
                if (data.bairro) wire.set('neighborhood', data.bairro);
                if (data.localidade) wire.set('city', data.localidade);
            }
        } catch(e) {}
        this.cepLoading = false;
    },
}));
