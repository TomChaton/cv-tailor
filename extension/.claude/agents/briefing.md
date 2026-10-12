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

## Briefing gaps

- **Commands.** The containerised commands to install dependencies, build,
  test, lint and regenerate the API client. To be added by the scaffold.
- **Supported sites.** The list of site extractors and their fixture
  locations, once the first are built.
