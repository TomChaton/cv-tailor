import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { backendOriginOf, hostPermissionOf, readSettings, writeSettings } from '../src/settings';

import { installFakeChrome } from './fake-chrome';

import type { FakeChrome } from './fake-chrome';

let fake: FakeChrome;

beforeEach(() => {
  fake = installFakeChrome();
  vi.spyOn(console, 'log').mockImplementation(() => undefined);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('backendOriginOf', () => {
  it('keeps the port, which is part of where the backend answers', () => {
    expect(backendOriginOf('http://cv-tailor.example.net:8080/')).toBe(
      'http://cv-tailor.example.net:8080',
    );
  });

  it('drops a path, because the client appends the whole one itself', () => {
    expect(backendOriginOf('https://cv-tailor.example.net/anything')).toBe(
      'https://cv-tailor.example.net',
    );
  });

  it('refuses anything that is not an http or https URL', () => {
    expect(backendOriginOf('cv-tailor.example.net')).toBeNull();
    expect(backendOriginOf('file:///etc/hosts')).toBeNull();
    expect(backendOriginOf('')).toBeNull();
  });
});

describe('hostPermissionOf', () => {
  it('leaves the port out, because a match pattern carries none', () => {
    expect(hostPermissionOf('http://cv-tailor.example.net:8080/')).toBe(
      'http://cv-tailor.example.net/*',
    );
  });

  it('asks for the one origin and nothing wider', () => {
    expect(hostPermissionOf('https://cv-tailor.example.net')).toBe(
      'https://cv-tailor.example.net/*',
    );
  });
});

describe('stored settings', () => {
  it('round-trips through chrome.storage.local', async () => {
    await writeSettings({ baseUrl: 'https://cv-tailor.example.net', token: 'token-abc' });

    expect(await readSettings()).toEqual({
      baseUrl: 'https://cv-tailor.example.net',
      token: 'token-abc',
    });
  });

  it('reads as unconfigured until both the URL and the token are there', async () => {
    expect(await readSettings()).toBeNull();

    fake.store['settings'] = { baseUrl: 'https://cv-tailor.example.net', token: '' };

    expect(await readSettings()).toBeNull();
  });
});
