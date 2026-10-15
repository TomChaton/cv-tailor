import { isAskedFor } from './capture/asks';

import type { AskedFor } from './api/client';

/**
 * What the popup asks the service worker for. The popup is where the owner
 * chooses between the three actions, and the capture itself happens in the
 * worker: the popup closes the moment the owner clicks away, and a capture must
 * not close with it.
 */
export interface CaptureRequest {
  type: 'capture';
  tabId: number;
  /** Which of the three the owner clicked. It travels with the capture. */
  askedFor: AskedFor;
}

export function isCaptureRequest(message: unknown): message is CaptureRequest {
  return (
    typeof message === 'object' &&
    message !== null &&
    (message as { type?: unknown }).type === 'capture' &&
    typeof (message as { tabId?: unknown }).tabId === 'number' &&
    isAskedFor((message as { askedFor?: unknown }).askedFor)
  );
}
