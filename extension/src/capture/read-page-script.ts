import { log } from '../log';

import { readPage } from './read-page';

import type { PostingCapture } from '../api/client';

export interface PageRead {
  posting: PostingCapture;
  characters: number;
  hiddenCharacters: number;
  hasJsonLd: boolean;
}

/**
 * Where this script leaves its work for the service worker to collect.
 *
 * `executeScript` hands back the value of a function it was given, but that
 * function is serialised to source and so cannot close over a bundled module —
 * and injecting a *file* hands nothing back. So the file publishes a function
 * under a known name and the worker's second, self-contained injection calls
 * it. Both injections land in the same isolated world, which is how the name is
 * there to be found.
 */
export const READ_FUNCTION = '__cvTailorReadPage';

function readThisPage(): PageRead {
  const { posting, hiddenCharacters, hasJsonLd } = readPage(document, location.href);

  const read: PageRead = {
    posting,
    characters: posting.page_text.length,
    hiddenCharacters,
    hasJsonLd,
  };

  log('page read', {
    host: location.hostname,
    characters: read.characters,
    jsonLd: read.hasJsonLd,
    hiddenText: posting.has_hidden_text,
    hiddenCharacters: read.hiddenCharacters,
  });

  return read;
}

(globalThis as unknown as Record<string, unknown>)[READ_FUNCTION] = readThisPage;
