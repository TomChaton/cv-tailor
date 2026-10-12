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
  **Skills-index rows are the exception**: their ID is a slug of the skill's
  name, because a skill *is* its name — renaming one is changing which skill it
  is — and deriving them keeps a few hundred units out of the set the author
  maintains by hand. The cost is bounded and accepted: reword a skill and an
  old revision cites an ID that no longer resolves, the validators say so, and
  rendered PDFs are untouched.
- **Recognised sections** are found by heading: agent rules, contact, headline
  titles, profile facts, skills index, employment history, independent
  projects, awards, interests, and "Do not claim". Their roles are below.
  Awards, interests and independent projects are things a person may not have,
  so their absence is a fact about them rather than a defect in the file; the
  rest are required.
- **Section headings are matched tolerantly** of a trailing parenthetical or
  dash qualifier, because real headings carry them ("Headline titles (any may
  be used)"). Matching on the exact string fails on the file people write.
  A heading the parser does not recognise closes the section above it rather
  than extending it: content filed under a heading of the author's own is not
  content they filed under the previous one.
- **A bullet's meaning comes from the labelled block above it.** Under "Tech
  stack" it is inventory and not selectable; under "Context" it is prose;
  under anything else it is a selectable achievement. Labelled metadata
  bullets (`- **Dates:** …`) are identified by the colon sitting *inside* the
  bold markers, which is what separates them from an achievement opening with
  a bold lead-in. **An unrecognised block label is reported and never
  refused** — its bullets are achievements, which is almost always what was
  meant, and a format that rejects a heading its author invented is a format
  its author stops maintaining. The same applies to metadata keys and to front
  matter keys the parser does not know.
- **The skills index has two row forms** — a two-column table of skill and
  where it was used, and a plain bullet list — and both are supported, because
  both are what people write. A list row often says nothing about where the
  skill was used, and a row claiming no evidence has none to check.
- **Unknown-detail markers** — `TODO`, and phrases such as "not recorded" or
  "do not elaborate" — are part of the format. Content carrying `TODO` is never
  selectable. Content carrying "do not elaborate" may be used verbatim only.
  Both are inherited by sub-bullets: a child of an unknown bullet is as unknown
  as its parent.

### Three kinds of content

The parser separates what the file mixes:

- **Rules** — the agent rules section and the "Do not claim" section. Sent to
  the model verbatim, and also the source of deterministic checks.
- **Facts** — roles, achievements, skills, projects, profile facts, headline
  titles. The only material the model may select from.
- **Identity** — the contact section. **Never sent to the LLM.** The rendered
  CV header comes from these fields through the template, so the model cannot
  mistype a phone number it never sees.

The parse keeps identity in one place and offers the rest as the only material
bound for the model, so leaking it takes a deliberate act rather than an
oversight. The front matter goes with identity rather than with the facts: it
is document bookkeeping — who the CV is for, when it was last touched — and
only the presentation policy derived from it is of any use downstream.

### Presentation policy

Generation needs settings the CV's facts do not hold: target length, default
role modes, and which headline titles are on offer. These live in the CV's
front matter so each user's policy travels with their CV.

**The policy is optional, and absent is the normal case**, so the defaults are
the ordinary path rather than a fallback and their absence is never a lint
failure. With no cutoff years configured every role defaults to `full`: that is
the honest reading of an author who has said nothing about length, and the safe
one, since a default of `full` cannot hide a role they wanted shown.

**Role modes are driven by two cutoff years** — a role ending before the first
defaults to `condensed`, before the second to `omitted`. Two are needed because
the model may only promote a role above its default: with one cutoff `omitted`
is unreachable and every role appears on every CV.

### Linting

`php artisan cv:lint` checks a master CV before it is used, and every
generation run refuses a CV that fails it:

- IDs present on every selectable unit, unique, and well-formed.
- Every skills-index "where used" entry names a role or project that exists,
  and every technology in a role's tech stack appears in the skills index.
  The index and the stacks state the same facts from opposite directions and
  will drift; the linter is what stops that.
- Recognised sections present.

A finding is a **problem** or a **note**, and only problems fail the lint.
Notes are for what the author chose and the tool merely does not know: a
one-off block label, a part of a "where used" cell that names nothing while the
rest of it names a real role.

