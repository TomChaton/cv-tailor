/**
 * What the popup asks the service worker for. The popup opens on a click or on
 * the keyboard shortcut and closes again whenever it likes, so the work happens
 * in the worker and the popup only reports the answer.
 */
export interface CaptureRequest {
  type: 'capture';
  tabId: number;
}

export function isCaptureRequest(message: unknown): message is CaptureRequest {
  return (
    typeof message === 'object' &&
    message !== null &&
    (message as { type?: unknown }).type === 'capture' &&
    typeof (message as { tabId?: unknown }).tabId === 'number'
  );
}
