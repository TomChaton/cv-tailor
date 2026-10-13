import { log } from '../log';

import { EXTRACTOR, extractPosting } from './extract';

import type { PostingCapture } from '../api/client';

export type PageExtraction =
  | { ok: true; extractor: string; posting: PostingCapture; hiddenCharacters: number }
  | { ok: false };

/**
 * Where this script leaves its work for the service worker to collect.
 *
 * `executeScript` hands back the value of a function it was given, but that
 * function is serialised to source and so cannot close over a bundled
 * dependency — and injecting a *file* hands nothing back. So the file publishes
 * a function under a known name and the worker's second, self-contained
 * injection calls it. Both injections land in the same isolated world, which is
 * how the name is there to be found.
 */
export const EXTRACT_FUNCTION = '__cvTailorExtract';

function extractFromPage(): PageExtraction {
  log('extractor chosen', { extractor: EXTRACTOR, host: location.hostname });

  const extraction = extractPosting(document, location.href);

  if (extraction === null) {
    log('extraction found nothing', { extractor: EXTRACTOR, host: location.hostname });

    return { ok: false };
  }

  log('extraction finished', {
    extractor: EXTRACTOR,
    characters: extraction.posting.extracted_text.length,
    htmlCharacters: extraction.posting.raw_html.length,
    hiddenText: extraction.posting.has_hidden_text,
    hiddenCharacters: extraction.hiddenCharacters,
  });

  return {
    ok: true,
    extractor: EXTRACTOR,
    posting: extraction.posting,
    hiddenCharacters: extraction.hiddenCharacters,
  };
}

(globalThis as unknown as Record<string, unknown>)[EXTRACT_FUNCTION] = extractFromPage;
