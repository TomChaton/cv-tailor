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

**The only person using it is its owner, and that is an ordering decision
rather than a statement about what the tool is for.** The owner knows the
master CV format, the stack and the commands, so nothing is built *today* to
teach a reader who does not: `cv:lint` and the in-app editor are a complete
answer for somebody maintaining a document they wrote. The tools a stranger
would need — reading a CV out of a document and being walked through making a
master CV of it, and the rest of it — are a later piece of work, and the design
intent recorded for them stands. Where the review UI is asked for a state the
backend does not support, build the UI and record the request: the owner is
the only person who sees it, and a recorded request is not a promise broken.

Four things this does not relax, each there for the owner rather than for a
stranger:

- **The fabrication guarantee.** It is not about who runs the tool. It is
  about what an employer reads.
- **The personal data rules.** The repository is written to be published even
  though the instance is not.
- **Observability.** The owner is the one who has to diagnose it, with no
  colleague and no second instance to compare against.
- **Idiomatic Laravel.** The owner is learning the framework from this
  codebase.

## Deployment

**Docker Compose on a single host.** The reference host is an always-on Mac
reached over Tailscale; nothing in the application depends on that.

- **One application image, several roles.** `app` (FrankenPHP serving the API
  and review UI), `worker` (`queue:work`), and `scheduler` (`schedule:work`)
  run the same image with different commands. `gotenberg` is a separate
  service so Chromium stays out of the PHP image. **The image carries the
  source** rather than bind-mounting it, so the three roles cannot drift from
  each other and a deploy is one artefact. The consequence for anybody running
  a change is that no edit is live until the image is built again: a stack
  brought up without `--build` serves the previous build, whatever the working
  copy says.
- **Images must run on `amd64` without AVX2.** The reference host is an Intel
  Ivy Bridge machine. Images that require x86-64-v3 fail there with illegal
  instruction errors, so every image is checked before it is adopted, and
  versions are pinned rather than floating. The versions in use are
  **FrankenPHP 1.13.1 on PHP 8.5.11** for the application image, and
  **`gotenberg/gotenberg:8`** (Chromium 152) for PDF conversion. A version is
  changed deliberately, with the new one checked on that host first.
- **State lives in named volumes** — the SQLite database and the files a
  revision renders to — never bind mounts. On macOS, bind mounts go through the
  Docker VM's file sharing, which is slow and does not give SQLite reliable
  locking.
- **The master CV is stored by the application, not mounted.** It is the one
  thing every user has to create and maintain, and as a file on the host it
  was the one thing the application could not help with: editing meant leaving
  for a line number the UI had reported and changing it in another program,
  and a new installation could do nothing at all until somebody had got a file
  onto the host. So the CV is a record in the database, written and corrected
  where its problems are reported, and a path reaches it only once — through
  `cv:import`, for somebody who already has one.

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
- **The document names no server.** Every instance is self-hosted at an
  address of its own, so the client is handed a base URL and a token, and one
  document then describes every installation. A document carrying whichever
  `APP_URL` the person who exported it happened to have would be wrong
  everywhere else.
- **A refusal is always JSON, and the API never redirects.** The framework's
  own default sends an unauthenticated visitor to a route named `login`, which
  assumes an application with a web login route; this one's login belongs to a
  panel, so where a guest is sent is configured — nothing for `api/*`, and the
  panel's login route, named rather than pathed, for everything else. The API
  is answered rather than redirected because a client cannot act on a redirect:
  an API that redirects a rejected token answers a permanent refusal with
  something the caller reads as the backend's own fault, and a client that
  retries the backend's faults then retries that refusal for ever. Nothing in
  the answer may depend on what the caller said it accepts, either — a caller
  that states nothing is not a caller to be guessed at.
- **Capturing a job is idempotent.** The extension may retry; the backend
  deduplicates (see the backend briefing) and returns the existing job rather
  than creating a second.
- **Capture carries the raw material, not an interpretation of it**: the page
  URL, the page's whole visible text, the `JobPosting` JSON-LD the page
  published about itself where it published any, the source site, and whether
  hidden text was found. The backend interprets it. Anything the backend can
  work out later, it works out later — so better extraction can be re-run over
  old captures.
- **The extension does not choose which part of a page is the job
  description.** Finding it is a judgement rather than a parse, and a judgement
  made at capture time cannot be revisited: what was not sent cannot be
  re-read. A container the extension picked left the page's metadata outside
  it, so no improvement to extraction could recover a title from what was
  stored. The whole page can be read again, so the whole page is what is
  stored.

### Capture

`POST /api/v1/postings`, with the extension's bearer token.

