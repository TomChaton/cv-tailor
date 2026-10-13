# cv-tailor — Extension briefing

The Chrome extension. Read the system briefing first; this one assumes it.

## Purpose

**Capture a job description and send it to the backend. Nothing else.** No
generation, preview, review or editing happens in the extension. Keeping it
this small means it rarely changes, and changes to job-board markup are the
only thing that should routinely require a new build.

## Platform

- **Manifest V3**, TypeScript, built with a bundler to a plain unpacked
  extension. It is loaded unpacked; distribution through the Chrome Web Store
  is a separate decision not yet taken.
- **Permissions are minimal.** `activeTab` and `scripting` for capture,
  `storage` for settings, `alarms` to wake the service worker for a retry. No
  broad host permissions: the extension reads a page only when the user acts on
  it.
- **The backend URL and API token are set on the options page** and kept in
  `chrome.storage.local`. Nothing about a particular installation is built in.
- **The backend's host permission is optional in the manifest and requested
  when the options page is saved.** A build happens before anybody has a
  backend, so the manifest cannot name the origin, and granting a broad host
  permission at install to cover the gap is not an acceptable substitute. So
  the manifest declares the optional pattern and the options page asks for the
  single origin that was typed; settings whose permission is refused are not
  saved, and the previous origin is dropped when the backend moves, which keeps
  what is granted equal to what is configured.
- **The requested pattern carries no port.** Chrome's match patterns have none,
  and one without a port matches the host on every port — so a backend on
  `:8080` is reached under a `scheme://host/*` grant.

## Capture

- The user triggers capture from the extension's action (toolbar button or
  keyboard shortcut) on a job's detail view. The shortcut is bound to
  `_execute_action` rather than to a command of its own, so the two triggers
  are one path and cannot drift apart.
- **Extraction is layered**: a small per-site extractor keyed on hostname for
  the job boards in regular use, falling back to a generic article extractor
  (Mozilla Readability) everywhere else. Site extractors are isolated modules,
  one per site, so a broken site is fixed without touching the others.
- **Only visible text is sent as the job description.** Extraction reads
  rendered text (`innerText`, skipping hidden elements), not `textContent`.
  If the container held hidden text, the capture says so. Hidden text in a
  listing is sometimes aimed at AI tools, and the owner should know.
- **The raw HTML of the extracted container is sent too**, so the backend can
  re-extract later as extractors improve.
- Metadata (title, company, location, salary) is taken where the page
  exposes it — structured data first (`JobPosting` JSON-LD), then the site
  extractor — and omitted rather than guessed. A heading that looks like a job
  title is a guess, so the generic extractor reads metadata from JSON-LD and
  from nowhere else. A salary stated as a `MonetaryAmount` is joined as it
  stands: writing "per year" would put an English word in a data field, and
  rounding the numbers would change what the board said.
- The payload follows the API contract in the system briefing. The client is
  generated from the backend's OpenAPI document and never hand-written.

### How the generic extractor finds the job description

- **Readability reads a marked clone, and its answer names a live element.**
  Readability rewrites the document it is given, so it is handed a clone whose
  every element carries a data attribute naming its live counterpart; the live
  element that contains everything Readability kept is the container. Its own
  output is a cleaned copy, which is no use as the raw HTML the backend
  re-extracts from, and it hands back no node to ask about.
- **Hidden elements are removed from that clone before Readability sees it**, so
  text a reader cannot see cannot steer which part of the page is chosen.
- **Visible text is read with `innerText`, but only on a subtree that holds
  nothing hidden.** `innerText` is the browser's own answer to what a page says
  and so is what it is read with — but it includes text that is merely clipped
  or parked off-screen, which is exactly what a listing aimed at an automated
  reader uses. A subtree holding something hidden is walked instead and the
  hidden parts dropped.
- **`aria-hidden` does not count as hidden.** It hides an element from
  assistive technology, not from the person looking at the page.
- **There is a floor on the extracted text.** Readability hands back its best
  attempt even when it is sure of nothing, so a page whose visible article text
  is shorter than any job description is treated as nothing found. Without it a
  sign-in page captures as a posting.
- **The extractor is injected in two steps.** `executeScript` hands back the
  value of a function it is given, but that function is serialised to source and
  so cannot close over a bundled dependency — and injecting a *file* hands
  nothing back at all. So the bundled extractor is injected as a file that
  publishes itself under a known name, and a second, self-contained injection
  calls it. Both land in the same isolated world, which is how the name is there
  to be found.

## The API client

`src/api/schema.ts` is generated from the backend's committed OpenAPI document.
`src/api/client.ts` wraps it: it takes a base URL and a bearer token as
arguments, because nothing about a particular installation is built in, and
reading those from storage is the options page's job.

- **Every type in the client comes from the generated schema**, so the compiler
  is what keeps the two sides in step: a field the backend adds or renames stops
  the extension type-checking.
- **Whether a posting is new is read from the response status**, which is where
  the contract puts it, and handed to the caller as a flag. `201` is a posting
  this capture created and `200` one the backend already held; the body says
  nothing about the difference, and nothing downstream has to know an HTTP code
  to choose between the two things capture says.
