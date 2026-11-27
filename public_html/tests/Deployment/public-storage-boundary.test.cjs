const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.resolve(__dirname, '../..');

// The storage stub answers every path, so these tests prove the public route itself blocks
// internal paths instead of relying on the storage handler's token check.
function load() {
    const exports = {};
    const storageCalls = [];
    const source = ts.transpileModule(fs.readFileSync(path.join(root, 'src/index.ts'), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    vm.runInNewContext(source, {
        exports, URL, Headers, Request, Response, AbortSignal, Date, scheduler: { wait: async () => {} },
        require(name) {
            if (name === './monitoring') return { checkOperations: async () => ({ status: 'ok' }) };
            if (name === './storage') return {
                storageRequest: async (request) => { storageCalls.push(new URL(request.url).pathname); return new Response('storage'); },
                isStorageAuthorized: async (request) => request.headers.get('authorization') === 'Bearer internal-test-token',
            };
            assert.equal(name, 'cloudflare:workers');
            return {
                WorkerEntrypoint: class { constructor(ctx, env) { this.ctx = ctx; this.env = env; } },
                DurableObject: class { constructor(ctx, env) { this.ctx = ctx; this.env = env; } },
            };
        },
    });
    const forwarded = [];
    const env = {
        APP_URL: 'https://erp.example.test', INTERNAL_TOKEN: 'internal-test-token',
        LARAVEL_CONTAINER: { getByName() { return { fetch: async (request) => { forwarded.push(request); return new Response('from app'); } }; } },
    };
    return { exports, env, forwarded, storageCalls };
}

test('the public hostname never serves the database or upload gateway, even with the token', async () => {
    const { exports, env, forwarded, storageCalls } = load();
    const auth = { authorization: 'Bearer internal-test-token' };
    for (const [method, url] of [
        ['POST', '/__cloudflare/database/rows_batch'], ['POST', '/__cloudflare/database/commit'], ['POST', '/__cloudflare/database/schema'],
        ['PUT', '/__cloudflare/uploads/profile_photo/00000000-0000-0000-0000-000000000000.png'], ['DELETE', '/__cloudflare/uploads/x'],
    ]) {
        const response = await exports.default.fetch(new Request('https://erp.example.test' + url, { method, headers: auth }), env);
        assert.equal(response.status, 404, url);
    }
    assert.deepEqual(storageCalls, []);
    assert.equal(forwarded.length, 0);
});

test('public profile photos are still served, and the internal gateway still reaches storage', async () => {
    const { exports, env, storageCalls } = load();
    const photo = await exports.default.fetch(new Request('https://erp.example.test/uploads/profile_photo/00000000-0000-0000-0000-000000000000.png'), env);
    assert.equal(await photo.text(), 'storage');
    const gateway = new exports.StorageGateway({}, env);
    assert.equal(await (await gateway.fetch(new Request('http://erp-storage.internal/__cloudflare/database/state', { method: 'POST' }))).text(), 'storage');
    assert.deepEqual(storageCalls, ['/uploads/profile_photo/00000000-0000-0000-0000-000000000000.png', '/__cloudflare/database/state']);
});

test('the restart endpoint stays public and still requires the token', async () => {
    const { exports, env } = load();
    const response = await exports.default.fetch(new Request('https://erp.example.test/__cloudflare/restart', { method: 'POST' }), env);
    assert.equal(response.status, 401);
});
