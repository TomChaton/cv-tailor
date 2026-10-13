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
  `storage` for settings, and host permission for the configured backend
  origin only. No broad host permissions: the extension reads a page only when
  the user acts on it.
- **The backend URL and API token are set on the options page** and kept in
  `chrome.storage.local`. Nothing about a particular installation is built in.

## Capture

- The user triggers capture from the extension's action (toolbar button or
  keyboard shortcut) on a job's detail view.
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
  extractor — and omitted rather than guessed.
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
  the contract puts it, and handed to the caller as a flag. Nothing downstream
  has to know an HTTP code to choose between the two things capture says.
- **A failed send reports *why* in a form a caller can act on**: a thrown fetch
  is a backend asleep or unreachable and the capture is worth keeping, where a
  refusal is not. The sentence the owner reads is chosen by the caller from
  `_locales`, never by the client.
- **The document is mounted into the `node` container on its own**, rather than
  by widening that container's mount to the repository root. The root holds
  `.env` and the LLM API key in it, and neither has any business being readable
  by something npm installed.

## Feedback

- Success is shown as a badge or a minimal popup state: "Saved ✓". A duplicate
  is reported as already saved.
- Failure says what the user can act on: not configured, backend unreachable,
  token rejected, nothing found on the page.
- Every word comes from `_locales/en_GB/messages.json`.

## Reliability

- **Capture survives a backend that is asleep or unreachable.** A failed send
  is kept in a small local queue and retried, with backoff, when the service
  worker next runs. Capture is idempotent on the backend, so retries are safe.
- The service worker can be stopped by Chrome at any time, so no state lives
  in memory between events.

## Observability

- Every log line in the service worker and content scripts is prefixed
  `[cv-tailor]` and names the step: extractor chosen, extraction result size,
  hidden text found, send attempted, send result, retry queued.
- Logs never contain job description text.
- To look: `chrome://extensions` → cv-tailor → service worker → Inspect.

## Testing

- Each site extractor is tested against saved HTML fixtures of that site's
  job pages. When a site's markup changes, the fix starts with a new fixture.
- Fixtures are trimmed to what extraction needs and contain no personal data.

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
