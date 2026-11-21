const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');

test('production build writes hashed bundles and a manifest the host page can read', () => {
    const out = fs.mkdtempSync(path.join(os.tmpdir(), 'ui-build-'));
    try {
        execFileSync(process.execPath, ['scripts/build-assets.mjs', '--production'], {cwd: root, env: {...process.env, UI_OUT: out}, stdio: 'pipe'});
        const manifest = JSON.parse(fs.readFileSync(path.join(out, 'manifest.json'), 'utf8'));
        assert.match(manifest.js, /\/main-[A-Z0-9]+\.js$/);
        assert.match(manifest.css, /\/main-[A-Z0-9]+\.css$/);
        const js = fs.readFileSync(path.join(out, path.basename(manifest.js)), 'utf8');
        const css = fs.readFileSync(path.join(out, path.basename(manifest.css)), 'utf8');
        assert.ok(css.includes('astryx'), 'Astryx component styles are bundled');
        assert.ok(fs.readdirSync(path.join(out, 'chunks')).some(f => f.startsWith('Students-')), 'screens are split into lazy chunks');
        // The old UI stack must not come back through a dependency.
        for (const legacy of ['jQuery', 'DataTable(', 'bootstrap.Modal', 'select2']) assert.ok(!js.includes(legacy), legacy);
    } finally {
        fs.rmSync(out, {recursive: true, force: true});
    }
});

test('the only front-end source is the React app', () => {
    for (const gone of ['resources/js', 'resources/css', 'resources/sass', 'resources/vendor', 'public/js', 'public/css', 'public/adminlte']) {
        assert.equal(fs.existsSync(path.join(root, gone)), false, gone);
    }
    const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
    for (const dep of ['jquery', 'bootstrap', 'datatables.net', 'select2', 'moment', 'sass']) {
        assert.equal(pkg.dependencies?.[dep] ?? pkg.devDependencies?.[dep], undefined, dep);
    }
    const views = fs.readdirSync(path.join(root, 'resources/views'), {recursive: true}).filter(f => f.endsWith('.blade.php')).sort();
    assert.deepEqual(views, ['app/shell.blade.php', 'errors/404.blade.php', 'errors/500.blade.php', 'errors/503.blade.php', 'errors/layout.blade.php', 'errors/maintenance.blade.php']);
});
