const registerOrdersKanban = () => {
    Alpine.data('ordersKanban', (wireId, canUpdate = false) => ({
        _hookCleanup: null,
        _drag: null,

        init() {
            const wire = this.$wire;

            this.$nextTick(() => this._initNativeDrag(wire));

            this._hookCleanup = Livewire.hook('commit', ({ component, succeed }) => {
                if (component.id === wireId) {
                    succeed(() => this.$nextTick(() => this._initNativeDrag(wire)));
                }
            });
        },

        destroy() {
            if (this._hookCleanup) {
                this._hookCleanup();
                this._hookCleanup = null;
            }
        },

        _initNativeDrag(wire) {
            const columns = this.$root.querySelectorAll('[data-kanban-col]');

            this.$root.querySelectorAll('[data-order-id]').forEach(card => {
                card.draggable = canUpdate;
                card.querySelectorAll('a, button').forEach(child => {
                    child.draggable = false;
                });

                card.ondragstart = (event) => {
                    const column = card.closest('[data-kanban-col]');
                    this._drag = {
                        orderId: parseInt(card.dataset.orderId, 10),
                        oldStatus: column?.dataset?.kanbanCol,
                        channel: card.dataset.channel,
                    };

                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(card.dataset.orderId));
                    card.classList.add('opacity-60');
                };

                card.ondragend = () => {
                    card.classList.remove('opacity-60');
                };
            });

            columns.forEach(col => {
                col.ondragover = (event) => {
                    if (!this._drag || !canUpdate) return;

                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                };

                col.ondrop = async (event) => {
                    if (!this._drag || !canUpdate) return;

                    event.preventDefault();

                    const orderId = this._drag.orderId || parseInt(event.dataTransfer.getData('text/plain'), 10);
                    const oldStatus = this._drag.oldStatus;
                    const channel = this._drag.channel;
                    const newStatus = col.dataset.kanbanCol;

                    if (!orderId || !newStatus || newStatus === oldStatus) {
                        this._drag = null;
                        return;
                    }

                    try {
                        // Cancelar pedido iFood exige motivo fechado — abre modal em vez
                        // de aplicar o cancelamento direto do drag.
                        if (newStatus === 'cancelled' && channel === 'ifood') {
                            await wire.call('openIfoodCancelModal', orderId);
                        } else {
                            await wire.call('updateOrderStatus', orderId, newStatus);
                        }
                    } catch (error) {
                        await wire.$refresh();
                    } finally {
                        this._drag = null;
                    }
                };
            });
        },
    }));
};

if (window.Alpine) {
    registerOrdersKanban();
} else {
    document.addEventListener('alpine:init', registerOrdersKanban, { once: true });
}
