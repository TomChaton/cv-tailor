import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { captureTab, drainQueue } from '../src/capture/capture';
import { dueCaptures, readQueue } from '../src/capture/queue';
import { messageKeyOf } from '../src/capture/outcome';

import { installFakeChrome, messageFor } from './fake-chrome';

import type { PageRead } from '../src/capture/read-page-script';
import type { FakeChrome } from './fake-chrome';

const NOW = 1_760_000_000_000;
const TAB = 17;

const pageRead: PageRead = {
  posting: {
    url: 'https://jobs.example.com/vacancy/7781',
    source_site: 'jobs.example.com',
    page_text: 'All jobs. Senior PHP Engineer at Northwind Systems. A job description.',
    json_ld: { '@type': 'JobPosting', title: 'Senior PHP Engineer' },
    has_hidden_text: false,
  },
  characters: 69,
  hiddenCharacters: 0,
  hasJsonLd: true,
};

let fake: FakeChrome;

function answer(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function stubFetch(...answers: (Response | Error)[]): ReturnType<typeof vi.fn> {
  const fetch = vi.fn(() => {
    const next = answers.length > 1 ? answers.shift()! : answers[0]!;

    return next instanceof Error ? Promise.reject(next) : Promise.resolve(next.clone());
  });

  vi.stubGlobal('fetch', fetch);

  return fetch;
}

async function configure(): Promise<void> {
  fake.store['settings'] = { baseUrl: 'https://cv-tailor.example.net', token: 'token-abc' };
}

beforeEach(() => {
  fake = installFakeChrome();
  fake.pageRead = pageRead;
  vi.spyOn(console, 'log').mockImplementation(() => undefined);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('captureTab', () => {
  it('sends the page it read and reports it saved', async () => {
    await configure();
    stubFetch(answer(201, { id: 12, status: 'captured' }));

    expect(await captureTab(TAB, 'save', NOW)).toBe('saved');
    expect(await readQueue()).toHaveLength(0);
    expect(fake.badge.text).toBe(messageFor('badgeLanded'));
  });

  it('reports a posting the backend already held as already saved, not as saved', async () => {
    await configure();
    stubFetch(answer(200, { id: 12, status: 'reviewed' }));

    const outcome = await captureTab(TAB, 'save', NOW);

    expect(outcome).toBe('already-saved');
    expect(messageKeyOf(outcome)).toBe('captureAlreadySaved');
    expect(messageFor(messageKeyOf(outcome))).toBe('Already saved');
    expect(messageFor(messageKeyOf(outcome))).not.toBe(messageFor('captureSaved'));
  });

  it('injects the page reader into the one tab the owner acted on', async () => {
    await configure();
    stubFetch(answer(201, { id: 12, status: 'captured' }));

    await captureTab(TAB, 'save', NOW);

    expect(fake.injections).toEqual([
      { files: ['read-page.js'], hasFunc: false },
      { files: undefined, hasFunc: true },
    ]);
  });

  it('sends the page whole, with whatever the page published about itself', async () => {
    await configure();

    const fetch = stubFetch(answer(201, { id: 12, status: 'captured' }));

    await captureTab(TAB, 'save', NOW);

    const request = fetch.mock.calls[0]![0] as Request;

    expect(await request.json()).toEqual({ ...pageRead.posting, asked_for: 'save' });
  });

  /**
   * The ask is the one thing in a capture the page did not say. Which of the
   * three was clicked is the owner's decision, and the backend acts on it.
   */
  it.each(['save', 'cv', 'cv_and_cover_letter'] as const)(
    'sends what the owner asked for: %s',
    async (askedFor) => {
      await configure();

      const fetch = stubFetch(answer(201, { id: 12, status: 'captured' }));

      expect(await captureTab(TAB, askedFor, NOW)).toBe('saved');

      const request = fetch.mock.calls[0]![0] as Request;

      expect(await request.json()).toMatchObject({ asked_for: askedFor });
    },
  );

  it('stops before reading the page when nothing is configured', async () => {
    expect(await captureTab(TAB, 'save', NOW)).toBe('not-configured');
    expect(fake.injections).toHaveLength(0);
  });

  /**
   * A Chrome page, the web store or a PDF refuses injection. That is a page the
   * extension may not touch rather than a page holding nothing.
   */
  it('reports a page it may not read as unreadable', async () => {
    await configure();
    fake.injectionRefused = true;

    expect(await captureTab(TAB, 'save', NOW)).toBe('page-unreadable');
    expect(await readQueue()).toHaveLength(0);
  });

  /**
   * There is no floor and no judgement about what a page holds, so a page with
   * one line on it is sent like any other.
   */
  it('sends a page whose text is one line', async () => {
    await configure();
    fake.pageRead = {
      posting: {
        url: 'https://jobs.example.com/vacancy/7781',
        source_site: 'jobs.example.com',
        page_text: 'Senior PHP Engineer, Leeds.',
        has_hidden_text: false,
      },
      characters: 27,
      hiddenCharacters: 0,
      hasJsonLd: false,
    } satisfies PageRead;

    stubFetch(answer(201, { id: 12, status: 'captured' }));

    expect(await captureTab(TAB, 'save', NOW)).toBe('saved');
  });

  /**
   * The one capture with nothing to send. Not a judgement about what the page
   * holds — it is the read itself that came back empty.
   */
  it('sends nothing for a page that read as blank, and keeps nothing', async () => {
    await configure();
    fake.pageRead = {
      posting: {
        url: 'https://jobs.example.com/vacancy/7781',
        source_site: 'jobs.example.com',
        page_text: '',
        has_hidden_text: true,
      },
      characters: 0,
      hiddenCharacters: 2_481,
      hasJsonLd: false,
    } satisfies PageRead;

    const fetch = stubFetch(answer(201, { id: 12, status: 'captured' }));

    expect(await captureTab(TAB, 'save', NOW)).toBe('page-blank');
    expect(fetch).not.toHaveBeenCalled();
    expect(await readQueue()).toHaveLength(0);
    expect(fake.badge.text).toBe(messageFor('badgeRefused'));
  });

  it('keeps a capture the backend never answered and shows the queue depth', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    expect(await captureTab(TAB, 'save', NOW)).toBe('unreachable');

    const queue = await readQueue();

    expect(queue).toHaveLength(1);
    expect(queue[0]!.attempts).toBe(1);
    expect(fake.badge.text).toBe('1');
  });

  /**
   * A capture kept for retry has to ask for the same thing when it is finally
   * sent, or the owner's decision is quietly downgraded to a posting nobody
   * generates from.
   */
  it('keeps what the owner asked for with the capture it kept', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, 'cv_and_cover_letter', NOW);

    const queue = await readQueue();

    expect(queue[0]!.capture.asked_for).toBe('cv_and_cover_letter');
  });

  /**
   * The refusal is named rather than reported as the backend's own trouble,
   * which is the whole difference between a capture the owner can act on and
   * one that waits on somebody else. The capture is kept because the token is
   * the thing the owner replaces.
   */
  it('keeps a capture a rejected token stopped, because the token can be replaced', async () => {
    await configure();
    stubFetch(answer(401, { message: 'Unauthenticated.' }));

    const outcome = await captureTab(TAB, 'save', NOW);

    expect(outcome).toBe('token-rejected');
    expect(messageFor(messageKeyOf(outcome))).toBe('Token rejected');
    expect(await readQueue()).toHaveLength(1);
  });

  /**
   * A 5xx is the backend's own to recover from and says nothing the owner can
   * act on, so it is not the same answer as a refused token.
   */
  it('tells a refused token from a backend that broke', async () => {
    await configure();
    stubFetch(answer(500, { message: 'Server Error' }));

    const outcome = await captureTab(TAB, 'save', NOW);

    expect(outcome).toBe('backend-error');
    expect(messageFor(messageKeyOf(outcome))).not.toBe('Token rejected');
  });

  it('drops a capture the backend will never accept', async () => {
    await configure();
    stubFetch(
      answer(422, { message: 'The url field must be a valid URL.', errors: { url: ['bad'] } }),
    );

    expect(await captureTab(TAB, 'save', NOW)).toBe('rejected');
    expect(await readQueue()).toHaveLength(0);
  });

  it('offers the rest of the queue to a backend that has just answered', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, 'save', NOW);

    stubFetch(answer(201, { id: 12, status: 'captured' }));

    // Far enough past the first failure for the backoff to be up.
    expect(await captureTab(TAB, 'save', NOW + 120_000)).toBe('saved');
    expect(await readQueue()).toHaveLength(0);
    expect(fake.badge.text).toBe(messageFor('badgeLanded'));
  });
});

