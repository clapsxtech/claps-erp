const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.resolve(__dirname, '../..');

class WorkerRequest extends Request {
    constructor(input, init) {
        if (init && init.body) init = { ...init, duplex: 'half' };
        super(input, init);
    }
}

function load() {
    const exports = {};
    const source = ts.transpileModule(fs.readFileSync(path.join(root, 'src/index.ts'), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    vm.runInNewContext(source, {
        exports, URL, Headers, Request: WorkerRequest, Response, AbortSignal, Date, scheduler: { wait: async () => {} },
        require(name) {
            if (name === './monitoring') return { checkOperations: async () => ({ status: 'ok' }) };
            if (name === './storage') return { storageRequest: async () => null, isRestartAuthorized: async () => false };
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
        LARAVEL_CONTAINER: { getByName(name) { assert.equal(name, 'production'); return { fetch: async (request) => { forwarded.push(request); return new Response('from app'); } }; } },
    };
    return { exports, env, forwarded };
}

test('the public route hides the sales API and strips the gateway header', async () => {
    const { exports, env, forwarded } = load();
    for (const url of ['https://erp.example.test/api/sales/login', 'https://erp.example.test/API/Sales/me', 'https://erp.example.test/api/sales']) {
        const response = await exports.default.fetch(new WorkerRequest(url, { method: 'POST', headers: { 'X-Claps-Sales-Gateway': 'internal-test-token' } }), env);
        assert.equal(response.status, 404, url);
    }
    assert.equal(forwarded.length, 0);
    await exports.default.fetch(new WorkerRequest('https://erp.example.test/login', { headers: { 'X-Claps-Sales-Gateway': 'internal-test-token', Cookie: 'a=b' } }), env);
    assert.equal(forwarded.length, 1);
    assert.equal(forwarded[0].headers.get('x-claps-sales-gateway'), null);
    assert.equal(forwarded[0].headers.get('cookie'), 'a=b');
});

test('the sales gateway forwards only sales API paths, on the public origin, with the internal header', async () => {
    const { exports, env, forwarded } = load();
    const gateway = new exports.SalesGateway({}, env);
    assert.equal((await gateway.fetch(new WorkerRequest('https://erp/erp/units/1'))).status, 404);
    assert.equal((await gateway.fetch(new WorkerRequest('https://erp/__cloudflare/restart', { method: 'POST' }))).status, 404);
    assert.equal(forwarded.length, 0);

    const response = await gateway.fetch(new WorkerRequest('https://sales.internal/api/sales/units/1/leads?q=asha', {
        headers: { Authorization: 'Bearer t', Accept: 'application/json', Cookie: 'dropped=1', 'X-Claps-Sales-Gateway': 'spoofed' },
    }));
    assert.equal(await response.text(), 'from app');
    const request = forwarded[0];
    assert.equal(request.url, 'https://erp.example.test/api/sales/units/1/leads?q=asha');
    assert.equal(request.headers.get('x-claps-sales-gateway'), 'internal-test-token');
    assert.equal(request.headers.get('authorization'), 'Bearer t');
    assert.equal(request.headers.get('cookie'), null);

    await gateway.fetch(new WorkerRequest('https://sales.internal/api/sales/login', { method: 'POST', body: '{"email":"a"}', headers: { 'Content-Type': 'application/json' } }));
    assert.equal(forwarded[1].method, 'POST');
    assert.equal(await forwarded[1].text(), '{"email":"a"}');
});

test('the sales gateway refuses to forward when the internal token is missing', async () => {
    const { exports, env, forwarded } = load();
    const gateway = new exports.SalesGateway({}, { ...env, INTERNAL_TOKEN: '' });
    assert.equal((await gateway.fetch(new WorkerRequest('https://sales.internal/api/sales/me'))).status, 404);
    assert.equal(forwarded.length, 0);
});
