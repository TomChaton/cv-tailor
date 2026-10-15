# cv-tailor — Extension briefing

The Chrome extension. Read the system briefing first; this one assumes it.

## Purpose

**Capture a job description and send it to the backend. Nothing else.** No
generation, preview, review or editing happens in the extension. Keeping it
this small means it rarely changes. It reads a page for its text and not for
its meaning, so a board rearranging its markup is not a reason for a new
build.

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

- The user opens the extension's action (toolbar button or keyboard shortcut)
  on a job's detail view, and the capture starts on one of the popup's three
  actions rather than on the popup opening. The shortcut is bound to
  `_execute_action` rather than to a command of its own, so the two triggers
  are one path and cannot drift apart.
- **The extension sends the page and judges nothing about it.** Finding the job
  description on an arbitrary page is a judgement rather than a parse, and a
  judgement made here cannot be revisited: what was not sent cannot be
  re-read. So the whole page's visible text goes, and the backend asks a model
  what it advertises.
- **There is therefore no "nothing found".** Any page the owner chose to
  capture is a page worth capturing, and there is no floor on how much text it
  has to hold.
- **A read that comes back empty is the one exception, and it is not a
  judgement about the page.** Nothing asks whether the text is a job
  description and no page is refused for being short — but an empty read leaves
  nothing to send, and a capture of nothing is one the backend can only refuse.
  So it is not sent and not queued, and the extension says the page read as
  blank.
- **Only visible text is sent.** Reading uses rendered text (`innerText`,
  skipping hidden elements), not `textContent`. If the page held hidden text,
  the capture says so. Hidden text in a listing is sometimes aimed at AI tools,
  and the owner should know.
- **Visible text is read with `innerText`, but only on a subtree that holds
  nothing hidden.** `innerText` is the browser's own answer to what a page says
  and so is what it is read with — but it includes text that is merely clipped
  or parked off-screen, which is exactly what a listing aimed at an automated
  reader uses. A subtree holding something hidden is walked instead and the
  hidden parts dropped.
- **`aria-hidden` does not count as hidden.** It hides an element from
  assistive technology, not from the person looking at the page.
- **Content a page never renders is not hidden text.** `script`, `style`,
  `noscript` and `template` hold source, not words somebody was kept from
  seeing, so they are neither read nor counted. The user-agent stylesheet puts
  `display: none` on them, so counting by what is hidden counts a page's own
  markup — and the warning is worth drawing only while it is rare. Set on every
  capture it is a warning the owner learns to ignore, which spends the one
  signal that says a listing is talking to the model. The decision is made on
  the element and never on its computed display, so no stylesheet turns markup
  into a warning or a warning into markup.
- **An element that lays out no box of its own is not hidden.** A browser
  answers the visibility question about boxes and reports a box-less element as
  not visible, but `display: contents` removes only the box: the children drawn
  in its place are on the page, and so is their text. Boards wrap whole regions
  in such an element, and because a hidden element takes its subtree with it, one
  misjudged wrapper costs the region and a wrapper near the root costs the page.
  So hiddenness is asked of the children of a box-less element and never of the
  element, and the out-of-sight rules are not asked of it either — clipping,
  position and opacity each need a box to apply to.
- **The page's `JobPosting` JSON-LD is sent as the page wrote it**, where the
  page published one. What its fields mean is the backend's to decide: reading
  schema.org is a judgement about data, and nothing here looks at a single
  field of it. A page with broken JSON-LD is a page that stated nothing rather
  than a failed capture.
- **The page reader is injected in two steps.** `executeScript` hands back the
  value of a function it is given, but that function is serialised to source
  and so cannot close over a bundled module — and injecting a *file* hands
  nothing back at all. So the bundled reader is injected as a file that
  publishes itself under a known name, and a second, self-contained injection
  calls it. Both land in the same isolated world, which is how the name is
  there to be found.
- **A page the extension may not touch is the one thing left that can fail
  before the backend is involved.** A Chrome page, the web store or a PDF
  refuses injection, and that is a fact about access rather than about what the
  page holds.
