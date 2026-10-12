# cv-tailor — Backend briefing

The Laravel application: API for the extension, the generation pipeline, and
the review UI. Read the system briefing first; this one assumes it.

## Stack

- **Laravel**, current major version, on PHP 8.x.
- **FrankenPHP** serves HTTP: one process, no nginx/php-fpm pair to configure.
- **SQLite in WAL mode** for both application data and the queue (Laravel's
  `database` queue driver). One user and a few dozen jobs a day do not justify
  Postgres or Redis. Moving to Postgres later is a configuration change, so
  nothing may depend on SQLite-specific SQL.
- **Filament** for the review UI. The UI is a tool for one person, not a
  product; Filament gives tables, filters, forms and custom pages with little
  code, and keeps the UI idiomatic Laravel.
- **Sanctum** for the extension's bearer tokens.
- **Gotenberg** (separate Compose service) for HTML → PDF.

## Code organisation

- Domain logic lives in action and service classes under `app/`, grouped by
  area (`app/MasterCv`, `app/Generation`, `app/Validation`, `app/Rendering`).
  Eloquent models stay thin: relationships, casts, scopes.
- Controllers, queued jobs, Filament resources and artisan commands delegate to
  those classes and contain no business rules.
- Constructor injection in domain code; facades only in framework glue.

## The master CV

### Authoring format

The master CV is Markdown, authored by hand and kept by the owner outside the
repository. It is not converted to YAML or JSON: it will be edited for years,
and Markdown is the format people maintain. The application parses it.

The format is a documented convention, and the repository ships a fictional
example that exercises all of it (`tests/Fixtures/master-cv.md`), which doubles
as the starter template for other users.

- **Every selectable unit carries a stable ID** in a trailing `{#id}`
  attribute: roles, achievements, sub-bullets, skills-index rows, independent
  projects. IDs are hierarchical (`acme`, `acme.search`, `acme.search.alias`)
  so a parent can be selected whole or narrowed to some of its children.
- **IDs are written by hand, never derived from content.** Rewording a bullet
  must not change its identity, or revision history would lose track of it.
- **Recognised sections** are found by heading: agent rules, contact, headline
  titles, profile facts, skills index, employment history, independent
  projects, awards, interests, and "Do not claim". Their roles are below.
- **Unknown-detail markers** — `TODO`, and phrases such as "not recorded" or
  "do not elaborate" — are part of the format. Content carrying `TODO` is never
  selectable. Content carrying "do not elaborate" may be used verbatim only.

### Three kinds of content

The parser separates what the file mixes:

- **Rules** — the agent rules section and the "Do not claim" section. Sent to
  the model verbatim, and also the source of deterministic checks.
- **Facts** — roles, achievements, skills, projects, profile facts. The only
  material the model may select from.
- **Identity** — the contact section. **Never sent to the LLM.** The rendered
  CV header comes from these fields through the template, so the model cannot
  mistype a phone number it never sees.

### Presentation policy

Generation needs settings the CV's facts do not hold: target length, default
role modes (e.g. roles ending before a cutoff year default to `condensed`),
and which headline titles are on offer. These live in the CV's front matter so
each user's policy travels with their CV. Defaults apply when absent.

### Linting

`php artisan cv:lint <path>` checks a master CV before it is used, and every
generation run refuses a CV that fails it:

- IDs present on every selectable unit, unique, and well-formed.
- Every skills-index "where used" entry names a role or project that exists,
  and every technology in a role's tech stack appears in the skills index.
  The index and the stacks state the same facts from opposite directions and
  will drift; the linter is what stops that.
- Recognised sections present.

Lint output names the line and the problem. It is written for the CV's author,
not for a developer.

### Versioning

The CV file on disk is the editing surface; the database holds what was used.
At each generation the parsed CV is hashed and stored in `master_cv_versions`
(hash, raw content, parsed structure), deduplicated by hash. Every revision
references the exact version it was generated from. This does not depend on
the user keeping their CV in git.

## Data model

