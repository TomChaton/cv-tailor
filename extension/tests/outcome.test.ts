import { describe, expect, it } from 'vitest';

import { landed, messageKeyOf, worthRetrying } from '../src/capture/outcome';

import { catalogueKeys, messageFor } from './fake-chrome';

import type { CaptureOutcome } from '../src/capture/outcome';

const OUTCOMES: CaptureOutcome[] = [
  'saved',
  'already-saved',
  'not-configured',
  'page-unreadable',
  'unreachable',
  'token-rejected',
  'backend-error',
  'too-large',
  'rejected',
];

describe('what capture says', () => {
  it('has a sentence in the catalogue for every outcome', () => {
    for (const outcome of OUTCOMES) {
      expect(messageFor(messageKeyOf(outcome)), outcome).toBeTypeOf('string');
    }
  });

  it('says each thing once: no two outcomes share a sentence', () => {
    const sentences = OUTCOMES.map((outcome) => messageFor(messageKeyOf(outcome)));

    expect(new Set(sentences).size).toBe(OUTCOMES.length);
  });

  it('keeps every word it shows in the catalogue', () => {
    for (const key of ['captureKept', 'popupSettings', 'badgeLanded', 'badgeRefused']) {
      expect(catalogueKeys(), key).toContain(key);
    }
  });

  /**
   * Whether a page holds a job description is the backend's judgement, so
   * nothing capture says is an answer to that question.
   */
  it('has nothing to say about what is on a page', () => {
    expect(OUTCOMES).not.toContain('nothing-found');
    expect(catalogueKeys()).not.toContain('captureNothingFound');
  });

  it('counts only a posting the backend holds as landed', () => {
    expect(landed('saved')).toBe(true);
    expect(landed('already-saved')).toBe(true);
    expect(landed('unreachable')).toBe(false);
  });

  it('keeps retrying only what a later attempt could still save', () => {
    expect(OUTCOMES.filter(worthRetrying)).toEqual([
      'unreachable',
      'token-rejected',
      'backend-error',
    ]);
  });
});
