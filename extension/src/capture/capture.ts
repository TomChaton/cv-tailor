import { CaptureFailed, createApiClient } from '../api/client';
import { log } from '../log';
import { readSettings } from '../settings';

import { showBadge } from './badge';
import { landed, worthRetrying } from './outcome';
import { defer, dueCaptures, enqueue, forget, queueDepth } from './queue';

import type { ApiClient, PostingCapture } from '../api/client';
import type { PageExtraction } from './extract-script';
import type { CaptureOutcome } from './outcome';

/** Built by the bundler from `extract-script.ts`. */
const EXTRACT_FILE = 'extract.js';

/**
 * Capture the posting on one tab.
 *
 * The capture is queued before it is sent and removed once it lands, so a
 * capture is never lost — the worst case is one the owner is told was kept.
 */
export async function captureTab(tabId: number, now: number = Date.now()): Promise<CaptureOutcome> {
  const settings = await readSettings();

  if (settings === null) {
    log('capture stopped', { reason: 'not-configured' });

    return await report('not-configured');
  }

  const extraction = await extract(tabId);

  if (!extraction.ok) {
    return await report('nothing-found');
  }

  log('extraction result', {
    extractor: extraction.extractor,
    characters: extraction.posting.extracted_text.length,
    htmlCharacters: extraction.posting.raw_html.length,
    hiddenText: extraction.posting.has_hidden_text,
    hiddenCharacters: extraction.hiddenCharacters,
  });

  const client = createApiClient(settings.baseUrl, settings.token);
  const entry = await enqueue(extraction.posting, now);
  const outcome = await send(client, extraction.posting);

  if (worthRetrying(outcome)) {
    await defer(entry.id, now);
  } else {
    await forget(entry.id, now);
  }

  // A backend that has just answered is a backend the rest of the queue can be
  // offered to.
  if (landed(outcome)) {
    await drain(client, now);
  }

  return await report(outcome);
}

/**
 * Offer every capture whose wait is up. Called from the retry alarm, from a
 * fresh service worker, and after a capture that landed.
 */
export async function drainQueue(now: number = Date.now()): Promise<void> {
  const settings = await readSettings();
  const depth = await queueDepth();

  log('queue drain started', { depth, configured: settings !== null });

  if (settings === null || depth === 0) {
    await showBadge(null, depth);

    return;
  }

  await drain(createApiClient(settings.baseUrl, settings.token), now);
  await showBadge(null, await queueDepth());
}

async function drain(client: ApiClient, now: number): Promise<void> {
  for (const entry of await dueCaptures(now)) {
    const outcome = await send(client, entry.capture);

    const depth = worthRetrying(outcome)
      ? await defer(entry.id, now)
      : await forget(entry.id, now);

    log('retry result', {
      captureId: entry.id,
      attempts: entry.attempts + 1,
      outcome,
      depth,
    });
  }
}

async function send(client: ApiClient, capture: PostingCapture): Promise<CaptureOutcome> {
  try {
    const posting = await client.capturePosting(capture);

    return posting.isNew ? 'saved' : 'already-saved';
  } catch (thrown) {
    if (!(thrown instanceof CaptureFailed)) {
      throw thrown;
    }

    switch (thrown.reason) {
      case 'unreachable':
        return 'unreachable';
      case 'token-rejected':
        return 'token-rejected';
      case 'too-large':
        return 'too-large';
      case 'rejected':
        return 'rejected';
      default:
        return 'backend-error';
    }
  }
}

async function extract(tabId: number): Promise<PageExtraction> {
  try {
    await chrome.scripting.executeScript({ target: { tabId }, files: [EXTRACT_FILE] });

    const [injected] = await chrome.scripting.executeScript({
      target: { tabId },
      func: callExtractor,
    });

    const result: unknown = injected?.result;

    if (typeof result === 'object' && result !== null && 'ok' in result) {
      return result as PageExtraction;
    }

    log('extraction failed', { reason: 'no-result' });

    return { ok: false };
  } catch {
    // A page the extension may not read — a Chrome page, the web store, a PDF —
    // refuses injection. Nothing was found on it either way, and the message is
    // not worth logging: it carries the page's URL.
    log('extraction failed', { reason: 'injection-refused' });

    return { ok: false };
  }
}

/**
 * Serialised to source by `executeScript`, so it closes over nothing: the name
 * the injected file publishes is written out here rather than imported.
 */
function callExtractor(): unknown {
  const extractor = (globalThis as unknown as Record<string, unknown>)['__cvTailorExtract'];

  return typeof extractor === 'function' ? (extractor as () => unknown)() : undefined;
}

async function report(outcome: CaptureOutcome): Promise<CaptureOutcome> {
  const depth = await queueDepth();

  log('capture finished', { outcome, depth });

  await showBadge(outcome, depth);

  return outcome;
}
