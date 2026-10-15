// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { isCaptureRequest } from '../src/messages';

import { installFakeChrome, messageFor } from './fake-chrome';
import popupPage from '../popup.html?raw';

import type { FakeChrome } from './fake-chrome';

const TAB = 17;

let fake: FakeChrome;

/**
 * The popup's own markup, so the ids the script looks for are the ids the page
 * ships: a button renamed in one and not the other fails here. The page's
 * `<script>` is left out, because the test imports the module itself.
 */
async function openPopup(): Promise<void> {
  const body = /<body>([\s\S]*)<\/body>/.exec(popupPage)?.[1];

  expect(body, 'popup.html has a body').toBeTypeOf('string');

  document.body.innerHTML = (body ?? '').replace(/<script[\s\S]*?<\/script>/g, '');

  vi.resetModules();

  await import('../src/popup');
}

function button(id: string): HTMLButtonElement {
  const found = document.getElementById(id);

  expect(found, id).not.toBeNull();

  return found as HTMLButtonElement;
}

async function click(id: string): Promise<void> {
  button(id).click();

  // The handler asks the worker and waits for the answer, so the assertions
  // wait for everything already queued rather than for one tick of it.
  await new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(() => {
  fake = installFakeChrome();
  fake.activeTabId = TAB;
  vi.spyOn(console, 'log').mockImplementation(() => undefined);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('the popup', () => {
  it('offers the three actions, labelled from the catalogue', async () => {
    await openPopup();

    expect(button('save').textContent).toBe(messageFor('popupSave'));
    expect(button('cv').textContent).toBe(messageFor('popupCv'));
    expect(button('cover-letter').textContent).toBe(messageFor('popupCvAndCoverLetter'));
  });

  /**
   * The capture starts on a click. The owner is on the job board and knows
   * whether the posting is worth a CV, and opening the popup is not the answer
   * to that question.
   */
  it('captures nothing until a button is clicked', async () => {
    await openPopup();

    expect(fake.sentMessages).toHaveLength(0);
    expect(document.getElementById('outcome')?.hidden).toBe(true);
  });

  it.each([
    ['save', 'save'],
    ['cv', 'cv'],
    ['cover-letter', 'cv_and_cover_letter'],
  ])('asks the service worker for %s', async (id, askedFor) => {
    await openPopup();

    await click(id);

    expect(fake.sentMessages).toEqual([{ type: 'capture', tabId: TAB, askedFor }]);
    expect(fake.sentMessages.every(isCaptureRequest)).toBe(true);
  });

  /**
   * One line, whichever button was clicked: saved and already saved are what
   * the owner needs told, and neither becomes two sentences because there were
   * three buttons.
   */
  it('says what happened in one line and puts the buttons away', async () => {
    fake.messageAnswer = 'already-saved';

    await openPopup();

    await click('cv');

    expect(document.getElementById('actions')?.hidden).toBe(true);
    expect(document.getElementById('outcome')?.textContent).toBe(
      messageFor('captureAlreadySaved'),
    );
    expect(document.getElementById('kept')?.hidden).toBe(true);
  });

  it('says a capture it kept is sent again later', async () => {
    fake.messageAnswer = 'unreachable';

    await openPopup();

    await click('save');

    expect(document.getElementById('outcome')?.textContent).toBe(messageFor('captureUnreachable'));
    expect(document.getElementById('kept')?.hidden).toBe(false);
  });

  it('offers a way into the settings only where there are none', async () => {
    fake.messageAnswer = 'not-configured';

    await openPopup();

    await click('save');

    expect(button('settings').hidden).toBe(false);

    button('settings').click();

    expect(fake.optionsPageOpened).toBe(1);
  });

  it('takes no second click while the worker is still working', async () => {
    await openPopup();

    await click('cv');

    expect(button('save').disabled).toBe(true);
    expect(button('cv').disabled).toBe(true);
    expect(button('cover-letter').disabled).toBe(true);
  });

  /**
   * A window with no tab to read is a different fact from a page that holds
   * nothing, and it is reported without the worker being asked anything.
   */
  it('reports a window with no active tab as a page it cannot read', async () => {
    fake.activeTabId = undefined;

    await openPopup();

    await click('save');

    expect(fake.sentMessages).toHaveLength(0);
    expect(document.getElementById('outcome')?.textContent).toBe(
      messageFor('capturePageUnreadable'),
    );
  });
});
