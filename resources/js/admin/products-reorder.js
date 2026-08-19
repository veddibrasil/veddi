const registerProductsReorder = () => {
    Alpine.data('productsReorder', (wireId, canUpdate = false) => ({
        _hookCleanup: null,
        _draggingProductEl: null,
        _draggingCategoryEl: null,

        init() {
            const wire = this.$wire;

            this.$nextTick(() => this._initDrag(wire));

            this._hookCleanup = Livewire.hook('commit', ({ component, succeed }) => {
                if (component.id === wireId) {
                    succeed(() => this.$nextTick(() => this._initDrag(wire)));
                }
            });
        },

        destroy() {
            if (this._hookCleanup) {
                this._hookCleanup();
                this._hookCleanup = null;
            }
        },

        _initDrag(wire) {
            if (!canUpdate) return;

            this.$root.querySelectorAll('[data-reorder-group]').forEach(group => {
                const categoryId = parseInt(group.dataset.reorderGroup, 10);

                group.querySelectorAll('[data-product-id]').forEach(row => {
                    row.draggable = true;
                    row.querySelectorAll('a, button').forEach(child => {
                        child.draggable = false;
                    });

                    row.ondragstart = (event) => {
                        this._draggingProductEl = row;
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', String(row.dataset.productId));
                        row.classList.add('opacity-40');
                    };

                    row.ondragend = () => {
                        row.classList.remove('opacity-40');
                        this._draggingProductEl = null;
                    };

                    row.ondragover = (event) => {
                        if (!this._draggingProductEl || this._draggingProductEl === row) return;

                        event.preventDefault();

                        const rect = row.getBoundingClientRect();
                        const before = (event.clientY - rect.top) < rect.height / 2;
                        row.parentElement.insertBefore(this._draggingProductEl, before ? row : row.nextSibling);
                    };
                });

                group.ondragover = (event) => {
                    if (this._draggingProductEl) event.preventDefault();
                };

                group.ondrop = async (event) => {
                    event.preventDefault();
                    if (!this._draggingProductEl) return;

                    const orderedIds = Array.from(group.querySelectorAll('[data-product-id]'))
                        .map(el => parseInt(el.dataset.productId, 10));

                    this._draggingProductEl = null;

                    try {
                        await wire.call('updateOrder', categoryId, orderedIds);
                    } catch (error) {
                        await wire.$refresh();
                    }
                };
            });

            const categoriesContainer = this.$root.querySelector('[data-reorder-categories]');
            if (categoriesContainer) {
                categoriesContainer.querySelectorAll('[data-category-id]').forEach(card => {
                    const handle = card.querySelector('[data-category-drag-handle]');
                    if (!handle) return;

                    handle.draggable = true;

                    handle.ondragstart = (event) => {
                        this._draggingCategoryEl = card;
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', String(card.dataset.categoryId));
                        card.classList.add('opacity-40');
                    };

                    handle.ondragend = () => {
                        card.classList.remove('opacity-40');
                        this._draggingCategoryEl = null;
                    };

                    card.ondragover = (event) => {
                        if (!this._draggingCategoryEl || this._draggingCategoryEl === card) return;

                        event.preventDefault();

                        const rect = card.getBoundingClientRect();
                        const before = (event.clientY - rect.top) < rect.height / 2;
                        card.parentElement.insertBefore(this._draggingCategoryEl, before ? card : card.nextSibling);
                    };
                });

                categoriesContainer.ondragover = (event) => {
                    if (this._draggingCategoryEl) event.preventDefault();
                };

                categoriesContainer.ondrop = async (event) => {
                    event.preventDefault();
                    if (!this._draggingCategoryEl) return;

                    const orderedCategoryIds = Array.from(categoriesContainer.querySelectorAll('[data-category-id]'))
                        .map(el => parseInt(el.dataset.categoryId, 10));

                    this._draggingCategoryEl = null;

                    try {
                        await wire.call('updateCategoryOrder', orderedCategoryIds);
                    } catch (error) {
                        await wire.$refresh();
                    }
                };
            }
        },
    }));
};

if (window.Alpine) {
    registerProductsReorder();
} else {
    document.addEventListener('alpine:init', registerProductsReorder, { once: true });
}
