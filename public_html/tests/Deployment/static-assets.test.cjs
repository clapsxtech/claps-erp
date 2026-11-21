const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
test('static packaging excludes application source, uploads, maps, hidden files and symlinks', () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-assets-'));
    try {
        for (const dir of ['ui/chunks','ui/assets','icons','css','uploads']) fs.mkdirSync(path.join(root,'public',dir),{recursive:true});
        for (const file of ['favicon.ico','robots.txt','index.php','ui/leak.php','ui/test.js.map','ui/.secret.js','css/old.css','uploads/private.png','ui/main-AB12.js','ui/main-AB12.css','ui/chunks/Students-CD34.js','ui/assets/inter-400.woff2','ui/manifest.json','manifest.webmanifest','parent.webmanifest','icons/icon-192.png','icons/notes.txt']) fs.writeFileSync(path.join(root,'public',file),'test');
        fs.symlinkSync(path.join(root,'public/index.php'),path.join(root,'public/ui/link.js'));
        execFileSync(process.execPath,[path.resolve('scripts/stage-cloudflare-assets.mjs')],{cwd:root});
        // The React app bundle (built into public/ui) is served from the edge.
        // So is the home-screen install kit: manifests and icons.
        for (const file of ['manifest.webmanifest','parent.webmanifest','icons/icon-192.png','favicon.ico']) assert.ok(fs.existsSync(path.join(root,'.cloudflare-assets',file)),file);
        for (const file of ['ui/main-AB12.js','ui/main-AB12.css','ui/chunks/Students-CD34.js','ui/assets/inter-400.woff2']) assert.ok(fs.existsSync(path.join(root,'.cloudflare-assets',file)),file);
        for (const file of ['index.php','ui/leak.php','ui/test.js.map','ui/.secret.js','ui/link.js','css/old.css','uploads/private.png','icons/notes.txt']) assert.equal(fs.existsSync(path.join(root,'.cloudflare-assets',file)),false,file);
    } finally { fs.rmSync(root,{recursive:true,force:true}); }
});