**The index and the stacks are never written in the same words** — the index
says "AWS SQS (incl. dead-letter replay)" where a stack says "SQS" — so the
coverage check matches generously: a skill contributes its name without
parentheticals, its parentheticals' contents, and the whole row as aliases; a
term matches when its words are among an alias's; and failing that, version
numbers are dropped from both sides. The question this check asks is whether
the index knows the technology at all. Whether a version is right for a role is
a different question, asked of generated output by a check of its own.

**That generosity is calibrated for a lint and must not be carried into a
validator.** Matching is subset-based, so across a few hundred rows the union
of words is large enough that a single-word term passes readily — including a
word that appears in a row for a negative reason. That is the right trade for
asking whether the index knows a technology at all, and the wrong trade for
asking whether a reworded bullet may claim one: **the technology-to-role check
on generated output must not reuse this vocabulary unexamined.** A loose
vocabulary costs tidiness in a lint and costs the guarantee in a validator.

Lint output names the line and the problem. It is written for the CV's author,
not for a developer. The exit code is non-zero on failure, so generation and
the scheduler can both rely on it.

### Bootstrapping the IDs

`php artisan cv:id <source> [destination]` writes the IDs a CV is missing and
emits the whole document, to a path or to standard output. It exists because no
CV can be generated from until IDs exist, and a CV written before them has
none.

- **It never writes in place.** The master CV's mount is read-only by
  deliberate decision, and this command is not the reason to undo that. The
  author reads a diff and adopts the result themselves.
- **It is idempotent**, and preserves everything but the IDs it adds, so the
  diff the author reads contains nothing else.
- **A role takes a slug of its employer; a bullet takes a number**, counted on
  from the highest its siblings already use. Nothing of a bullet's wording is
  in its ID, so no rewording can make an ID look wrong and tempt the author
  into "fixing" it — which is the one change that would break the history.

### Versioning

The CV file on disk is the editing surface; the database holds what was used.
At each generation the parsed CV is hashed and stored in `master_cv_versions`
(hash, raw content, parsed structure), deduplicated by hash. Every revision
references the exact version it was generated from. This does not depend on
the user keeping their CV in git.

**The parse is hashed rather than the file**, so what identifies a version is
what was used rather than the bytes it was read from. Line numbers are part of
the parse — lint and the review UI address facts by line — so a reflow produces
a new version. Erring towards a new version costs a row and never misattributes
a revision.

### The fictional fixture

`tests/Fixtures/master-cv.md` is a fictional CV that exercises every feature of
the format, and doubles as the starter template for anyone else running this.
It reads as a plausible CV rather than as a test matrix, because a template
nobody would want to edit is a template nobody adopts. In it:

- **Front matter** carries a populated presentation policy and keys the parser
  ignores.
- **Skills index**: four table-form subsections and two list-form ones, one of
  which has rows with no "where used" at all.
- **Roles**: one with a context block and ones without; metadata keys beyond
  those the tool knows; a one-off block label; sub-bullets; and four roles whose
  end years exercise `full`, `condensed` and `omitted` against the policy's
  cutoffs.
- **Independent projects**: one with blocks and a labelled value on a line of
  its own, one whose tech stack is written as a metadata bullet instead.
- **Markers**: `TODO`, "not recorded" and "do not elaborate", each on content
  that shows what it does to selectability.
- **Optional sections** — awards, interests, independent projects — are all
  present, so what the parser does with them is covered.

`tests/Fixtures/master-cv/minimal.md` is the smallest CV that passes lint, and
`tests/Fixtures/master-cv/broken-*.md` are variants of it that each differ by
one line: a duplicate ID, a missing ID, a skills row naming a role that does
not exist, a tech-stack entry missing from the index, a missing section. One
defect per file, one line apart from a file that passes, so what a check is
reacting to is visible in a diff.

No part of any fixture derives from a real CV.

## Data model

### Postings, not jobs

The captured entity is a **`posting`** throughout the schema, the code, the log
events and the context keys. Laravel's `database` queue driver owns the tables
`jobs`, `job_batches` and `failed_jobs`, so a domain table called `jobs` would
sit beside the framework's own and a `job_id` in a log line would be ambiguous.
For the same reason the provider batch table is **`llm_batches`** with the
context key `llm_batch_id`: `batches` reads as Laravel's `job_batches`.

