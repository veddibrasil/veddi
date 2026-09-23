// Embedded Signup do WhatsApp (Meta): o restaurante conecta o próprio número.
//
// Fluxo: clique → FB.login (popup da Meta) → a Meta devolve o `code` no callback do login e
// os ids (waba_id / phone_number_id) por postMessage, em ordem não garantida → juntamos os
// dois e entregamos ao Livewire (completeSignup). Nada aqui é logado: code e ids são sensíveis.

const ALLOWED_ORIGINS = ['https://www.facebook.com', 'https://web.facebook.com'];

const FINISH_EVENTS = ['FINISH', 'FINISH_ONLY_WABA', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'];

// Quanto esperar pelo postMessage depois que o login devolve o code.
const SESSION_WAIT_MS = 5000;

const MSG_RELOAD = 'Não foi possível carregar o WhatsApp da Meta. Recarregue a página e tente novamente.';

const MSG_NO_SESSION = 'A Meta não confirmou a conta e o número conectados. Clique em "Conectar WhatsApp" para refazer.';

const onlyDigits = (value) => (typeof value === 'string' || typeof value === 'number') && /^\d{5,32}$/.test(String(value))
    ? String(value)
    : null;

const registerWhatsappSignup = () => {
    Alpine.data('whatsappSignup', ({ appId, configId, graphVersion }) => ({
        busy: false,
        error: null,

        session: null,
        _onMessage: null,
        _waiter: null,

        init() {
            this._onMessage = (event) => this.handleMessage(event);
            window.addEventListener('message', this._onMessage);

            this.loadSdk();
        },

        destroy() {
            window.removeEventListener('message', this._onMessage);
            this.clearWaiter();
        },

        // O SDK só é carregado nesta tela (não faz parte do bundle do painel).
        loadSdk() {
            const init = () => {
                window.FB.init({ appId, autoLogAppEvents: true, xfbml: false, version: graphVersion });
            };

            if (window.FB) {
                init();

                return;
            }

            window.fbAsyncInit = init;

            if (document.getElementById('facebook-jssdk')) {
                return;
            }

            const script = document.createElement('script');
            script.id = 'facebook-jssdk';
            script.src = 'https://connect.facebook.net/pt_BR/sdk.js';
            script.async = true;
            script.defer = true;
            script.crossOrigin = 'anonymous';
            document.head.appendChild(script);
        },

        // FB.login precisa ser chamado de forma síncrona dentro do clique (senão o popup é bloqueado).
        connect() {
            if (this.busy) {
                return;
            }

            this.error = null;

            if (!window.FB || !configId) {
                this.error = MSG_RELOAD;

                return;
            }

            this.session = null;
            this.busy = true;

            window.FB.login((response) => this.handleLogin(response), {
                config_id: configId,
                response_type: 'code',
                override_default_response_type: true,
                extras: {
                    setup: {},
                    featureType: 'whatsapp_business_app_onboarding',
                    sessionInfoVersion: '3',
                },
            });
        },

        handleMessage(event) {
            if (!ALLOWED_ORIGINS.includes(event.origin)) {
                return;
            }

            let payload = event.data;

            if (typeof payload === 'string') {
                try {
                    payload = JSON.parse(payload);
                } catch {
                    return;
                }
            }

            if (!payload || payload.type !== 'WA_EMBEDDED_SIGNUP') {
                return;
            }

            const data = payload.data ?? {};

            if (FINISH_EVENTS.includes(payload.event)) {
                this.session = {
                    wabaId: onlyDigits(data.waba_id),
                    phoneNumberId: onlyDigits(data.phone_number_id),
                    // Número que já vive no app WhatsApp Business (coexistência) NÃO pode ser registrado na Cloud API.
                    onboardingType: payload.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING' ? 'coexistence' : 'cloud_api',
                };

                this.notifyWaiter();

                return;
            }

            if (payload.event === 'CANCEL') {
                const step = data.current_step ? ` na etapa "${String(data.current_step).slice(0, 60)}"` : '';
                this.error = `A conexão foi cancelada${step}. Clique em "Conectar WhatsApp" para tentar de novo.`;
                this.busy = false;
                this.clearWaiter();

                return;
            }

            if (payload.event === 'ERROR') {
                const errorId = data.error_id ? ` (código ${String(data.error_id).slice(0, 40)})` : '';
                this.error = `A Meta informou um erro durante a conexão${errorId}. Tente novamente.`;
                this.busy = false;
                this.clearWaiter();
            }
        },

        async handleLogin(response) {
            const code = response?.authResponse?.code;

            // Sem code: login cancelado ou recusado. Se a Meta já explicou (CANCEL/ERROR), mantém a mensagem.
            if (!code) {
                this.busy = false;
                this.error ??= 'A conexão foi cancelada antes de terminar. Clique em "Conectar WhatsApp" para tentar de novo.';

                return;
            }

            const session = await this.waitForSession();

            // Sem o postMessage não sabemos se é número novo ou coexistência — e registrar na Cloud API
            // um número que está no app WhatsApp Business o tira do app. Melhor refazer do que chutar.
            if (!session) {
                this.busy = false;
                this.error ??= MSG_NO_SESSION;

                return;
            }

            try {
                await this.$wire.completeSignup(code, session.wabaId, session.phoneNumberId, session.onboardingType);
            } finally {
                this.busy = false;
            }
        },

        waitForSession() {
            if (this.session) {
                return Promise.resolve(this.session);
            }

            return new Promise((resolve) => {
                const timer = setTimeout(() => {
                    this._waiter = null;
                    resolve(this.session);
                }, SESSION_WAIT_MS);

                this._waiter = () => {
                    clearTimeout(timer);
                    this._waiter = null;
                    resolve(this.session);
                };
            });
        },

        notifyWaiter() {
            this._waiter?.();
        },

        clearWaiter() {
            if (this._waiter) {
                this._waiter();
            }
        },
    }));
};

if (window.Alpine) {
    registerWhatsappSignup();
} else {
    document.addEventListener('alpine:init', registerWhatsappSignup, { once: true });
}
