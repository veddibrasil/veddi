// Contagem regressiva do prazo do iFood pra aceitar o pedido (tela do pedido, lista e kanban).
// O iFood cancela sozinho o pedido imediato que não for confirmado no prazo.
// O nome não pode começar com "if": o Alpine trata x-data="if...(...)" como comando if.
const registerAcceptanceCountdown = () => {
    Alpine.data('acceptanceCountdown', (deadlineMs) => ({
        remaining: Math.max(0, Math.floor((deadlineMs - Date.now()) / 1000)),
        _timer: null,

        init() {
            this._timer = setInterval(() => {
                this.remaining = Math.max(0, Math.floor((deadlineMs - Date.now()) / 1000));
            }, 1000);
        },

        destroy() {
            clearInterval(this._timer);
        },

        get label() {
            if (this.remaining <= 0) {
                return 'prazo esgotado';
            }

            const minutes = Math.floor(this.remaining / 60);
            const seconds = String(this.remaining % 60).padStart(2, '0');

            return `${minutes}:${seconds}`;
        },

        get urgent() {
            return this.remaining <= 180;
        },
    }));
};

if (window.Alpine) {
    registerAcceptanceCountdown();
} else {
    document.addEventListener('alpine:init', registerAcceptanceCountdown, { once: true });
}
