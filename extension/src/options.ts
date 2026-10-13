import { log } from './log';
import { backendOriginOf, hostPermissionOf, readSettings, writeSettings } from './settings';

const form = document.getElementById('settings') as HTMLFormElement;
const urlField = document.getElementById('base-url') as HTMLInputElement;
const tokenField = document.getElementById('token') as HTMLInputElement;
const outcomeLine = document.getElementById('outcome') as HTMLElement;

document.title = chrome.i18n.getMessage('extensionName');
label('base-url-label', 'optionsBackendUrl');
label('token-label', 'optionsToken');
label('save', 'optionsSave');

void fill();

form.addEventListener('submit', (submission) => {
  submission.preventDefault();
  void save();
});

async function fill(): Promise<void> {
  const settings = await readSettings();

  if (settings !== null) {
    urlField.value = settings.baseUrl;
    tokenField.value = settings.token;
  }
}

async function save(): Promise<void> {
  const entered = urlField.value;
  const baseUrl = backendOriginOf(entered);
  const origin = hostPermissionOf(entered);

  if (baseUrl === null || origin === null) {
    say('optionsUrlInvalid');

    return;
  }

  // The manifest cannot know this origin, so it is asked for here — first,
  // before anything else is awaited, because Chrome only grants an optional
  // permission while the click that asked for it is still in hand.
  const granted = await chrome.permissions.request({ origins: [origin] });

  log('host permission requested', { origin, granted });

  if (!granted) {
    say('optionsPermissionRefused');

    return;
  }

  const previous = await readSettings();

  await writeSettings({ baseUrl, token: tokenField.value });

  await dropStaleOrigin(previous?.baseUrl, origin);

  say('optionsSaved');
}

/**
 * The granted set stays exactly the configured backend: a host the extension
 * has stopped pointing at is a host it has no business being able to read.
 */
async function dropStaleOrigin(previousBaseUrl: string | undefined, origin: string): Promise<void> {
  if (previousBaseUrl === undefined) {
    return;
  }

  const stale = hostPermissionOf(previousBaseUrl);

  if (stale === null || stale === origin) {
    return;
  }

  const removed = await chrome.permissions.remove({ origins: [stale] });

  log('host permission dropped', { origin: stale, removed });
}

function label(id: string, message: string): void {
  const target = document.getElementById(id);

  if (target !== null) {
    target.textContent = chrome.i18n.getMessage(message);
  }
}

function say(message: string): void {
  outcomeLine.textContent = chrome.i18n.getMessage(message);
}
