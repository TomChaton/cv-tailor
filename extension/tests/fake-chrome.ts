import { vi } from 'vitest';

import messages from '../_locales/en_GB/messages.json';

const catalogue = messages as Record<string, { message: string }>;

export interface FakeChrome {
  /** What `chrome.storage.local` holds. Survives a simulated worker restart. */
  store: Record<string, unknown>;
  alarms: { name: string; when: number }[];
  clearedAlarms: string[];
  badge: { text: string | null; background: string | null };
  injections: { files?: string[]; hasFunc: boolean }[];
  /** What the injected page reader hands back. */
  pageRead: unknown;
  /** Set to make injection fail, as a page the extension may not read does. */
  injectionRefused: boolean;
  permissionGranted: boolean;
  requestedOrigins: string[][];
  removedOrigins: string[][];
  optionsPageOpened: number;
}

export function installFakeChrome(): FakeChrome {
  const fake: FakeChrome = {
    store: {},
    alarms: [],
    clearedAlarms: [],
    badge: { text: null, background: null },
    injections: [],
    pageRead: undefined,
    injectionRefused: false,
    permissionGranted: true,
    requestedOrigins: [],
    removedOrigins: [],
    optionsPageOpened: 0,
  };

  const api = {
    storage: {
      local: {
        get: (key: string) => Promise.resolve({ [key]: fake.store[key] }),
        set: (items: Record<string, unknown>) => {
          Object.assign(fake.store, structuredClone(items));

          return Promise.resolve();
        },
      },
    },
    alarms: {
      create: (name: string, info: { when: number }) => {
        fake.alarms.push({ name, when: info.when });

        return Promise.resolve();
      },
      clear: (name: string) => {
        fake.clearedAlarms.push(name);

        return Promise.resolve(true);
      },
    },
    action: {
      setBadgeText: (details: { text: string }) => {
        fake.badge.text = details.text;

        return Promise.resolve();
      },
      setBadgeBackgroundColor: (details: { color: string }) => {
        fake.badge.background = details.color;

        return Promise.resolve();
      },
      setBadgeTextColor: () => Promise.resolve(),
    },
    scripting: {
      executeScript: (injection: { files?: string[]; func?: unknown }) => {
        if (fake.injectionRefused) {
          return Promise.reject(new Error('Cannot access contents of the page'));
        }

        fake.injections.push({ files: injection.files, hasFunc: injection.func !== undefined });

        return Promise.resolve(
          injection.func === undefined ? [{ result: null }] : [{ result: fake.pageRead }],
        );
      },
    },
    permissions: {
      request: (details: { origins: string[] }) => {
        fake.requestedOrigins.push(details.origins);

        return Promise.resolve(fake.permissionGranted);
      },
      remove: (details: { origins: string[] }) => {
        fake.removedOrigins.push(details.origins);

        return Promise.resolve(true);
      },
    },
    i18n: {
      getMessage: (key: string) => catalogue[key]?.message ?? '',
    },
    runtime: {
      openOptionsPage: () => {
        fake.optionsPageOpened += 1;

        return Promise.resolve();
      },
    },
  };

  vi.stubGlobal('chrome', api);

  return fake;
}

export function messageFor(key: string): string | undefined {
  return catalogue[key]?.message;
}

export function catalogueKeys(): string[] {
  return Object.keys(catalogue);
}
