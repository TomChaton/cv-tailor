import { landed } from './outcome';

import type { CaptureOutcome } from './outcome';

/**
 * What the toolbar button says once the popup has gone.
 *
 * Captures waiting to be sent are the one thing the owner cannot find out any
 * other way, so a non-empty queue shows its depth and takes precedence. With
 * nothing outstanding the badge carries the last outcome: a mark for a capture
 * that landed, and one for a capture that needs the owner.
 */
const WAITING = '#9a6700';
const LANDED = '#1a7f37';
const REFUSED = '#b42318';

export async function showBadge(outcome: CaptureOutcome | null, depth: number): Promise<void> {
  if (depth > 0) {
    await set(String(depth), WAITING);

    return;
  }

  if (outcome === null) {
    await set('', LANDED);

    return;
  }

  if (landed(outcome)) {
    await set(chrome.i18n.getMessage('badgeLanded'), LANDED);

    return;
  }

  await set(chrome.i18n.getMessage('badgeRefused'), REFUSED);
}

async function set(text: string, colour: string): Promise<void> {
  await chrome.action.setBadgeBackgroundColor({ color: colour });
  await chrome.action.setBadgeTextColor({ color: '#ffffff' });
  await chrome.action.setBadgeText({ text });
}
