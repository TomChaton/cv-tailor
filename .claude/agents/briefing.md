# cv-tailor — System briefing

Cross-cutting decisions: what the system is, how its parts fit, how it is
deployed, and the contracts between components. Component detail lives in
`backend/.claude/agents/briefing.md` and `extension/.claude/agents/briefing.md`.

## Purpose

cv-tailor tailors a CV to individual job descriptions. The owner captures jobs
while browsing; generation happens later, unattended; the owner reviews the
results and applies at leisure.

**The governing property: a tailored CV never claims anything the master CV
does not support.** Where any other goal — fluency, length, keyword match,
convenience — conflicts with it, this one wins.

## Shape

```
Chrome extension ──HTTPS──▶ backend (Laravel)
  capture only               ├─ API          receives captured jobs
                             ├─ review UI    lists jobs, revisions, diffs
                             ├─ worker       generation, validation, rendering
                             ├─ scheduler    batch submit / poll, backups
                             └─ gotenberg    HTML → PDF
                                    │
                                    └──▶ LLM provider API (the only egress)
```

- **The extension captures and nothing else.** It sends a job to the backend
  and confirms it was saved. No generation, review or editing happens in the
  browser, so the extension stays small and stable while the logic lives in
  one place.
- **Generation is asynchronous.** Captured jobs queue; the LLM work runs in
  batches; results are validated and rendered before the owner sees them.
  Nothing in the capture path waits on the LLM.
- **Review happens in the backend's own web UI**, not the extension.

## Distribution model

**Single-tenant and self-hosted.** Each person runs their own instance with
their own master CV and their own LLM API key. There is no multi-user
tenancy, and the data model carries none. Publishing means publishing the
software, not running a service. Moving to a hosted multi-user service would be
a deliberate redesign, not an increment.

Because the software is published, the repository contains no personal data.
Tests and examples use a fictional master CV.

## Deployment

**Docker Compose on a single host.** The reference host is an always-on Mac
reached over Tailscale; nothing in the application depends on that.

- **One application image, several roles.** `app` (FrankenPHP serving the API
  and review UI), `worker` (`queue:work`), and `scheduler` (`schedule:work`)
  run the same image with different commands. `gotenberg` is a separate
  service so Chromium stays out of the PHP image.
- **Images must run on `amd64` without AVX2.** The reference host is an Intel
  Ivy Bridge machine. Images that require x86-64-v3 fail there with illegal
  instruction errors, so every image is checked before it is adopted, and the
  versions that passed are pinned rather than floating:
  **FrankenPHP 1.13.1 on PHP 8.5.11** for the application image, and
  **`gotenberg/gotenberg:8`** (Chromium 152) for PDF conversion. A version is
  changed deliberately, with the new one checked on that host first.
- **State lives in named volumes** — the SQLite database and generated PDFs —
  never bind mounts. On macOS, bind mounts go through the Docker VM's file
  sharing, which is slow and does not give SQLite reliable locking.
- **The master CV is bind-mounted read-only.** It is authored on the host, and
  the application only ever reads it.

### Routing is a deployment overlay

The application listens on plain HTTP and knows nothing about how traffic
reaches it. Compose files are layered:

- `compose.yml` — the core services. The app port binds to `127.0.0.1` only,
  so a default install is not exposed to the network.
- `compose.tailscale.yml` — optional overlay adding a Tailscale sidecar that
  gives the app its own tailnet name with HTTPS. Requires `TS_AUTHKEY`.
- Anyone else puts their own reverse proxy (Caddy, Traefik, Cloudflare Tunnel,
  nginx) in front. The README documents one example.

Laravel's `TrustProxies` and `APP_URL` are driven from environment variables
so URL generation and HTTPS detection work behind any proxy.

**The application never relies on the routing layer for authentication.**
Tailscale identity headers, proxy auth and network position are not trusted.
See Authentication.

## Authentication

