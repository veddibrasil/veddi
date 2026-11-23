import test from 'node:test';
import assert from 'node:assert/strict';
import { listenForFiscalNoteAuthorization } from '../../resources/js/admin/pdv-printer.js';

const tick = () => new Promise(setImmediate);

function environment(t, fetch, echo) {
    const originalWindow = globalThis.window;
    const originalFetch = globalThis.fetch;
    globalThis.window = { Echo: echo };
    globalThis.fetch = fetch;
    t.after(() => {
        globalThis.window = originalWindow;
        globalThis.fetch = originalFetch;
    });
}

test('avisa NF-e autorizada imediatamente mesmo sem conexão em tempo real', async t => {
    let notified = 0;
    let requests = 0;
    environment(t, async url => {
        requests++;
        assert.equal(url, '/admin/pdv/fiscal-note-status/42');
        return { ok: true, json: async () => ({ status: 'authorized', model: '55' }) };
    });
    const stop = listenForFiscalNoteAuthorization(42, { onNfeAuthorized: () => notified++ });
    t.after(stop);
    await tick();
    assert.equal(requests, 1);
    assert.equal(notified, 1);
});

test('evento e consulta concorrentes notificam uma única autorização', async t => {
    const listeners = {};
    let notified = 0;
    let left = 0;
    let answer;
    environment(t, () => new Promise(resolve => { answer = resolve; }), {
        private: () => ({ listen: (event, callback) => { listeners[event] = callback; } }),
        leave: () => left++,
    });
    const stop = listenForFiscalNoteAuthorization(42, { onNfeAuthorized: () => notified++ });
    t.after(stop);
    listeners['.FiscalNoteAuthorized']({ model: '55' });
    answer({ ok: true, json: async () => ({ status: 'authorized', model: '55' }) });
    await tick();
    assert.equal(notified, 1);
    assert.equal(left, 1);
});

test('rejeição recebida pela consulta avisa o caixa sem aguardar WebSocket', async t => {
    let failure;
    environment(t, async () => ({ ok: true, json: async () => ({ status: 'rejected', reason: 'Confira o cadastro fiscal.' }) }));
    const stop = listenForFiscalNoteAuthorization(42, { onFailed: reason => { failure = reason; } });
    t.after(stop);
    await tick();
    assert.equal(failure, 'Confira o cadastro fiscal.');
});

test('nota pendente é consultada novamente até autorizar', { timeout: 3000 }, async t => {
    let requests = 0;
    let notified = 0;
    environment(t, async () => ({ ok: true, json: async () => (++requests === 1 ? { status: 'pending' } : { status: 'authorized', model: '55' }) }));
    const done = new Promise(resolve => {
        const stop = listenForFiscalNoteAuthorization(42, { onNfeAuthorized: () => { notified++; resolve(); } });
        t.after(stop);
    });
    await done;
    assert.equal(requests, 2);
    assert.equal(notified, 1);
});

test('cancelar a espera ignora respostas tardias e não agenda novas consultas', async t => {
    let answer;
    let notified = 0;
    environment(t, () => new Promise(resolve => { answer = resolve; }));
    const stop = listenForFiscalNoteAuthorization(42, { onNfeAuthorized: () => notified++ });
    stop();
    answer({ ok: true, json: async () => ({ status: 'authorized', model: '55' }) });
    await tick();
    assert.equal(notified, 0);
});

test('NFC-e autorizada solicita impressão uma vez, sem aguardar WebSocket', async t => {
    const requests = [];
    environment(t, async url => {
        requests.push(url);
        return url.includes('fiscal-note-status')
            ? { ok: true, json: async () => ({ status: 'authorized', model: '65' }) }
            : { ok: false, status: 404 };
    });
    const stop = listenForFiscalNoteAuthorization(42);
    t.after(stop);
    await tick();
    assert.deepEqual(requests, ['/admin/pdv/fiscal-note-status/42', '/admin/pdv/print/fiscal-note/42']);
});
