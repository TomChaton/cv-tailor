import { describe, expect, it } from 'vitest';

import { logLine } from '../src/log';

describe('logLine', () => {
  it('prefixes every line so the service worker console can be filtered', () => {
    expect(logLine('service worker started')).toBe('[cv-tailor] service worker started');
  });

  it('carries detail as JSON after the step', () => {
    expect(logLine('extraction finished', { characters: 1200, hiddenText: false })).toBe(
      '[cv-tailor] extraction finished {"characters":1200,"hiddenText":false}',
    );
  });
});