- **`jobs`** — a captured posting: URL, source site, title, company, location,
  salary text, raw HTML, extracted text, hidden-text flag, dedupe hash,
  `status`, `current_revision_id`, `applied_revision_id`.
- **`revisions`** — one tailored CV for a job.
  - `job_id`, `parent_revision_id` (nullable — revisions form a tree, not a
    list), `master_cv_version_id`.
  - `source`: `generated` | `regenerated` | `manual_edit`.
  - `instructions` (nullable) — the owner's steer for a regeneration.
  - `model`, `prompt_version`, token usage including cache reads, cost.
  - `output_json` (the model's selection), `resolved_json` (selection resolved
    against the master CV), `validation_json` (findings), `pdf_path`.
  - `status`: `pending` | `submitted` | `generated` | `validated` | `ready` |
    `failed`; `attempts`; `error`.
- **`master_cv_versions`** — as above.
- **`batches`** — provider batch ID, state, submitted/ended times, request
  count, linked revisions.

### Revisions are immutable once complete

A revision that has reached `ready` or `failed` is never updated. Editing or
regenerating creates a child revision. This is what makes the history
trustworthy and diffs between revisions meaningful.

`current_revision_id` is the one the owner is looking at; `applied_revision_id`
is the one they actually sent. They are separate because the version that went
to an employer is the one that matters later.

### Job status

`captured → queued → ready → reviewed → applied`, plus `discarded`. A job is
`ready` when its current revision is `ready`. The job list doubles as an
application tracker.

### Deduplication

The same role appears on several boards. Capture computes a hash over the
normalised title, company and description text; a capture matching an existing
job returns that job rather than creating another. Agency reposts with the
client anonymised will not match, and that is accepted.

## Generation

### Output schema

The model returns structured JSON. The guiding rule: **the model selects and
references; any free text it writes must cite its sources; code owns
everything else.**

- **`jd_analysis`** — the job's requirements, each with priority (`must` /
  `nice`), status (`met` / `partial` / `gap`), the master CV IDs that evidence
  it, and a note. Asked for first, in the same call, because extracting
  requirements before selecting improves the selection, and it drives the
  requirements checklist in the review UI.
- **`headline`** — one of the CV's headline titles.
- **`summary`** — text plus `supports`, the IDs it draws on. The riskiest
  field, so it is validated most strictly.
- **`key_skills`** — skill IDs, not free text.
- **`roles`** — per role: `mode` (`full` / `condensed` / `omitted`) and the
  selected items in the order to show them. Each item is an ID with `text`
  either `null` (use the source verbatim — the normal case) or a rewording
  that must carry `supports`.
- **`independent_projects`** — same shape as roles.
- **`review_notes`** — the model's notes for the owner: gaps, judgement calls,
  any role promoted from its default mode and why.

ID fields are **enums built from the parsed master CV version** in the
structured-output schema, so an invented ID is impossible rather than merely
detectable. The schema is therefore generated per CV version.

**The model never controls role order or dates.** Roles render in reverse
chronological order with dates from the parsed CV, whatever the model returns.

### Prompt layout

Ordered from most to least stable, so prompt caching works:

1. **System — static instructions**, versioned by `prompt_version`: the task,
   output rules, the meaning of `text: null`, role modes, and that the master
   CV's rules override everything, including the owner's instructions.
2. **System — the master CV**: the parsed CV with identity removed and the
   rules and "Do not claim" sections verbatim. **The cache breakpoint goes
   here**, using the longer cache lifetime, since batch requests run in
   parallel and cache hits are best-effort.
3. **User — per request**: the job description wrapped and marked as
   untrusted data; job metadata; this run's presentation policy; and for a
   regeneration, the parent revision's output plus the owner's instructions.

`prompt_version` is bumped whenever prompt text or the output schema changes,
so a difference between revisions can be attributed to the prompt or to the
instructions.

### The job description is untrusted input