- **A failed send reports *why* in a form a caller can act on**: a thrown fetch
  is a backend asleep or unreachable and the capture is worth keeping, where a
  refusal is not. The sentence the owner reads is chosen by the caller from
  `_locales`, never by the client.
- **The document is mounted into the `node` container on its own**, rather than
  by widening that container's mount to the repository root. The root holds
  `.env` and the LLM API key in it, and neither has any business being readable
  by something npm installed.

## Feedback

- **The popup is how a capture is made.** Opening it starts the capture of the
  active tab; the work itself happens in the service worker, because the popup
  closes the moment the owner clicks away and a capture must not close with it.
- **The popup shows one line**: what happened — saved, already saved, not
  configured, nothing found on the page, or what the backend said. A second line
  appears only while the capture is still waiting to be sent, and a way into the
  settings only when there are none.
- **The badge shows what is outstanding.** A count while captures wait to be
  sent, because that is the one thing the owner cannot find out any other way;
  with nothing waiting, a mark for the last capture — one for landed, one for
  needing the owner. The popup is gone seconds after it opens, so the badge is
  what is left to read.
- Failure says what the user can act on: not configured, backend unreachable,
  token rejected, nothing found on the page.
- Every word comes from `_locales/en_GB/messages.json`, the badge's marks
  included.

## Reliability

- **Capture survives a backend that is asleep or unreachable.** A failed send
  is kept in a small local queue and retried, with backoff, when the service
  worker next runs.
- **A capture is queued before it is sent, not after it fails.** Chrome can stop
  the service worker in the middle of a send, and a capture that exists only in
  memory is a capture that can vanish. It is written to the queue first and
  removed once the backend has it, so the worst case is a capture the owner is
  told was kept.
- **The queue lives in `chrome.storage.local`** and is the only state capture
  keeps. The service worker can be stopped by Chrome at any time, so no state
  lives in memory between events.
- **Retrying is safe because capture is idempotent**: a posting the backend
  already holds answers with that posting rather than a second copy. That is
  what lets the queue send something a second time without having to know
  whether the first attempt arrived.
- **Backoff doubles from a minute to an hour**, counted per capture. A minute is
  under the shortest wait worth taking on a backend that has just failed to
  answer, and past an hour waiting longer gains nothing: generation is
  asynchronous anyway and the badge says how many captures are outstanding.
- **One alarm is set, for the earliest attempt due.** An alarm is what wakes a
  stopped service worker; without one a queued capture waits for the next time
  the owner happens to capture something. The queue is also offered to the
  backend when a capture lands, when the worker starts and when the extension is
  installed.
- **Only what a later attempt could still save is kept.** A backend that is
  asleep will answer eventually, a token that was refused can be replaced, and
  an error on the backend's side is its own to recover from. A refusal decided
  by the payload — too large, or rejected as invalid — would be refused
  identically for ever, so it is dropped rather than queued for ever.

## Observability

- Every log line in the service worker and content scripts is prefixed
  `[cv-tailor]` and names the step: extractor chosen, extraction result size,
  hidden text found, send attempted, send result, retry queued and the queue's
  depth.
- **What happens in the page is reported in both consoles**: once in the page's
  own, and once in the service worker's from what the extractor handed back. An
  injected script logs where it runs, and the worker's console is the only place
  that sees a capture end to end.
- Logs never contain job description text. Sizes, outcomes and the host only.
- To look: `chrome://extensions` → cv-tailor → service worker → Inspect.

## Testing

- Each site extractor is tested against saved HTML fixtures of that site's
  job pages. When a site's markup changes, the fix starts with a new fixture.
  The generic extractor is tested the same way: a page with `JobPosting`
  JSON-LD, one without, one with hidden text in the container, and one that is
  not an article at all.
- Fixtures are trimmed to what extraction needs and contain no personal data.
  They are the inner HTML of `<html>` and load into the test's own document,
  because extraction asks the browser what is visible and that needs a document
  with its styles applied.
- **The tests run against a DOM implementation, not a browser.** It answers
  `checkVisibility` and `getComputedStyle`, which is what extraction decides
  visibility with, so a hiding technique can be proved excluded without driving
  Chrome.

## Commands

Run from the repository root. The Node toolchain is a Compose service, `node`,
in the `tools` profile, so `docker compose up` never starts it. Nothing is
installed on the host.

| Task | Command |
| --- | --- |
| Install dependencies | `docker compose run --rm node npm ci` |
| Build the unpacked extension | `docker compose run --rm node npm run build` |
| Regenerate the API client | `docker compose run --rm node npm run api:client` |
| Run the tests | `docker compose run --rm node npm test` |
| Lint and type-check | `docker compose run --rm node npm run lint` |

`api:client` reads the backend's document, which Compose mounts at
`extension/openapi.json` and `.gitignore` keeps out of the repository: the one
copy of the contract lives in `backend/`. Regenerate the document first — the
command is in the backend briefing.

The build writes `extension/dist`. Load it with `chrome://extensions` →
Developer mode → Load unpacked → `extension/dist`.

## Briefing gaps

- **Supported sites.** The list of site extractors and their fixture
  locations, once the first are built.