- The payload follows the API contract in the system briefing. The client is
  generated from the backend's OpenAPI document and never hand-written.

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

- **The popup is how a capture is made, and it asks what for.** It offers the
  three things the backend can be asked for — keep the posting, generate a CV,
  generate a CV and a cover letter — and nothing is captured until one of them
  is clicked: the owner is on the job board, where whether a posting is worth a
  CV is decided in the same glance as whether to keep it at all. The work
  itself happens in the service worker, because the popup closes the moment the
  owner clicks away and a capture must not close with it.
- **The buttons are a `Record` keyed by the contract's own type**, so they are
  the backend's list of choices rather than a second one kept in step by hand:
  a choice the backend adds or renames stops the popup compiling.
- **The popup shows one line**: what happened — saved, already saved, not
  configured, a page it may not read, a page that read as blank, or what the
  backend said. The actions give way to it, and none of them makes it two
  sentences: three buttons are three decisions, not three things to be told.
  There is no "nothing found on the page": whether a page holds a job
  description is the backend's judgement, and a blank read answers a different
  question — whether there was anything to send at all. A second line appears
  only while the capture is still waiting to be sent, and a way into the
  settings only when there are none.
- **The badge shows what is outstanding.** A count while captures wait to be
  sent, because that is the one thing the owner cannot find out any other way;
  with nothing waiting, a mark for the last capture — one for landed, one for
  needing the owner. The popup is gone seconds after it opens, so the badge is
  what is left to read.
- Failure says what the user can act on: not configured, the page cannot be
  read, backend unreachable, token rejected.
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
- **The queue carries what the owner asked for**, stored with the capture
  rather than beside it. A capture kept for retry has to ask for the same thing
  on the attempt that finally lands, or a decision the owner took on the job
  board is quietly downgraded to a posting nothing generates from.
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
  `[cv-tailor]` and names the step: page text size, whether the page published
  a `JobPosting` object, hidden text found, send attempted, send result, retry
  queued and the queue's depth.
- **What happens in the page is reported in both consoles**: once in the page's
  own, and once in the service worker's from what the page reader handed back. An
  injected script logs where it runs, and the worker's console is the only place
  that sees a capture end to end.
- Logs never contain the page's text, and never the structured data it
  published. Sizes, outcomes and the host only.
- To look: `chrome://extensions` → cv-tailor → service worker → Inspect.

## Testing

- **Fixtures are pages to read text from**, not sites to recognise: a page with
  `JobPosting` JSON-LD, one without, one with hidden text, and one that is not
  an article at all. The last of those proves the page is sent rather than
  refused.
- Fixtures are trimmed to what reading a page needs and contain no personal
  data. They are the inner HTML of `<html>` and load into the test's own
  document, because reading a page asks the browser what is visible and that
  needs a document with its styles applied.
- **One fixture is a page saved from a board in use**, trimmed to what reading
  a page needs: the board's own wrappers, the rules from its own stylesheet that
  decide what is visible, and a job whose words are invented, because a real
  job's words are not ours to publish and a real page carries the owner's
  profile all through it. A fixture written by hand holds only the hiding
  techniques whoever wrote it thought of, and the ones that break a reader are
  the ones nobody thought of. It keeps the board's own inline script and rules
  for the same reason: a page carries its own markup, and a fixture with none
  cannot show that markup is left out of what a page is said to hide.
- **A hidden-character count a fixture asserts is pinned part by part.** A
  total says only that something was counted, and the point of the count is
  which things it is made of: take one of them out of the fixture and the count
  drops by that much, take the markup out and it does not move.
- **The tests run against a DOM implementation, not a browser.** It answers
  `checkVisibility` and `getComputedStyle`, which is what visibility is decided
  with, so a hiding technique can be proved excluded without driving Chrome.
- **Where that DOM answers more generously than a browser, the suite teaches it
  the browser's answer** (`tests/browser-visibility.ts`). It lays nothing out,
  so it cannot say whether an element has a box, and it calls a box-less element
  visible. Under its own answer a page a browser reads as blank reads perfectly,
  and a fixture carrying such a page proves nothing at all.

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
