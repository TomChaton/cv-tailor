import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { captureTab, drainQueue } from '../src/capture/capture';
import { dueCaptures, readQueue } from '../src/capture/queue';
import { messageKeyOf } from '../src/capture/outcome';

import { installFakeChrome, messageFor } from './fake-chrome';

import type { PageExtraction } from '../src/capture/extract-script';
import type { FakeChrome } from './fake-chrome';

const NOW = 1_760_000_000_000;
const TAB = 17;

const extraction: PageExtraction = {
  ok: true,
  extractor: 'readability',
  posting: {
    url: 'https://jobs.example.com/vacancy/7781',
    source_site: 'jobs.example.com',
    extracted_text: 'A job description long enough to be worth keeping.',
    raw_html: '<div id="description"><p>A job description.</p></div>',
    has_hidden_text: false,
    title: 'Senior PHP Engineer',
    company: 'Northwind Systems',
  },
  hiddenCharacters: 0,
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
  fake.extraction = extraction;
  vi.spyOn(console, 'log').mockImplementation(() => undefined);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('captureTab', () => {
  it('sends the extracted posting and reports it saved', async () => {
    await configure();
    stubFetch(answer(201, { id: 12, status: 'captured' }));

    expect(await captureTab(TAB, NOW)).toBe('saved');
    expect(await readQueue()).toHaveLength(0);
    expect(fake.badge.text).toBe(messageFor('badgeLanded'));
  });

  it('reports a posting the backend already held as already saved, not as saved', async () => {
    await configure();
    stubFetch(answer(200, { id: 12, status: 'reviewed' }));

    const outcome = await captureTab(TAB, NOW);

    expect(outcome).toBe('already-saved');
    expect(messageKeyOf(outcome)).toBe('captureAlreadySaved');
    expect(messageFor(messageKeyOf(outcome))).toBe('Already saved');
    expect(messageFor(messageKeyOf(outcome))).not.toBe(messageFor('captureSaved'));
  });

  it('injects the extractor into the one tab the owner acted on', async () => {
    await configure();
    stubFetch(answer(201, { id: 12, status: 'captured' }));

    await captureTab(TAB, NOW);

    expect(fake.injections).toEqual([
      { files: ['extract.js'], hasFunc: false },
      { files: undefined, hasFunc: true },
    ]);
  });

  it('stops before reading the page when nothing is configured', async () => {
    expect(await captureTab(TAB, NOW)).toBe('not-configured');
    expect(fake.injections).toHaveLength(0);
  });

  it('reports a page it cannot read as nothing found', async () => {
    await configure();
    fake.injectionRefused = true;

    expect(await captureTab(TAB, NOW)).toBe('nothing-found');
    expect(await readQueue()).toHaveLength(0);
  });

  it('keeps a capture the backend never answered and shows the queue depth', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    expect(await captureTab(TAB, NOW)).toBe('unreachable');

    const queue = await readQueue();

    expect(queue).toHaveLength(1);
    expect(queue[0]!.attempts).toBe(1);
    expect(fake.badge.text).toBe('1');
  });

  it('keeps a capture a rejected token stopped, because the token can be replaced', async () => {
    await configure();
    stubFetch(answer(401, { message: 'Unauthenticated.' }));

    expect(await captureTab(TAB, NOW)).toBe('token-rejected');
    expect(await readQueue()).toHaveLength(1);
  });

  it('drops a capture the backend will never accept', async () => {
    await configure();
    stubFetch(
      answer(422, { message: 'The url field must be a valid URL.', errors: { url: ['bad'] } }),
    );

    expect(await captureTab(TAB, NOW)).toBe('rejected');
    expect(await readQueue()).toHaveLength(0);
  });

  it('offers the rest of the queue to a backend that has just answered', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, NOW);

    stubFetch(answer(201, { id: 12, status: 'captured' }));

    // Far enough past the first failure for the backoff to be up.
    expect(await captureTab(TAB, NOW + 120_000)).toBe('saved');
    expect(await readQueue()).toHaveLength(0);
    expect(fake.badge.text).toBe(messageFor('badgeLanded'));
  });
});

describe('drainQueue', () => {
  it('retries a kept capture once its backoff is up, and clears the badge', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, NOW);

    stubFetch(answer(201, { id: 12, status: 'captured' }));

    await drainQueue(NOW + 61_000);

    expect(await readQueue()).toHaveLength(0);
    expect(fake.badge.text).toBe('');
  });

  it('leaves a capture alone until its backoff is up', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, NOW);

    const fetch = stubFetch(answer(201, { id: 12, status: 'captured' }));

    await drainQueue(NOW + 30_000);

    expect(fetch).not.toHaveBeenCalled();
    expect(await readQueue()).toHaveLength(1);
  });

  it('lengthens the wait each time an attempt fails', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, NOW);
    await drainQueue(NOW + 61_000);

    const queue = await readQueue();

    expect(queue[0]!.attempts).toBe(2);
    expect(await dueCaptures(NOW + 61_000 + 119_000)).toHaveLength(0);
    expect(await dueCaptures(NOW + 61_000 + 121_000)).toHaveLength(1);
  });

  it('retries a capture kept by a service worker that has since been stopped', async () => {
    await configure();
    stubFetch(new TypeError('Failed to fetch'));

    await captureTab(TAB, NOW);

    // A restart is a fresh set of modules over the same storage: nothing the
    // capture needs was held in memory.
    vi.resetModules();

    const restarted = await import('../src/capture/capture');

    stubFetch(answer(201, { id: 12, status: 'captured' }));

    await restarted.drainQueue(NOW + 61_000);

    expect(await readQueue()).toHaveLength(0);
  });
});
