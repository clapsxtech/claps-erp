const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.join(__dirname, '../..');

function workerHarness(appUrl = 'https://logs.example.test', environment = {}, unavailableProbes = 0) {
    const exports = {};
    let clock = 0, probes = 0;
    // Node requires duplex for a streamed request body; Workers does not.
    class WorkerRequest extends Request {
        constructor(input, init) {
            if (init && init.body) init = { ...init, duplex: 'half' };
            super(input, init);
        }
    }
    const source = ts.transpileModule(fs.readFileSync(path.join(root, 'src/index.ts'), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    vm.runInNewContext(source, {
        exports, URL, Headers, Request: WorkerRequest, Response, AbortSignal,
        Date: class extends Date { static now() { return clock; } },
        scheduler: { wait: async (ms) => { clock += ms; } },
        require(name) {
            if (name === './monitoring') return { checkOperations: async () => ({ status: 'ok' }) };
            if (name === './storage') return { storageRequest: async () => null };
            assert.equal(name, 'cloudflare:workers');
            return { WorkerEntrypoint: class {}, DurableObject: class { constructor(ctx, env) { this.ctx = ctx; this.env = env; } } };
        },
    });
    const gatewayBinding = {};
    const forwarded = [];
    let startOptions;
    const port = { async fetch(request) {
        if (typeof request === 'string') {
            assert.equal(request, 'http://container/ready');
            if (probes++ < unavailableProbes) throw new Error('Container is still booting');
            return new Response('ready');
        }
        forwarded.push(request); return new Response('application response');
    } };
    const container = {
        running: false, images: { base: 'test-image' },
        start(options) {
            // Reject obsolete instance names just as the Containers API does.
            assert.ok(['lite', 'standard-1', 'standard-2', 'standard-3', 'standard-4'].includes(options.instance));
            startOptions = options;
            this.running = true;
        },
        interceptOutboundHttp: async (host, binding) => { assert.equal(host, 'erp-storage.internal'); assert.equal(binding, gatewayBinding); },
        setInactivityTimeout: async () => {},
        getTcpPort(number) { assert.equal(number, 8080); return port; },
    };
    const worker = new exports.LaravelContainer({ container, blockConcurrencyWhile: (cb) => cb() }, { APP_URL: appUrl, STORAGE_GATEWAY: gatewayBinding, ...environment });
    return { worker, forwarded, container, get probes() { return probes; }, get startOptions() { return startOptions; } };
}

test('cold startup tolerates fast connection refusals for more than twenty probes', async () => {
    const h = workerHarness('https://logs.example.test', {}, 50);
    assert.equal((await h.worker.fetch(new Request('https://logs.example.test/erp'))).status, 200);
    assert.equal(h.forwarded.length, 1);
});

test('an unavailable container reaches a bounded readiness deadline without forwarding', async () => {
    const h = workerHarness('https://logs.example.test', {}, Infinity);
    await assert.rejects(h.worker.fetch(new Request('https://logs.example.test/erp')), /did not become ready/);
    assert.equal(h.forwarded.length, 0);
});

test('Worker preserves public Host, query, body and authentication headers over TCP HTTP', async () => {
    const h = workerHarness();
    const response = await h.worker.fetch(new Request('https://logs.example.test/records?day=23', {
        method: 'POST', body: 'value=sample', headers: { Cookie: 'session=test-only', 'Content-Type': 'application/x-www-form-urlencoded' },
    }));
    assert.equal(await response.text(), 'application response');
    const request = h.forwarded[0];
    assert.equal(request.url, 'http://logs.example.test/records?day=23');
    assert.equal(request.headers.get('host'), 'logs.example.test');
    assert.equal(request.headers.get('cookie'), 'session=test-only');
    assert.equal(await request.text(), 'value=sample');
    assert.equal(request.redirect, 'manual');
    assert.equal(h.startOptions.env.PUBLIC_ORIGIN, 'https://logs.example.test');
    assert.equal(h.startOptions.env.TRUSTED_PROXIES, '');
    assert.equal(h.startOptions.env.SESSION_SECURE_COOKIE, 'true');
    assert.equal(h.startOptions.env.CACHE_DRIVER, 'file');
    assert.equal(h.startOptions.env.CLOUDFLARE_GATEWAY_URL, 'http://erp-storage.internal');
});

test('Worker replaces all client-supplied forwarding origin headers and strips spoofed chains', async () => {
    const h = workerHarness();
    await h.worker.fetch(new Request('https://logs.example.test:8443/login', { headers: {
        Host: 'evil.example', Forwarded: 'proto=http;host=evil.example',
        'X-Forwarded-Host': 'evil.example', 'X-Forwarded-Proto': 'http', 'X-Forwarded-Port': '81',
        'X-Forwarded-For': '203.0.113.99', 'X-Forwarded-Prefix': '/evil',
        'X-Real-IP': '203.0.113.99', 'X-Original-URL': '/evil', 'X-Rewrite-URL': '/evil',
    } }));
    const headers = h.forwarded[0].headers;
    assert.equal(h.forwarded[0].url, 'http://logs.example.test:8443/login');
    assert.equal(headers.get('host'), 'logs.example.test:8443');
    assert.equal(headers.get('x-forwarded-host'), 'logs.example.test:8443');
    assert.equal(headers.get('x-forwarded-proto'), 'https');
    assert.equal(headers.get('x-forwarded-port'), '8443');
    for (const name of ['forwarded', 'x-forwarded-for', 'x-forwarded-prefix', 'x-real-ip', 'x-original-url', 'x-rewrite-url']) {
        assert.equal(headers.get(name), null);
    }
});

test('Worker passes the Cloudflare visitor address and ignores a visitor-supplied copy', async () => {
    const h = workerHarness();
    await h.worker.fetch(new Request('https://logs.example.test/parent/login', { headers: { 'CF-Connecting-IP': '198.51.100.23', 'X-ERP-Client-IP': '203.0.113.66' } }));
    assert.equal(h.forwarded[0].headers.get('x-erp-client-ip'), '198.51.100.23');
    assert.equal(h.startOptions.env.ERP_CLIENT_IP_FROM_WORKER, 'true');
    await h.worker.fetch(new Request('https://logs.example.test/parent/login', { headers: { 'X-ERP-Client-IP': '203.0.113.66' } }));
    assert.equal(h.forwarded[1].headers.get('x-erp-client-ip'), null);
});

test('HTTP local development does not enable canonical HTTPS mode', async () => {
    const h = workerHarness('http://localhost:8787');
    await h.worker.fetch(new Request('http://localhost:8787/health'));
    assert.equal(h.startOptions.env.PUBLIC_ORIGIN, '');
    assert.equal(h.startOptions.env.SESSION_SECURE_COOKIE, 'false');
    assert.equal(h.forwarded[0].url, 'http://localhost:8787/health');
});


test('Worker module configuration is explicit and Sheets remains disabled by default', async () => {
    const defaults = workerHarness();
    await defaults.worker.fetch(new Request('https://logs.example.test/health'));
    assert.equal(defaults.startOptions.env.SHEETS_SYNC_ENABLED, 'false');
    assert.equal(defaults.startOptions.env.GOOGLE_SERVICE_ENABLED, 'false');
    assert.equal(defaults.startOptions.env.GOOGLE_SPREADSHEET_ID, '');
    assert.equal(defaults.startOptions.env.ERP_BUSINESS_ID, '1');
    assert.equal(defaults.startOptions.env.ERP_SHEETS_TRANSITION_ENABLED, 'false');
    assert.equal(defaults.startOptions.env.ERP_DATA_AUTHORITY, 'application');
    assert.equal(defaults.startOptions.env.ERP_SHEETS_BAND_UNITS, '{}');
    const configured = workerHarness('https://logs.example.test', {
        SHEETS_SYNC_ENABLED: 'true', GOOGLE_SERVICE_ENABLED: 'true',
        GOOGLE_SPREADSHEET_ID: 'test-sheet-not-a-real-account', ERP_BUSINESS_ID: '7',
        ERP_SHEETS_TRANSITION_ENABLED: 'true', ERP_DATA_AUTHORITY: 'sheets', ERP_SHEETS_BAND_UNITS: '{"AA":42}',
    });
    await configured.worker.fetch(new Request('https://logs.example.test/health'));
    assert.equal(configured.startOptions.env.SHEETS_SYNC_ENABLED, 'true');
    assert.equal(configured.startOptions.env.GOOGLE_SERVICE_ENABLED, 'true');
    assert.equal(configured.startOptions.env.GOOGLE_SPREADSHEET_ID, 'test-sheet-not-a-real-account');
    assert.equal(configured.startOptions.env.ERP_BUSINESS_ID, '7');
    assert.equal(configured.startOptions.env.ERP_SHEETS_TRANSITION_ENABLED, 'true');
    assert.equal(configured.startOptions.env.ERP_DATA_AUTHORITY, 'sheets');
    assert.equal(configured.startOptions.env.ERP_SHEETS_BAND_UNITS, '{"AA":42}');
});


test('warm requests skip readiness probes, but a stopped container is probed again', async () => {
    const h = workerHarness();
    await h.worker.fetch(new Request('https://logs.example.test/tools'));
    assert.equal(h.probes, 1);
    await h.worker.fetch(new Request('https://logs.example.test/tools'));
    assert.equal(h.probes, 1);
    h.container.running = false;
    await h.worker.fetch(new Request('https://logs.example.test/tools'));
    assert.equal(h.probes, 2);
});

test('a failed forwarded mutation is not replayed and invalidates readiness', async () => {
    const h = workerHarness();
    await h.worker.fetch(new Request('https://logs.example.test/tools'));
    const original = h.container.getTcpPort;
    let attempts = 0;
    h.container.getTcpPort = () => ({ fetch: async () => { attempts++; throw new Error('Disconnected'); } });
    await assert.rejects(h.worker.fetch(new Request('https://logs.example.test/save', { method: 'POST', body: 'x=1' })), /Disconnected/);
    assert.equal(attempts, 1);
    h.container.getTcpPort = original;
    await h.worker.fetch(new Request('https://logs.example.test/tools'));
    assert.equal(h.probes, 2);
});
