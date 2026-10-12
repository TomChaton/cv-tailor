import { cp, mkdir, rm } from 'node:fs/promises';

import * as esbuild from 'esbuild';

const outdir = 'dist';

await rm(outdir, { recursive: true, force: true });
await mkdir(outdir, { recursive: true });

await esbuild.build({
  entryPoints: ['src/service-worker.ts'],
  outdir,
  bundle: true,
  format: 'esm',
  // Manifest V3 service workers are modules in a recent Chrome; nothing older
  // can load this extension anyway.
  target: 'chrome120',
  platform: 'browser',
  sourcemap: true,
});

await cp('manifest.json', `${outdir}/manifest.json`);
await cp('_locales', `${outdir}/_locales`, { recursive: true });

console.log(`Built unpacked extension in ${outdir}/`);