- **A new posting answers `201`; one the backend already held answers `200`.**
  The extension has two things to say — saved, against already saved — and a
  status code is the cheapest place for that difference to live. A body that
  said so instead would be a field every later caller had to remember to read.
- **The response carries the posting's identity and nothing else.** The
  extension has just sent the text and does not need it back.
- **Capture never parses the master CV and never creates a revision.** A
  posting captured while the CV fails its lint is still a posting worth having,
  so capture is the one step the state of the CV cannot stop. Queueing a
  posting for generation is a later step, and that one does depend on the CV.
- **The API states a request body limit, and refuses a larger body with
  `413`.** A capture carries a page's whole visible text, so without a stated
  ceiling the largest page anybody browses decides how much memory the
  application uses. The limit is configuration, and it is checked against the
  declared length before anything reads the body.
- **The capture itself says nothing about what the page advertises.** The
  title, the company, the location, the salary and the job description are
  extraction's answer, worked out on the backend after the capture has landed.
  See the backend briefing.
- **A capture carries what the owner asked for**, which is the one thing in it
  the page did not say. Keeping a posting and asking for a CV for it are
  decided in the same glance, on the job board, so the decision travels with
  the capture rather than waiting for a second call into the review UI — making
  it later, in another window, is a second visit to a job already read.
- **It is one field with one value per choice, not a flag per choice.** Two
  flags would let a capture ask for nothing to be generated and a cover letter
  with it, which means nothing, and a third choice would need a third flag. The
  field is named for the decision rather than for what the backend does with
  it, and the value is a string the document enumerates, so the extension's
  generated client cannot send a choice the backend does not have.
- **A capture that omits the field asks to keep the posting**, which is what
  capture has always done. A caller that knows nothing about the field
  therefore behaves as it did before there was one.

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

The database and a revision's rendered files are backed up nightly by the
scheduler: a consistent SQLite snapshot (`sqlite3 .backup`, never a file copy
of a live database) plus those files, written to a host directory configured by
environment variable. Off-host copies are the host's job (e.g. Time Machine).

**The database holds the master CV, which is the one piece of state nobody can
regenerate.** A captured posting can be captured again and a revision can be
generated again; a CV its author has spent years on cannot. That is what the
backup is for, and it is also why the database fsyncs on every commit rather
than at checkpoints — see the backend briefing.

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

All of these run from the repository root.

### First run, in this order

1. `cp .env.example .env`. Compose reads `.env` for its own interpolation as
   well as for the containers' environment, so nothing starts without it.
2. `docker compose run --rm toolchain composer install`. Nothing else that
   calls artisan works before this: `vendor/` is not in the repository and the
   toolchain image deliberately carries no source of its own.
3. `docker compose run --rm toolchain php artisan key:generate --show`, and put
   the result in `APP_KEY`.
4. `docker compose up -d --build`.
5. `docker compose exec app php artisan cv:owner` to create the account the
   review UI is entered with, then
   `docker compose exec app php artisan cv:token` for the extension's token. A
   stack whose `.env` names them needs neither — see below.
6. The master CV. Write or paste it on the Master CV page in the review UI.
   Somebody who already has one as a file copies it in and imports it:
   `docker compose cp master-cv.md app:/tmp/master-cv.md`, then
   `docker compose exec app php artisan cv:import /tmp/master-cv.md`. Nothing
   is generated until there is one, and `php artisan cv:status` says whether
   there is.

### Credentials may come from the environment instead

`CV_OWNER_NAME`, `CV_OWNER_EMAIL`, `CV_OWNER_PASSWORD` and
`CV_EXTENSION_TOKEN` name the owner account and the extension's token, and the
`app` role applies them on boot, after migrating.

They buy a stack that comes back up ready: a database discarded with `down -v`
otherwise costs both credentials again, and the extension costs them twice over
because a freshly generated token has to be pasted into the browser before it
will send anything. Only the token's hash is ever stored, so a token the owner
chose is held no more weakly than one the command generated — and because the
plaintext is the owner's own, an extension configured once goes on capturing
against a database built again. They already sit beside the LLM API key in the
same file, so putting them there costs no secrecy.

**An empty variable means its step does not run.** It does not mean the step
runs and fails, and it never means a default is used.

**Seeding is refused where `APP_ENV` is production.** A password sitting in a
file is not how a real instance should get its owner, so the convenience stops
at the edge of one. `cv:owner` and `cv:token` are unaffected there and are how
an instance somebody else hosts is set up.

### Afterwards

| Task | Command |
| --- | --- |
| Start the stack, and pick up any change to it | `docker compose up -d --build` |
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
