import { log } from './log';

// Chrome stops and restarts the service worker whenever it likes, so nothing is
// held in memory between events. This line therefore runs on every start, and
// its absence from the console means the worker is not running.
log('service worker started');
