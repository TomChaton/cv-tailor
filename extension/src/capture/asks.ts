import type { AskedFor } from '../api/client';

/**
 * The three things the owner can ask for, each with the button that asks for it
 * and the entry in `_locales` that labels it.
 *
 * Keyed by the contract's own type, so the popup's buttons are the backend's
 * list of choices rather than a second one kept in step by hand: a choice the
 * backend adds or renames stops this compiling.
 */
export interface Ask {
  elementId: string;
  message: string;
}

export const ASKS: Record<AskedFor, Ask> = {
  save: { elementId: 'save', message: 'popupSave' },
  cv: { elementId: 'cv', message: 'popupCv' },
  cv_and_cover_letter: { elementId: 'cover-letter', message: 'popupCvAndCoverLetter' },
};

/** Asserted because `Object.entries` loses a record's key type. */
export function asks(): [AskedFor, Ask][] {
  return Object.entries(ASKS) as [AskedFor, Ask][];
}

/**
 * A message from the popup crosses `chrome.runtime`, which types nothing, so
 * what arrives is checked against the choices rather than trusted.
 */
export function isAskedFor(value: unknown): value is AskedFor {
  return typeof value === 'string' && Object.hasOwn(ASKS, value);
}