describe('drainQueue', () => {
  /**
   * The retry asks for what the owner asked for, however long it waited.
   */
  it('sends a kept capture asking for the same thing it asked for first', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, 'cv', NOW);

    const fetch = stubFetch(answer(201, { id: 12, status: 'wanted' }));

    await drainQueue(NOW + 61_000);

    const request = fetch.mock.calls[0]![0] as Request;

    expect(await request.json()).toMatchObject({ asked_for: 'cv' });
    expect(await readQueue()).toHaveLength(0);
  });

  it('retries a kept capture once its backoff is up, and clears the badge', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, 'save', NOW);

    stubFetch(answer(201, { id: 12, status: 'captured' }));

    await drainQueue(NOW + 61_000);

    expect(await readQueue()).toHaveLength(0);
    expect(fake.badge.text).toBe('');
  });

  it('leaves a capture alone until its backoff is up', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, 'save', NOW);

    const fetch = stubFetch(answer(201, { id: 12, status: 'captured' }));

    await drainQueue(NOW + 30_000);

    expect(fetch).not.toHaveBeenCalled();
    expect(await readQueue()).toHaveLength(1);
  });

  it('lengthens the wait each time an attempt fails', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, 'save', NOW);
    await drainQueue(NOW + 61_000);

    const queue = await readQueue();

    expect(queue[0]!.attempts).toBe(2);
    expect(await dueCaptures(NOW + 61_000 + 119_000)).toHaveLength(0);
    expect(await dueCaptures(NOW + 61_000 + 121_000)).toHaveLength(1);
  });

  it('retries a capture kept by a service worker that has since been stopped', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, 'save', NOW);

    // A restart is a fresh set of modules over the same storage: nothing the
    // capture needs was held in memory.
    vi.resetModules();

    const restarted = await import('../src/capture/capture');

    stubFetch(answer(201, { id: 12, status: 'captured' }));

    await restarted.drainQueue(NOW + 61_000);

    expect(await readQueue()).toHaveLength(0);
  });
});
