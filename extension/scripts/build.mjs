import { cp, mkdir, rm } from 'node:fs/promises';

import * as esbuild from 'esbuild';

const outdir = 'dist';

// Manifest V3 service workers are modules in a recent Chrome; nothing older
// can load this extension anyway.
const target = 'chrome120';

await rm(outdir, { recursive: true, force: true });
await mkdir(outdir, { recursive: true });

await esbuild.build({
  entryPoints: ['src/service-worker.ts', 'src/popup.ts', 'src/options.ts'],
  outdir,
  bundle: true,
  format: 'esm',
  target,
  platform: 'browser',
  sourcemap: true,
});

// The page reader is injected into a page by `chrome.scripting.executeScript`,
// which runs a classic script rather than a module, so this one is an IIFE.
await esbuild.build({
  entryPoints: ['src/capture/read-page-script.ts'],
  outfile: `${outdir}/read-page.js`,
  bundle: true,
  format: 'iife',
  target,
  platform: 'browser',
  sourcemap: true,
});

await cp('manifest.json', `${outdir}/manifest.json`);
await cp('popup.html', `${outdir}/popup.html`);
await cp('options.html', `${outdir}/options.html`);
await cp('_locales', `${outdir}/_locales`, { recursive: true });

console.log(`Built unpacked extension in ${outdir}/`);
