import { log } from '../log';

import type { PostingCapture } from '../api/client';

/**
 * Captures waiting to reach the backend.
 *
 * A capture is written here *before* it is sent and removed once it lands, so
 * Chrome stopping the service worker mid-send cannot lose one. Capture is
 * idempotent on the backend — a posting it already holds answers with that
 * posting rather than a second copy — which is what makes sending something
 * twice safe, and therefore what makes this queue safe.
 *
 * The queue is the only state capture keeps, and it lives in
 * `chrome.storage.local`: the service worker holds nothing in memory between
 * events because Chrome stops it whenever it likes.
 */
export interface QueuedCapture {
  id: string;
  capture: PostingCapture;
  /** Sends made so far. One means the first send has been tried and failed. */
  attempts: number;
  nextAttemptAt: number;
}

const QUEUE_KEY = 'capture-queue';

/** Named here because the service worker routes the alarm to the drain. */
export const RETRY_ALARM = 'capture-retry';

const FIRST_BACKOFF_MS = 60_000;
const MAX_BACKOFF_MS = 3_600_000;

/**
 * Doubling from a minute to an hour. A minute is below the shortest wait worth
 * taking for a backend that has just refused to answer, and an hour is the
 * point past which waiting longer gains nothing: the owner reviews captures
 * later anyway, and the badge says how many are outstanding.
 */
export function backoffMs(attempts: number): number {
  return Math.min(FIRST_BACKOFF_MS * 2 ** Math.max(0, attempts - 1), MAX_BACKOFF_MS);
}

export async function readQueue(): Promise<QueuedCapture[]> {
  const stored = await chrome.storage.local.get(QUEUE_KEY);
  const queue: unknown = stored[QUEUE_KEY];

  return Array.isArray(queue) ? (queue as QueuedCapture[]) : [];
}

export async function queueDepth(): Promise<number> {
  return (await readQueue()).length;
}

export async function enqueue(capture: PostingCapture, now: number): Promise<QueuedCapture> {
  const entry: QueuedCapture = {
    id: crypto.randomUUID(),
    capture,
    attempts: 0,
    nextAttemptAt: now,
  };

  const queue = [...(await readQueue()), entry];

  await persist(queue, now);

  log('capture queued', { captureId: entry.id, depth: queue.length });

  return entry;
}

export async function forget(id: string, now: number): Promise<number> {
  const queue = (await readQueue()).filter((entry) => entry.id !== id);

  await persist(queue, now);

  return queue.length;
}

/** Counts the attempt that just failed and sets when the next one may run. */
export async function defer(id: string, now: number): Promise<number> {
  const queue = (await readQueue()).map((entry) =>
    entry.id === id
      ? {
          ...entry,
          attempts: entry.attempts + 1,
          nextAttemptAt: now + backoffMs(entry.attempts + 1),
        }
      : entry,
  );

  await persist(queue, now);

  const deferred = queue.find((entry) => entry.id === id);

  log('retry queued', {
    captureId: id,
    attempts: deferred?.attempts ?? 0,
    inSeconds: Math.round(((deferred?.nextAttemptAt ?? now) - now) / 1000),
    depth: queue.length,
  });

  return queue.length;
}

export async function dueCaptures(now: number): Promise<QueuedCapture[]> {
  return (await readQueue()).filter((entry) => entry.nextAttemptAt <= now);
}

async function persist(queue: readonly QueuedCapture[], now: number): Promise<void> {
  await chrome.storage.local.set({ [QUEUE_KEY]: queue });
  await scheduleNextAttempt(queue, now);
}

/**
 * One alarm for the whole queue, set for the earliest attempt due. An alarm is
 * what wakes a stopped service worker, so without it a queued capture waits for
 * the next time the owner happens to capture something.
 */
async function scheduleNextAttempt(queue: readonly QueuedCapture[], now: number): Promise<void> {
  if (queue.length === 0) {
    await chrome.alarms.clear(RETRY_ALARM);

    return;
  }

  const when = Math.min(...queue.map((entry) => entry.nextAttemptAt));

  await chrome.alarms.create(RETRY_ALARM, { when: Math.max(when, now + 1000) });
}
