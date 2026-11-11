// Builds public/assets/js/status-lookup.js (the module page) from
// public/assets/js/src. Run: npm ci && npm run build
import { build } from 'esbuild';
import { readFileSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const page = 'public/assets/js/status-lookup.js';

await build({
  entryPoints: ['public/assets/js/src/status-lookup.js'],
  outfile: page,
  bundle: true,
  minify: false,                  // kept readable
  format: 'iife',
  target: ['es2019'],
  banner: { js: '/* Status Lookup for MikoPBX | GPL-3.0-or-later | built from public/assets/js/src */' },
});

for (const file of [page]) {
  const bytes = statSync(file).size;
  const gz = gzipSync(readFileSync(file)).length;
  console.log(`${file}: ${(bytes / 1024).toFixed(0)} KiB, ${(gz / 1024).toFixed(0)} KiB gzip`);
}
