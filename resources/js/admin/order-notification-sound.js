let audioContext;
let lastChatSoundAt = -Infinity;

function context() {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return null;
    audioContext ??= new AudioContext();
    return audioContext;
}

export async function unlockNotificationAudio() {
    try {
        const ctx = context();
        if (ctx?.state === 'suspended') await ctx.resume();
        return ctx?.state === 'running';
    } catch {
        return false;
    }
}

export function playNotificationSound(kind = 'default') {
    try {
        const ctx = context();
        if (ctx?.state !== 'running') return false;
        const isChat = kind === 'chat';
        if (isChat && Date.now() - lastChatSoundAt < 1500) return true;

        // Chat: three ascending double notes, distinct from the usual two-note toast.
        const notes = isChat
            ? [[659, 0], [880, 0.16], [659, 0.4], [880, 0.56], [988, 0.8], [1319, 0.96]]
            : [[880, 0], [660, 0.12]];

        for (const [frequency, offset] of notes) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            const start = ctx.currentTime + offset;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.setValueAtTime(frequency, start);
            gain.gain.setValueAtTime(0.001, start);
            gain.gain.exponentialRampToValueAtTime(0.22, start + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.001, start + 0.18);
            osc.onended = () => { osc.disconnect(); gain.disconnect(); };
            osc.start(start);
            osc.stop(start + 0.2);
        }
        if (isChat) lastChatSoundAt = Date.now();
        return true;
    } catch {
        return false;
    }
}

export function chatOrderAlerts() {
    return {
        ids: [],
        audioReady: false,
        timer: null,
        onInteraction: null,

        init() {
            this.onInteraction = () => this.enableAudio();
            window.addEventListener('pointerdown', this.onInteraction);
            window.addEventListener('keydown', this.onInteraction);
            this.audioReady = audioContext?.state === 'running';
        },

        async enableAudio() {
            const wasReady = this.audioReady;
            this.audioReady = await unlockNotificationAudio();
            if (!wasReady && this.audioReady && this.ids.length) playNotificationSound('chat');
        },

        sync(ids) {
            const hasNew = ids.some(id => !this.ids.includes(id));
            this.ids = ids;
            if (!ids.length) {
                clearInterval(this.timer);
                this.timer = null;
                return;
            }
            if (hasNew) playNotificationSound('chat');
            this.timer ??= setInterval(() => {
                this.audioReady = playNotificationSound('chat');
            }, 8000);
        },

        destroy() {
            clearInterval(this.timer);
            window.removeEventListener('pointerdown', this.onInteraction);
            window.removeEventListener('keydown', this.onInteraction);
        },
    };
}

if (typeof window !== 'undefined') {
    window.playNotificationSound = playNotificationSound;
    const register = () => window.Alpine.data('chatOrderAlerts', chatOrderAlerts);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
