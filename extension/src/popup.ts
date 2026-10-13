import { messageKeyOf, worthRetrying } from './capture/outcome';
import { log } from './log';

import type { CaptureRequest } from './messages';
import type { CaptureOutcome } from './capture/outcome';

const outcomeLine = element('outcome');
const keptLine = element('kept');
const settingsButton = element('settings');

settingsButton.textContent = chrome.i18n.getMessage('popupSettings');
settingsButton.addEventListener('click', () => void chrome.runtime.openOptionsPage());

keptLine.textContent = chrome.i18n.getMessage('captureKept');

void capture();

async function capture(): Promise<void> {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });

  const outcome =
    tab?.id === undefined
      ? 'nothing-found'
      : await chrome.runtime.sendMessage<CaptureRequest, CaptureOutcome>({
          type: 'capture',
          tabId: tab.id,
        });

  log('popup showed outcome', { outcome });

  outcomeLine.textContent = chrome.i18n.getMessage(messageKeyOf(outcome));
  keptLine.hidden = !worthRetrying(outcome);
  settingsButton.hidden = outcome !== 'not-configured';
}

function element(id: string): HTMLElement {
  const found = document.getElementById(id);

  if (found === null) {
    throw new Error(`missing element: ${id}`);
  }

  return found;
}
