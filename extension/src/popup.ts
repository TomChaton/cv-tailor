import { asks, ASKS } from './capture/asks';
import { messageKeyOf, worthRetrying } from './capture/outcome';
import { log } from './log';

import type { AskedFor } from './api/client';
import type { CaptureOutcome } from './capture/outcome';
import type { CaptureRequest } from './messages';

const actions = element('actions');
const outcomeLine = element('outcome');
const keptLine = element('kept');
const settingsButton = element('settings');

settingsButton.textContent = chrome.i18n.getMessage('popupSettings');
settingsButton.addEventListener('click', () => void chrome.runtime.openOptionsPage());

keptLine.textContent = chrome.i18n.getMessage('captureKept');

// Nothing is captured until one of these is clicked. The owner is on the job
// board and knows whether the posting is worth a CV, so the popup asks rather
// than capturing the moment it opens.
for (const [askedFor, ask] of asks()) {
  const action = button(ask.elementId);

  action.textContent = chrome.i18n.getMessage(ask.message);
  action.addEventListener('click', () => void capture(askedFor));
}

async function capture(askedFor: AskedFor): Promise<void> {
  log('action clicked', { askedFor });

  // The buttons stay on screen until there is something to say instead of
  // them, and take no second click while the worker is still working.
  for (const ask of Object.values(ASKS)) {
    button(ask.elementId).disabled = true;
  }

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });

  const outcome =
    tab?.id === undefined
      ? 'page-unreadable'
      : await chrome.runtime.sendMessage<CaptureRequest, CaptureOutcome>({
          type: 'capture',
          tabId: tab.id,
          askedFor,
        });

  log('popup showed outcome', { outcome });

  // One line, whichever button was clicked: saved and already saved are what
  // the owner needs told, and neither becomes two sentences because there
  // were three buttons.
  actions.hidden = true;
  outcomeLine.hidden = false;
  outcomeLine.textContent = chrome.i18n.getMessage(messageKeyOf(outcome));
  keptLine.hidden = !worthRetrying(outcome);
  settingsButton.hidden = outcome !== 'not-configured';
}

function button(id: string): HTMLButtonElement {
  return element(id) as HTMLButtonElement;
}

function element(id: string): HTMLElement {
  const found = document.getElementById(id);

  if (found === null) {
    throw new Error(`missing element: ${id}`);
  }

  return found;
}