Laravel's own queue tables keep their framework names, and `pending_jobs` and
`failed_jobs` in the worker heartbeat are queue jobs and are correct as they
stand.

**The word on screen stays "Jobs."** The owner browses job boards and thinks in
jobs, so that is what `lang/en_GB` says. `postings` in code, "Jobs" on screen —
deliberately, and not to be made to agree.

- **`postings`** — a captured posting: URL, source site, title, company,
  location, salary text, raw HTML, extracted text, hidden-text flag, dedupe
  hash, `status`, `current_revision_id`, `applied_revision_id`.
- **`revisions`** — one tailored CV for a posting.
  - `posting_id`, `parent_revision_id` (nullable — revisions form a tree, not a
    list), `master_cv_version_id`.
  - `source`: `generated` | `regenerated` | `manual_edit`.
  - `instructions` (nullable) — the owner's steer for a regeneration.
  - `model`, `prompt_version`, token usage including cache reads, cost.
  - `output_json` (the model's selection), `resolved_json` (selection resolved
    against the master CV), `validation_json` (findings), `pdf_path`.
  - `status`: `pending` | `submitted` | `generated` | `validated` | `ready` |
    `failed`; `attempts`; `error`.
- **`master_cv_versions`** — as above.
- **`llm_batches`** — provider batch ID, state, submitted/ended times, request
  count, linked revisions.

### Revisions are immutable once complete

A revision that has reached `ready` or `failed` is never updated. Editing or
regenerating creates a child revision. This is what makes the history
trustworthy and diffs between revisions meaningful.

**The rule is enforced in the model, not the schema.** No constraint can
express it: it is a rule about the value a column is moving *away* from, so the
guard reads the stored status rather than the one being written. A revision may
therefore be moved *into* `ready` or `failed`, and never out again, and a
complete revision may still gain children. The guard lives in an observer
rather than in the model body, because it needs the event logger and observers
are resolved from the container.

**The refusal raises.** A write dropped in silence leaves its caller believing
it changed history, and a history nobody can trust is worse than one that is
missing. It is logged as well as raised, under `revision.mutation_rejected`.

Eloquent fires no model event for a query-builder update, so the guarantee
covers writes that go through the model. Revisions are written by this
application's own actions, and those go through the model.

`current_revision_id` is the one the owner is looking at; `applied_revision_id`
is the one they actually sent. They are separate because the version that went
to an employer is the one that matters later.

### A posting carries no foreign key to its revisions

`postings.current_revision_id` and `postings.applied_revision_id` are plain
nullable integer columns, indexed, with no constraint behind them. The
reference is circular — a posting points at a revision that points back at the
posting — so one of the two tables has to be created before its target exists,
and SQLite cannot add a constraint to a table afterwards. Nothing here may
depend on SQLite-specific SQL, which rules out the driver-specific escapes, so
the relationship is enforced in code instead.

### Cost and token usage

**Cost is an integer in micros.** Money is never a float, and millionths of a
currency unit are fine enough for a per-revision LLM charge.

**Token usage is four named columns** — input, output, cache read, cache write
— rather than one JSON blob. A cost report over them is then a sum rather than
a walk over JSON, and a column that is summed is a column worth naming.

### Posting status

`captured → queued → ready → reviewed → applied`, plus `discarded`. A posting
is `ready` when its current revision is `ready`. The list doubles as an
application tracker.

**The status is stored even though `ready` could be derived** from the current
revision. `reviewed` and `applied` are things the owner did, which no revision
knows about, and the column is what makes the list an application tracker
rather than a view of the pipeline. The cost is that `ready` is denormalised
and has to be kept in step with the current revision.

Both posting status and revision status are PHP enums, cast on the model, so a
state that exists only in a string literal cannot be invented. Each reports a
count per state in `cv:status`, including the states nothing is in: a state
absent from the report cannot be told apart from one that has been renamed.

### Deduplication

The same role appears on several boards. Capture computes a hash over the
normalised title, company and description text; a capture matching an existing
posting returns that posting rather than creating another. Agency reposts with the
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

Filament, for one user, mounted at **`/review`** on the same origin as the API.
The owner signs in with an ordinary session login; `/` redirects there, because
the review UI is the only thing this application serves to a browser.

It shows:

- Postings, filterable by status and source, with the hidden-text warning.
  The screen calls them Jobs.