- **The extension authenticates with a bearer token** — a Laravel Sanctum
  personal access token, created by an artisan command and pasted into the
  extension's options page. Tokens are revocable individually.
- **The review UI uses an ordinary session login** for the single owner
  account, created by an artisan command.
- Both apply even on a private network. They cost nothing and protect an
  instance that is exposed by mistake.

## The API contract

The extension and backend meet at one HTTP API, versioned under `/api/v1`.

- **The OpenAPI document is the contract.** The backend generates it from its
  code; the extension's typed client is generated from that document. A change
  to the API changes both sides in the same pull request, with the client
  regenerated.
- **Capturing a job is idempotent.** The extension may retry; the backend
  deduplicates (see the backend briefing) and returns the existing job rather
  than creating a second.
- **Capture carries the raw material, not an interpretation of it**: the
  page URL, the extracted visible text of the job description, the raw HTML of
  the extracted container, page metadata where present (title, company,
  location, salary), the source site, and whether hidden text was found in the
  container. Anything the backend can work out later, it works out later — so
  better extraction can be re-run over old captures.

## Data that never leaves the host

The only outbound network traffic is to the configured LLM provider's API.

- **Contact details** (phone, email, postal address) and anything else in the
  master CV's contact section are never sent to the LLM. The CV header is
  rendered from the parsed contact section by the template.
- **Logs never contain CV bodies, job description bodies or LLM output.** They
  carry identifiers, sizes, hashes, token counts and outcomes. A log line that
  would be embarrassing in a public bug report is a bug.

## Observability

- **Every log line is structured JSON** with an `event` key naming what
  happened (`posting.captured`, `batch.submitted`, `revision.validated`) and
  context keys for the objects involved (`posting_id`, `revision_id`,
  `llm_batch_id`). Event names are part of the interface: issues name them and
  reviews check them, so they are not renamed casually.
- **Long-running processes emit throttled heartbeats** (`worker.heartbeat`,
  `scheduler.heartbeat`) so a silent process is distinguishable from an idle
  one.
- **`php artisan cv:status` is the first place to look.** It reports counts by
  posting and revision state, open batches and their age, the most recent
  heartbeat from each process, the active master CV version, and recent
  failures. Anything with a state machine appears there. Documents describe how
  to look; this command shows what is.

## Backups

The database and generated PDFs are backed up nightly by the scheduler: a
consistent SQLite snapshot (`sqlite3 .backup`, never a file copy of a live
database) plus the PDFs, written to a host directory configured by
environment variable. Off-host copies are the host's job (e.g. Time Machine).

## Repository layout

```
cv-tailor/
├── CLAUDE.md                code agent instructions
├── .claude/agents/          this briefing
├── compose.yml
├── compose.tailscale.yml
├── .env.example
├── backend/                 Laravel application (own briefing)
└── extension/               Chrome extension (own briefing)
```

## Commands

All of these run from the repository root. Copy `.env.example` to `.env` and
fill it in first — Compose reads it for both its own interpolation and the
containers' environment, so nothing starts without it. `APP_KEY` comes from
`docker compose run --rm toolchain php artisan key:generate --show`.

| Task | Command |
| --- | --- |
| Build and start the stack | `docker compose up -d --build` |
| Stop it | `docker compose down` |
| Stop it and discard its data | `docker compose down -v` |
| See what is running | `docker compose ps` |
| What the stack is doing | `docker compose exec app php artisan cv:status` |
| Follow a service's logs | `docker compose logs -f app` (or `worker`, `scheduler`, `gotenberg`) |
| Find an event | `docker compose logs worker \| grep '"event":"worker.heartbeat"'` |
| A shell in the application | `docker compose exec app bash` |

`toolchain` and `node` carry the development toolchains. They sit in Compose's
`tools` profile, so `docker compose up` never starts them and
`docker compose run` starts them on demand. Their commands are in the backend
and extension briefings.

## Briefing gaps

- **Tailscale overlay.** The exact sidecar configuration and the steps to
  enable it, once built.
