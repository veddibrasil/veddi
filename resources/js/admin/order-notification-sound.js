const ALARM_INTERVAL_MS = 2500;

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
        if (ctx && ctx.state !== 'running') await ctx.resume();
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

        const isAlarm = kind === 'alarm';

        // Alarm: fast square-wave ring, harsh on purpose so it cuts through kitchen noise.
        // Chat: three ascending double notes, distinct from the usual two-note toast.
        const notes = isAlarm
            ? [[988, 0], [1319, 0.18], [988, 0.36], [1319, 0.54], [988, 0.72], [1319, 0.9]]
            : isChat
                ? [[659, 0], [880, 0.16], [659, 0.4], [880, 0.56], [988, 0.8], [1319, 0.96]]
                : [[880, 0], [660, 0.12]];

        for (const [frequency, offset] of notes) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            const start = ctx.currentTime + offset;
            osc.type = isAlarm ? 'square' : 'sine';
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.setValueAtTime(frequency, start);
            gain.gain.setValueAtTime(0.001, start);
            gain.gain.exponentialRampToValueAtTime(isAlarm ? 0.4 : 0.22, start + 0.015);
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

export function orderAcceptanceAlarm() {
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
            // Browsers that allow audio without a gesture resolve here; the rest keep resume()
            // pending and the page shows the sound-off warning until the first interaction.
            this.enableAudio();
        },

        async enableAudio() {
            const wasReady = this.audioReady;
            this.audioReady = await unlockNotificationAudio();
            if (!wasReady && this.audioReady && this.ids.length) this.ring();
        },

        ring() {
            this.audioReady = playNotificationSound('alarm');
        },

        sync(ids) {
            const hasNew = ids.some(id => !this.ids.includes(id));
            this.ids = ids;
            if (!ids.length) {
                clearInterval(this.timer);
                this.timer = null;
                return;
            }
            if (hasNew) this.ring();
            this.timer ??= setInterval(() => this.ring(), ALARM_INTERVAL_MS);
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
    const register = () => window.Alpine.data('orderAcceptanceAlarm', orderAcceptanceAlarm);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
