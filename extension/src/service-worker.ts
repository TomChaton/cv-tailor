import { captureTab, drainQueue } from './capture/capture';
import { RETRY_ALARM } from './capture/queue';
import { log } from './log';
import { isCaptureRequest } from './messages';

// Chrome stops and restarts the service worker whenever it likes, so nothing is
// held in memory between events. This line therefore runs on every start, and
// its absence from the console means the worker is not running.
log('service worker started');

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!isCaptureRequest(message)) {
    return false;
  }

  captureTab(message.tabId)
    .then(sendResponse)
    .catch((thrown: unknown) => {
      log('capture failed', {
        reason: thrown instanceof Error ? thrown.name : typeof thrown,
      });

      sendResponse('backend-error');
    });

  // The answer arrives after this listener returns, which is what `true` says.
  return true;
});

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === RETRY_ALARM) {
    void drainQueue();
  }
});

// A capture waiting to be sent is offered again as soon as there is anything
// running to offer it.
chrome.runtime.onStartup.addListener(() => void drainQueue());
chrome.runtime.onInstalled.addListener(() => void drainQueue());
