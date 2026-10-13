import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RETRY_ALARM, backoffMs, defer, dueCaptures, enqueue, forget } from '../src/capture/queue';

import { installFakeChrome } from './fake-chrome';

import type { PostingCapture } from '../src/api/client';
import type { FakeChrome } from './fake-chrome';

const NOW = 1_760_000_000_000;

const capture: PostingCapture = {
  url: 'https://jobs.example.com/vacancy/7781',
  source_site: 'jobs.example.com',
  extracted_text: 'A job description long enough to be worth keeping.',
  raw_html: '<div id="description"><p>A job description.</p></div>',
  has_hidden_text: false,
};

let fake: FakeChrome;

beforeEach(() => {
  fake = installFakeChrome();
  vi.spyOn(console, 'log').mockImplementation(() => undefined);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('backoffMs', () => {
  it('doubles from a minute and stops at an hour', () => {
    expect(backoffMs(1)).toBe(60_000);
    expect(backoffMs(2)).toBe(120_000);
    expect(backoffMs(3)).toBe(240_000);
    expect(backoffMs(99)).toBe(3_600_000);
  });
});

describe('the capture queue', () => {
  it('holds a capture that is due at once', async () => {
    await enqueue(capture, NOW);

    expect(await dueCaptures(NOW)).toHaveLength(1);
  });

  it('puts a deferred capture beyond reach until its backoff is up', async () => {
    const entry = await enqueue(capture, NOW);

    await defer(entry.id, NOW);

    expect(await dueCaptures(NOW)).toHaveLength(0);
    expect(await dueCaptures(NOW + 59_000)).toHaveLength(0);
    expect(await dueCaptures(NOW + 61_000)).toHaveLength(1);
  });

  it('wakes a stopped service worker with one alarm for the earliest attempt', async () => {
    const entry = await enqueue(capture, NOW);

    await defer(entry.id, NOW);

    expect(fake.alarms.at(-1)).toEqual({ name: RETRY_ALARM, when: NOW + 60_000 });
  });

  it('clears the alarm once nothing is waiting', async () => {
    const entry = await enqueue(capture, NOW);

    await forget(entry.id, NOW);

    expect(fake.clearedAlarms).toContain(RETRY_ALARM);
    expect(await dueCaptures(NOW)).toHaveLength(0);
  });

  it('survives the service worker being stopped, because it is not in memory', async () => {
    await enqueue(capture, NOW);

    // A restart is a fresh set of modules over the same storage.
    vi.resetModules();

    const restarted = await import('../src/capture/queue');

    expect(await restarted.dueCaptures(NOW)).toHaveLength(1);
  });
});
