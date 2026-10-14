/**
 * What capture has to say. The popup turns one of these into a sentence and the
 * badge into a mark, both from `_locales`, so the outcome itself carries no
 * words.
 *
 * There is no "nothing found": whether a page holds a job description is the
 * backend's judgement, and every page the owner captures is sent.
 * `page-unreadable` is a different fact — a page this extension may not touch.
 */
export type CaptureOutcome =
  | 'saved'
  | 'already-saved'
  | 'not-configured'
  | 'page-unreadable'
  | 'unreachable'
  | 'token-rejected'
  | 'backend-error'
  | 'too-large'
  | 'rejected';

const MESSAGES: Record<CaptureOutcome, string> = {
  saved: 'captureSaved',
  'already-saved': 'captureAlreadySaved',
  'not-configured': 'captureNotConfigured',
  'page-unreadable': 'capturePageUnreadable',
  unreachable: 'captureUnreachable',
  'token-rejected': 'captureTokenRejected',
  'backend-error': 'captureBackendError',
  'too-large': 'captureTooLarge',
  rejected: 'captureRejected',
};

/**
 * Outcomes a later attempt can still turn into a saved posting: a backend that
 * is asleep will answer eventually, a token that was refused can be replaced,
 * and an error on the backend's side is its own to recover from. The other
 * refusals are decided by the payload, which will never change, so retrying one
 * would queue it forever.
 */
const WORTH_RETRYING: ReadonlySet<CaptureOutcome> = new Set<CaptureOutcome>([
  'unreachable',
  'token-rejected',
  'backend-error',
]);

export function messageKeyOf(outcome: CaptureOutcome): string {
  return MESSAGES[outcome];
}

export function worthRetrying(outcome: CaptureOutcome): boolean {
  return WORTH_RETRYING.has(outcome);
}

export function landed(outcome: CaptureOutcome): boolean {
  return outcome === 'saved' || outcome === 'already-saved';
}
