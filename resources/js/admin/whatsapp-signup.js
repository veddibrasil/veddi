// Embedded Signup do WhatsApp (Meta): o restaurante conecta o próprio número.
//
// Fluxo: clique → FB.login (popup da Meta) → a Meta devolve o `code` no callback do login e
// os ids (waba_id / phone_number_id) por postMessage, em ordem não garantida → juntamos os
// dois e entregamos ao Livewire (completeSignup). Nada aqui é logado: code e ids são sensíveis.

// Qualquer subdomínio de facebook.com (a Meta serve o popup de www., web., business. ...).
const ALLOWED_ORIGIN = /^https:\/\/([a-z0-9-]+\.)*facebook\.com$/;

const FINISH_EVENTS = ['FINISH', 'FINISH_ONLY_WABA', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'];

// O SDK recusa FB.login fora de HTTPS sem chamar o callback (o botão ficaria preso em "Conectando…").
const MSG_HTTPS = 'A conexão com a Meta só funciona em HTTPS. Acesse o painel pelo endereço https:// e tente de novo.';

const MSG_UNSUPPORTED ='A Meta concluiu um tipo de conexão que a Veddi ainda não suporta. Fale com o suporte para conectar este número.';

// Quanto esperar pelo postMessage depois que o login devolve o code.
const SESSION_WAIT_MS = 5000;

const MSG_RELOAD = 'Não foi possível carregar o WhatsApp da Meta. Recarregue a página e tente novamente.';

const MSG_NO_SESSION = 'A Meta não confirmou a conta e o número conectados. Clique em "Conectar WhatsApp" para refazer.';

// completeSignup rejeitado (rede, 500, 419): sem isso o botão volta ao normal sem explicação.
const MSG_SERVER = 'Não foi possível enviar a conexão ao servidor. Tente novamente.';

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

            if (window.location.protocol !== 'https:') {
                this.error = MSG_HTTPS;

                return;
            }

            if (!window.FB || !configId) {
                this.error = MSG_RELOAD;

                return;
            }

            this.session = null;
            this.busy = true;

            // Embedded Signup v4: a versão vem da configuração do Login for Business (config_id),
            // não do extras; o v4 manda o evento de sessão sempre (sem sessionInfoVersion, que era do
            // v2/v3). featureType liga a tela de coexistência (número do app WhatsApp Business).
            try {
                window.FB.login((response) => this.handleLogin(response), {
                    config_id: configId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: {
                        setup: {},
                        featureType: 'whatsapp_business_app_onboarding',
                    },
                });
            } catch {
                this.busy = false;
                this.error = MSG_RELOAD;
            }
        },

        handleMessage(event) {
            if (typeof event.origin !== 'string' || !ALLOWED_ORIGIN.test(event.origin)) {
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

            // Erro reportado pela Meta: vem como CANCEL (ou ERROR) com error_code/error_message.
            // O texto da Meta vai para a tela via x-text (nunca como HTML) e encurtado.
            const errorCode = data.error_code ?? data.error_id;

            if ((payload.event === 'CANCEL' || payload.event === 'ERROR') && (errorCode || data.error_message)) {
                const code = errorCode ? ` (código ${String(errorCode).slice(0, 40)})` : '';
                const message = data.error_message ? ` Mensagem da Meta: "${String(data.error_message).trim().slice(0, 200)}".` : '';
                this.error = `A Meta informou um erro durante a conexão${code}.${message} Tente novamente.`;
                this.busy = false;
                this.clearWaiter();

                return;
            }

            if (payload.event === 'CANCEL' || payload.event === 'ERROR') {
                const step = data.current_step ? ` na etapa "${String(data.current_step).slice(0, 60)}"` : '';
                this.error = `A conexão foi cancelada${step}. Clique em "Conectar WhatsApp" para tentar de novo.`;
                this.busy = false;
                this.clearWaiter();

                return;
            }

            // Outros fins de fluxo (ex.: FINISH_GRANT_ONLY_API_ACCESS, FINISH_OBO_MIGRATION): número já
            // está na Cloud API por outro parceiro; registrar de novo falharia. Avisa em vez de tentar.
            if (typeof payload.event === 'string' && payload.event.startsWith('FINISH')) {
                this.error = MSG_UNSUPPORTED;
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
                // Causa mais comum: WHATSAPP_ES_CONFIG_ID aponta para uma configuração do Login for
                // Business de variação "Geral" (não "Cadastro incorporado do WhatsApp"), que devolve o
                // code mas nunca abre o fluxo do WhatsApp. Sem dados sensíveis no aviso.
                console.warn('[WhatsApp] Login concluído sem o evento WA_EMBEDDED_SIGNUP: confira se a configuração do Embedded Signup (config_id) é do tipo "Cadastro incorporado do WhatsApp" com o produto WhatsApp selecionado.');

                this.busy = false;
                this.error ??= MSG_NO_SESSION;

                return;
            }

            try {
                await this.$wire.completeSignup(code, session.wabaId, session.phoneNumberId, session.onboardingType);
            } catch {
                this.error = MSG_SERVER;
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