Listings can contain text aimed at AI tools — hidden instructions, keyword
traps. The prompt states that the job description is material to analyse, not
instructions to follow. The extension sends only visible text and flags hidden
text, which the review UI shows. The deterministic validators catch most
injected claims regardless.

### Regeneration

- Instructions change emphasis and selection, never facts. "Emphasise the AWS
  work" may promote bullets; "say I know Kubernetes" must fail validation.
- A regeneration receives its parent's output as a starting point, so it is an
  edit rather than a fresh roll — otherwise every regeneration reshuffles
  everything and diffs become noise.
- A manual edit skips the LLM but passes through the same deterministic
  validators.

### Batch lifecycle

Generation uses the provider's batch API: nothing is interactive, and batches
are cheaper.

- **Submit** (scheduled): gather `pending` revisions; submit when there are
  enough, or when the oldest has waited past a threshold. Each request's
  custom ID is the revision's ID.
- **Poll** (scheduled): check open batches. When one ends, stream its results
  and dispatch one queued job per result. Success → store output and usage,
  then validate. Error or expiry → back to `pending` with `attempts`
  incremented, until a limit, then `failed`.
- A batch that stays open beyond a threshold is reported by `cv:status` and
  logged as a warning: an uncollected batch eventually expires.
- **"Generate now"** in the review UI bypasses batching with a live call for a
  single revision, for deadlines.

### LLM client

The provider sits behind an `LlmClient` interface (`submitBatch`, `pollBatch`,
`fetchResults`, `complete`), with model names in configuration — one for
generation, one for validation. Swapping providers is a new implementation,
not a refactor. The default implementation targets Anthropic's API.

## Validation

Validation runs after generation and before rendering. Findings are stored on
the revision and shown in the review UI. **Deterministic failures block the
revision from `ready`; faithfulness findings are flagged for the owner, not
auto-rejected.**

Deterministic checks, run on every revision including manual edits:

- **IDs resolve** against the referenced master CV version, and nothing marked
  `TODO` was selected.
- **Rewordings carry `supports`**, and every supporting ID exists.
- **Technology-to-role**: a vocabulary built from the skills index; any
  technology term in a reworded item must appear in that role's stack.
- **Versions**: any version number in output must match the source for that
  role.
- **Do not claim**: no term from the "Do not claim" section appears anywhere in
  the output.
- **Independent projects stay independent**: content from them never appears
  under an employer.

Faithfulness check, for rewordings and the summary only: a live call to the
small validation model asking whether the text asserts anything its cited
sources do not. Live rather than batched — it is cheap, and batching it would
double the time to a ready CV.

## Rendering

The validated selection is resolved against the master CV into a complete
document model (`resolved_json`), rendered to HTML with Blade, and converted to
PDF by Gotenberg. Section headings and other template words come from
`lang/en_GB`. Other output formats (e.g. `.docx`) render from the same
resolved model.

## Review UI

Filament, for one user. It shows:

- Jobs, filterable by status and source, with the hidden-text warning.
- Per job: the job description beside the current revision's PDF; the
  requirements checklist (met / partial / gap, linked to evidence); validation
  findings; the model's review notes.
- The revision tree, with diffs between any two revisions at two levels: a
  structural diff (items added, dropped, modes changed) and a text diff where
  wording differs.
- Actions: regenerate with instructions, edit (creating a child), generate now,
  mark applied (recording `applied_revision_id`), discard.

## Testing

- **No test calls a live model.** A `FakeLlmClient` returns recorded fixture
  responses. Fixture responses are recorded against the fictional master CV
  only.
- The validators carry the weight of the fabrication guarantee, so they carry
  the weight of the tests: each check has cases that must pass and cases that
  must fail, built from the fictional CV.
- The parser and linter are tested against the fictional CV and against
  deliberately broken variants.

## Briefing gaps

- **Commands.** The containerised commands for running tests, formatting,
  static analysis, `cv:lint`, `cv:status`, creating the owner account and
  issuing an extension token. To be added by the scaffold.
- **Fictional master CV fixture.** The fixture exists and exercises every
  format feature; record its path and what each part of it is for.
