'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { SessionRegistry } = require('./sessions');

/**
 * عميل واتساب مزيّف: يسجّل ما إذا أُنهي فعلاً، ويتيح إطلاق الأحداث يدوياً.
 * الغرض اختبار دورة حياة الجلسة دون تشغيل متصفّح.
 */
function fakeClient() {
    const handlers = {};

    return {
        destroyed: false,
        initialized: false,
        on(event, handler) {
            handlers[event] = handler;
        },
        emit(event, payload) {
            handlers[event]?.(payload);
        },
        initialize() {
            this.initialized = true;
        },
        async destroy() {
            this.destroyed = true;
        },
    };
}

function registryWith(overrides = {}) {
    const created = [];
    let clock = 1000;

    const registry = new SessionRegistry({
        createClient: (clientId) => {
            const client = fakeClient();
            created.push({ clientId, client });

            return client;
        },
        now: () => clock,
        log: () => {},
        ...overrides,
    });

    return {
        registry,
        created,
        advance: (ms) => { clock += ms; },
        setClock: (value) => { clock = value; },
    };
}

test('reading a session never starts a browser', () => {
    const { registry, created } = registryWith();

    assert.strictEqual(registry.get('supervisor_1'), undefined);
    assert.strictEqual(created.length, 0);
});

test('starting twice reuses the one browser', () => {
    const { registry, created } = registryWith();

    registry.start('supervisor_1');
    registry.start('supervisor_1');

    assert.strictEqual(created.length, 1);
    assert.strictEqual(registry.size, 1);
    assert.ok(created[0].client.initialized);
});

test('a disconnect destroys the browser rather than orphaning it', async () => {
    const { registry, created } = registryWith();

    registry.start('supervisor_1');
    created[0].client.emit('disconnected', 'NAVIGATION');

    // stop() is async; let it settle.
    await new Promise((resolve) => setImmediate(resolve));

    assert.ok(created[0].client.destroyed, 'the browser must actually be destroyed');
    assert.strictEqual(registry.size, 0);
});

test('a reconnect after a disconnect leaves exactly one browser behind', async () => {
    const { registry, created } = registryWith();

    registry.start('supervisor_1');
    created[0].client.emit('disconnected', 'NAVIGATION');
    await new Promise((resolve) => setImmediate(resolve));

    registry.start('supervisor_1');

    assert.strictEqual(created.length, 2, 'a second client is expected');
    assert.ok(created[0].client.destroyed, 'the first must be gone');
    assert.strictEqual(registry.size, 1, 'only one may still be registered');
});

test('an idle session is closed, and a used one is not', async () => {
    const { registry, created, advance } = registryWith({ idleMs: 60_000 });

    registry.start('idle_one');
    registry.start('busy_one');

    advance(59_000);
    registry.touch('busy_one');

    advance(2_000);
    const closed = await registry.sweepIdle();

    assert.deepStrictEqual(closed, ['idle_one']);
    assert.ok(created.find((c) => c.clientId === 'idle_one').client.destroyed);
    assert.ok(!created.find((c) => c.clientId === 'busy_one').client.destroyed);
    assert.strictEqual(registry.size, 1);
});

test('going ready counts as use, so a fresh login is not swept away', async () => {
    const { registry, created, advance } = registryWith({ idleMs: 60_000 });

    registry.start('supervisor_1');

    // Logging in can take longer than the idle window on a slow server.
    advance(59_000);
    created[0].client.emit('ready');

    advance(2_000);
    const closed = await registry.sweepIdle();

    assert.deepStrictEqual(closed, []);
    assert.strictEqual(registry.size, 1);
});

test('a browser that refuses to die still leaves the registry', async () => {
    const { registry } = registryWith({
        createClient: () => {
            const client = fakeClient();
            client.destroy = async () => { throw new Error('already gone'); };

            return client;
        },
    });

    registry.start('supervisor_1');
    await registry.stop('supervisor_1');

    assert.strictEqual(registry.size, 0, 'a failed destroy must not strand the entry');
});

test('stopping something that was never started is harmless', async () => {
    const { registry } = registryWith();

    assert.strictEqual(await registry.stop('nobody'), false);
});

test('stopAll closes every browser', async () => {
    const { registry, created } = registryWith();

    registry.start('a');
    registry.start('b');
    await registry.stopAll();

    assert.strictEqual(registry.size, 0);
    assert.ok(created.every((c) => c.client.destroyed));
});