- Per posting: the job description beside the current revision's PDF; the
  requirements checklist (met / partial / gap, linked to evidence); validation
  findings; the model's review notes.
- The revision tree, with diffs between any two revisions at two levels: a
  structural diff (items added, dropped, modes changed) and a text diff where
  wording differs.
- Actions: regenerate with instructions, edit (creating a child), generate now,
  mark applied (recording `applied_revision_id`), discard.

## Observability

- **Log lines are written through one class**, so `event` is never optional.
  The Monolog formatter puts the event first and lifts context keys to the top
  level, so one grep matches both an event and the object it concerns. A line
  that arrives without an event is named `log.unnamed`: such a call is a bug,
  and naming it makes it findable rather than letting it pass as ordinary.
  Reported exceptions carry `exception.reported`, because an exception is a log
  line like any other.
- **The heartbeat timestamp lives in the cache**, under a key per process. It
  has to outlive the process that wrote it and be readable from a different
  container, and the cache table is already part of Laravel's own schema, so it
  buys both without a table of its own. The cost is that clearing the cache
  resets the report; that is cheap next to owning schema for it.
  Stored without expiry: an entry that aged out would be indistinguishable from
  a process that had never reported.
- **Heartbeats are bucketed on the clock minute**, not on elapsed seconds. An
  elapsed window skips a minute whenever the caller's own interval jitters just
  under it, and a gap in a heartbeat reads as a stopped process.
- **The worker's heartbeat hangs off the queue's `Looping` event**, which fires
  whether or not there is work, so an idle worker still reports. The
  scheduler's is a scheduled task, so it reports only while the scheduler is
  actually running tasks.
- **`cv:status` is a list of sections in `config/cv.php`.** Each is a class
  with a heading and label-to-value rows. Anything with a state machine appends
  a section and leaves the ones above it alone.
- **Artisan command output is developer-facing** and does not live in a
  language catalogue. `lang/en_GB` is for words the owner reads in the review
  UI.

## Testing

- **No test calls a live model.** A `FakeLlmClient` returns recorded fixture
  responses. Fixture responses are recorded against the fictional master CV
  only.
- The validators carry the weight of the fabrication guarantee, so they carry
  the weight of the tests: each check has cases that must pass and cases that
  must fail, built from the fictional CV.
- The parser and linter are tested against the fictional CV and against
  deliberately broken variants.

## Commands

Run from the repository root.

The development toolchain is a Compose service, `toolchain`, built from the
same Dockerfile as the application but carrying no source: it bind-mounts the
checkout, so an edit needs no rebuild. It sits in the `tools` profile, so
`docker compose up` never starts it.

| Task | Command |
| --- | --- |
| Install dependencies | `docker compose run --rm toolchain composer install` |
| Run the tests | `docker compose run --rm toolchain php artisan test` |
| Format | `docker compose run --rm toolchain vendor/bin/pint` |
| Check formatting only | `docker compose run --rm toolchain vendor/bin/pint --test` |
| Static analysis | `docker compose run --rm toolchain vendor/bin/phpstan analyse --memory-limit=1G` |

These run against the **running** stack and so use `exec app`, not the
toolchain:

| Task | Command |
| --- | --- |
| What the stack is doing | `docker compose exec app php artisan cv:status` |
| Create or update the owner | `docker compose exec app php artisan cv:owner` |
| Issue an extension token | `docker compose exec app php artisan cv:token` |
| Check the master CV | `docker compose exec app php artisan cv:lint` |
| Add the IDs a master CV is missing | `docker compose exec app php artisan cv:id /var/lib/cv-tailor/master-cv.md > master-cv-with-ids.md` |

`cv:owner` prompts, and takes `--name`, `--email` and `--password` when a
script needs it. `cv:token` prints the token once; only its hash is stored.

`cv:lint` checks the mounted CV, and takes a path when another file is wanted.
`cv:id` reads the mounted CV and writes elsewhere — standard output above, or a
path given as a second argument; the mount is read-only, so there is nowhere
inside the container to write it but `/tmp`.

### Two things that are easy to get wrong

- **The test suite supplies its own environment** and is handed no `.env`, so a
  result never depends on how a particular machine is configured. Test values
  belong in `phpunit.xml`.
- **Configuration is never cached.** It arrives as container environment
  variables, and a cached config file would freeze whatever happened to be set
  when the image was built.
