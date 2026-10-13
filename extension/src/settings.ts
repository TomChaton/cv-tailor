import { log } from './log';

/**
 * Where the backend is and how to prove we may talk to it. Nothing about a
 * particular installation is built in, so both come from the options page and
 * live in `chrome.storage.local`.
 */
export interface Settings {
  baseUrl: string;
  token: string;
}

const SETTINGS_KEY = 'settings';

export async function readSettings(): Promise<Settings | null> {
  const stored = await chrome.storage.local.get(SETTINGS_KEY);
  const settings: unknown = stored[SETTINGS_KEY];

  if (typeof settings !== 'object' || settings === null) {
    return null;
  }

  const { baseUrl, token } = settings as Partial<Settings>;

  if (typeof baseUrl !== 'string' || baseUrl === '') {
    return null;
  }

  if (typeof token !== 'string' || token === '') {
    return null;
  }

  return { baseUrl, token };
}

export async function writeSettings(settings: Settings): Promise<void> {
  await chrome.storage.local.set({ [SETTINGS_KEY]: settings });

  log('settings saved', { host: new URL(settings.baseUrl).hostname });
}

/**
 * The origin the client will be pointed at, or null when what was typed is not
 * one. The path is dropped: the API lives at the root of its own instance, and
 * the generated client appends the whole path itself.
 */
export function backendOriginOf(entered: string): string | null {
  const url = parse(entered);

  return url === null ? null : url.origin;
}

/**
 * The host permission to ask for before saving. Chrome's match patterns carry
 * no port — one without a port matches every port on the host — so the port in
 * the backend's URL is deliberately absent here.
 */
export function hostPermissionOf(entered: string): string | null {
  const url = parse(entered);

  return url === null ? null : `${url.protocol}//${url.hostname}/*`;
}

function parse(entered: string): URL | null {
  let url: URL;

  try {
    url = new URL(entered.trim());
  } catch {
    return null;
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return null;
  }

  return url.hostname === '' ? null : url;
}
