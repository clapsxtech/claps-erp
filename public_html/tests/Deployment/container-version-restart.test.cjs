const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.join(__dirname, '../..');

// One Durable Object instance per call, sharing `state` (the container and its storage)
// between instances, the way a deploy replaces the instance but not the running container.
function load() {
    const exports = {};
    const source = ts.transpileModule(fs.readFileSync(path.join(root, 'src/index.ts'), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    vm.runInNewContext(source, {
        exports, URL, Headers, Request, Response, AbortSignal, Date, console: { log() {} },
        scheduler: { wait: async () => {} },
        require(name) {
            if (name === './monitoring') return { checkOperations: async () => ({ status: 'ok' }) };
            if (name === './storage') return { storageRequest: async () => null, isRestartAuthorized: async () => false };
            return { WorkerEntrypoint: class {}, DurableObject: class { constructor(ctx, env) { this.ctx = ctx; this.env = env; } } };
        },
    });
    return exports;
}

function sharedState() {
    const stored = new Map();
    const state = { starts: 0, destroys: 0, stored };
    state.container = {
        running: false, images: { base: 'image-for-current-version' },
        start() { state.starts++; this.running = true; },
        async destroy() { state.destroys++; this.running = false; },
        interceptOutboundHttp: async () => {},
        setInactivityTimeout: async () => {},
        getTcpPort() { return { fetch: async (request) => new Response(typeof request === 'string' ? 'ready' : 'app') }; },
    };
    state.storage = { get: async (key) => stored.get(key), put: async (key, value) => { stored.set(key, value); } };
    return state;
}

function instance(exports, state, versionId) {
    const env = { APP_URL: 'https://erp.example.test', STORAGE_GATEWAY: {}, ...(versionId ? { CF_VERSION_METADATA: { id: versionId, tag: '', timestamp: '' } } : {}) };
    return new exports.LaravelContainer({ container: state.container, storage: state.storage, blockConcurrencyWhile: (cb) => cb() }, env);
}

const ask = () => new Request('https://erp.example.test/health');

test('a new Worker version restarts a container started by an older version, once', async () => {
    const exports = load(); const state = sharedState();
    const v1 = instance(exports, state, 'version-1');
    assert.equal(await (await v1.fetch(ask())).text(), 'app');
    assert.equal(state.starts, 1);
    assert.equal(state.stored.get('container-started-by-version'), 'version-1');
    await v1.fetch(ask());
    assert.equal(state.destroys, 0, 'same version: no restart');

    // Deploy: a new Durable Object instance, the same running container.
    const v2 = instance(exports, state, 'version-2');
    await v2.fetch(ask());
    assert.equal(state.destroys, 1);
    assert.equal(state.starts, 2);
    assert.equal(state.stored.get('container-started-by-version'), 'version-2');
    await v2.fetch(ask()); await v2.fetch(ask());
    assert.equal(state.destroys, 1, 'only the first request after a deploy restarts');

    // A rollback is just another version change.
    await instance(exports, state, 'version-1').fetch(ask());
    assert.equal(state.destroys, 2);
    assert.equal(state.stored.get('container-started-by-version'), 'version-1');
});

test('a container running before this feature existed is restarted once onto the new version', async () => {
    const exports = load(); const state = sharedState();
    state.container.running = true; // started by older code, which recorded no version
    await instance(exports, state, 'version-2').fetch(ask());
    assert.equal(state.destroys, 1);
    assert.equal(state.stored.get('container-started-by-version'), 'version-2');
});

test('a replaced instance of the same version keeps the running container', async () => {
    const exports = load(); const state = sharedState();
    await instance(exports, state, 'version-1').fetch(ask());
    await instance(exports, state, 'version-1').fetch(ask());
    assert.equal(state.destroys, 0);
    assert.equal(state.starts, 1);
});

test('without version metadata nothing is restarted', async () => {
    const exports = load(); const state = sharedState();
    state.container.running = true;
    await instance(exports, state, null).fetch(ask());
    assert.equal(state.destroys, 0);
    assert.equal(state.stored.size, 0);
});

test('wrangler.jsonc binds the version metadata the restart relies on', () => {
    const config = fs.readFileSync(path.join(root, 'wrangler.jsonc'), 'utf8');
    assert.match(config, /"version_metadata"\s*:\s*\{\s*"binding"\s*:\s*"CF_VERSION_METADATA"\s*\}/);
});
