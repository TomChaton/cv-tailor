import { CaptureFailed, createApiClient } from '../api/client';
import { log } from '../log';
import { readSettings } from '../settings';

import { showBadge } from './badge';
import { landed, worthRetrying } from './outcome';
import { defer, dueCaptures, enqueue, forget, queueDepth } from './queue';

import type { ApiClient, AskedFor, PostingCapture } from '../api/client';
import type { CaptureOutcome } from './outcome';
import type { PageRead } from './read-page-script';

/** Built by the bundler from `read-page-script.ts`. */
const READ_PAGE_FILE = 'read-page.js';

/**
 * Capture the page on one tab, asking the backend for what the owner clicked.
 *
 * The capture is queued before it is sent and removed once it lands, so a
 * capture is never lost — the worst case is one the owner is told was kept.
 *
 * Nothing here judges what is on the page. Every page the owner captures is
 * sent, and what it advertises is the backend's answer.
 */
export async function captureTab(
  tabId: number,
  askedFor: AskedFor,
  now: number = Date.now(),
): Promise<CaptureOutcome> {
  log('capture started', { askedFor });

  const settings = await readSettings();

  if (settings === null) {
    log('capture stopped', { reason: 'not-configured' });

    return await report('not-configured');
  }

  const read = await readPage(tabId);

  if (read === null) {
    return await report('page-unreadable');
  }

  log('page read result', {
    characters: read.characters,
    jsonLd: read.hasJsonLd,
    hiddenText: read.posting.has_hidden_text,
    hiddenCharacters: read.hiddenCharacters,
  });

  // A page that read as no text at all is the one case with nothing to send.
  // Not a judgement about what the page holds — the backend still makes that —
  // but a capture of nothing is a capture the backend can only refuse.
  if (read.posting.page_text === '') {
    return await report('page-blank');
  }

  // The ask is the one thing in a capture the page did not say, and it is kept
  // with the capture rather than beside it: a capture the backend never
  // answered is retried from the queue, and it has to ask for the same thing
  // then as it did now, or the owner's decision is quietly downgraded.
  const posting: PostingCapture = { ...read.posting, asked_for: askedFor };

  const client = createApiClient(settings.baseUrl, settings.token);
  const entry = await enqueue(posting, now);
  const outcome = await send(client, posting);

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

/**
 * Null where the page could not be read at all — a Chrome page, the web store,
 * a PDF. That is a page this extension may not touch rather than a page holding
 * nothing, and it is the one thing a capture can still fail at before the
 * backend is involved.
 */
async function readPage(tabId: number): Promise<PageRead | null> {
  try {
    await chrome.scripting.executeScript({ target: { tabId }, files: [READ_PAGE_FILE] });

    const [injected] = await chrome.scripting.executeScript({
      target: { tabId },
      func: callPageReader,
    });

    const result: unknown = injected?.result;

    if (typeof result === 'object' && result !== null && 'posting' in result) {
      return result as PageRead;
    }

    log('page read failed', { reason: 'no-result' });

    return null;
  } catch {
    // The thrown message is not worth logging: it carries the page's URL.
    log('page read failed', { reason: 'injection-refused' });

    return null;
  }
}

/**
 * Serialised to source by `executeScript`, so it closes over nothing: the name
 * the injected file publishes is written out here rather than imported.
 */
function callPageReader(): unknown {
  const reader = (globalThis as unknown as Record<string, unknown>)['__cvTailorReadPage'];

  return typeof reader === 'function' ? (reader as () => unknown)() : undefined;
}

async function report(outcome: CaptureOutcome): Promise<CaptureOutcome> {
  const depth = await queueDepth();

  log('capture finished', { outcome, depth });

  await showBadge(outcome, depth);

  return outcome;
}
