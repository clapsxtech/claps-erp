// Builds the React + Astryx application into public/ui (hashed files plus a
// manifest the Blade host page reads). Astryx ships prebuilt CSS; the StyleX
// plugin compiles only our own stylex.create() calls, never node_modules.
//
//   node scripts/build-assets.mjs                 development build
//   node scripts/build-assets.mjs --production    minified build
//   node scripts/build-assets.mjs --watch         rebuild on change
//   UI_OUT=public/ui-x node scripts/build-assets.mjs   build into another folder (serve with APP_UI_DIR=ui-x)
import { build } from 'esbuild';
import { rm, writeFile, watch } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
process.chdir(root);
const production = process.argv.includes('--production');
const uiOut = process.env.UI_OUT || 'public/ui';

async function buildApp() {
    const { default: stylex } = await import('@stylexjs/unplugin/esbuild');
    await rm(uiOut, { recursive: true, force: true });
    const result = await build({
        entryPoints: { main: 'resources/app/main.tsx' },
        bundle: true, splitting: true, format: 'esm', platform: 'browser', target: ['es2020'],
        minify: production, legalComments: 'eof', logLevel: 'warning',
        outdir: uiOut, entryNames: '[name]-[hash]', chunkNames: 'chunks/[name]-[hash]', assetNames: 'assets/[name]-[hash]',
        jsx: 'automatic', metafile: true,
        loader: { '.woff': 'file', '.woff2': 'file', '.svg': 'file', '.png': 'file' },
        define: { 'process.env.NODE_ENV': JSON.stringify(production ? 'production' : 'development') },
        plugins: [stylex({ useCSSLayers: true, dev: !production, unstable_moduleResolution: { type: 'commonJS', rootDir: root } })],
    });
    const entry = {};
    for (const [file, meta] of Object.entries(result.metafile.outputs)) {
        if (meta.entryPoint === 'resources/app/main.tsx') {
            entry.js = '/' + path.relative('public', file).split(path.sep).join('/');
            if (meta.cssBundle) entry.css = '/' + path.relative('public', meta.cssBundle).split(path.sep).join('/');
        }
    }
    await writeFile(`${uiOut}/manifest.json`, JSON.stringify(entry, null, 2) + '\n');
    console.log(`App built into ${uiOut} (${production ? 'production' : 'development'}).`);
}

await buildApp();
if (process.argv.includes('--watch')) {
    // Async iteration serializes builds; no stale build can finish over a newer one.
    for await (const event of watch('resources/app', { recursive: true })) {
        if (!event.filename || !/\.(ts|tsx|css)$/.test(event.filename)) continue;
        try { await buildApp(); } catch (error) { console.error(error); }
    }
}
