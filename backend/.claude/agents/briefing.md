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
  a bold lead-in. That colon does not decide alone: **an ID is what
  distinguishes a selectable unit from a metadata field.** A field never
  carries one and a unit always does, so a labelled bullet with a trailing
  `{#id}` is an achievement whose text opens with a bold lead-in — ordinary CV
  writing — and not a field whose name happens to be a sentence. On the colon
  alone, a unit the author had identified is filed as a field and lost, which
  is the one kind of loss nothing downstream can detect: nothing downstream
  knows it existed. **An unrecognised block label is reported and never
  refused** — its bullets are achievements, which is almost always what was
  meant, and a format that rejects a heading its author invented is a format
  its author stops maintaining. The same applies to metadata keys and to front
  matter keys the parser does not know.
- **A bullet's continuation lines are joined onto it before its ID is split
  off**, so a bullet the author wrapped reads as an unwrapped one does. A
  wrapped bullet carries its `{#id}` at the end of its last line, and
  splitting line by line leaves the ID buried in the text and the unit with
  none. A lint then reports a missing ID against the line the bullet starts on
  while the ID sits further down, which sends the author to edit a line that
  is already right — worse than saying nothing.
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

Generation and rendering need settings the CV's facts do not hold: target
length, default role modes, which headline titles are on offer, which contact
fields the CV's header carries, and whether awards and interests are shown.
These live in the CV's front matter so each user's policy travels with their CV.

**The policy is optional, and absent is the normal case**, so the defaults are
the ordinary path rather than a fallback and their absence is never a lint
failure. With no cutoff years configured every role defaults to `full`: that is
the honest reading of an author who has said nothing about length, and the safe
one, since a default of `full` cannot hide a role they wanted shown.

**Role modes are driven by two cutoff years** — a role ending before the first
defaults to `condensed`, before the second to `omitted`. Two are needed because
the model may only promote a role above its default: with one cutoff `omitted`
is unreachable and every role appears on every CV.

**`contact_fields` names the contact labels the header prints**, in the order
it prints them, and defaults to location, phone, email and LinkedIn. A contact
section is the source of answers to application forms as much as it is a CV
header: it holds a postal address, a right to work, a notice period, and the
author's own labels often mark those as conditional ("use only if a form
requires it"). A header is not where a form's answers belong, and the
alternative — printing everything, or ranking labels in code — either clutters
the page or invents a taxonomy the author never wrote. The name is the
document's title, so it is not one of the four.

Labels are **matched forgivingly of their exact wording**, the way section
headings are, so a policy naming `LinkedIn` finds a field written `LinkedIn
profile`. A named field the CV does not have is **skipped rather than
reported**: the policy is a preference about a header, and a CV with no phone
number is a fact about its author rather than a defect in the file.

**`include_awards` and `include_interests` switch those sections, and both
default to on.** The output schema has no field for either, so the model cannot
select them and the only thing that can drop one is this key; a default of off
would quietly lose a section the author wrote. Anything but an explicit no
reads as yes, for the same reason. Letting the model choose would be a schema
change and a `prompt_version` bump.

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

- **Front matter** carries a populated presentation policy — including a
  `contact_fields` list that is not the default and both section switches, one
  on and one off — and keys the parser ignores.
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

**The fictional CV is byte-identical to what `cv:id` writes from it with its
IDs stripped**, which is what makes it a starter template rather than only a
test input. Two shapes cannot live in it without giving that up: a bullet whose
ID sits on a continuation line, because `cv:id` writes an ID on the line a
bullet starts on; and a labelled bullet, because without an ID it reads as a
metadata field and so is never offered one. Both are consequences of
bootstrapping IDs from a file that has none, and neither is a reason to weaken
the round trip, so they live in `tests/Fixtures/master-cv/labelled-and-wrapped.md`
instead — a CV that passes lint and whose only purpose is that the ordinary
parse covers them.

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
  count.
- **`llm_batch_revision`** — one row per submission: which batch carried which
  revision, and what the provider made of it. See The join table carries an
  outcome per submission.

**A posting's URL is `text`, not a varchar.** A job board's URL carries session
keys and tracking parameters, and passes 255 characters routinely. SQLite
enforces no varchar width, so a narrower column holds everything right up until
the first database that does enforce one — and moving to one of those is meant
to be a configuration change rather than a data loss.

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

**It is kept in step by the step that renders.** Putting a revision at `ready`
is the one moment the denormalised column is known to be out of date, so the
render carries the posting with it — the same reasoning that queues validation
where the output is stored, rather than from the caller that stored it. Every
path that produces a PDF then moves the tracker without having to remember to,
and a posting whose CV is rendered never reads `queued`.

**A posting moves to `ready` only from `queued`**, and only on its *current*
revision. `reviewed` and `applied` are the owner's own states, which no
revision knows about, so a render arriving afterwards must not drag the tracker
backwards; `discarded` is the owner's too. A revision the owner is not looking
at says nothing about where the posting has got to.

**The whole condition is one conditional update**, as the queue's claim is: a
posting that has moved on, or is already `ready`, matches no row, so a second
run writes nothing and logs nothing. A duplicate dispatch is the ordinary
consequence of a retried job, so nothing may depend on the transition happening
exactly once.

Both posting status and revision status are PHP enums, cast on the model, so a
state that exists only in a string literal cannot be invented. Each reports a
count per state in `cv:status`, including the states nothing is in: a state
absent from the report cannot be told apart from one that has been renamed.

### Deduplication

The same role appears on several boards. Capture computes a hash over the
normalised title, company and description text; a capture matching an existing
posting returns that posting rather than creating another. Agency reposts with the
client anonymised will not match, and that is accepted.

**The URL is not part of the hash.** The same posting under two URLs is one
posting, and a retry the extension never saw the answer to is the same capture
rather than a second one. Normalising case and whitespace is part of the same
judgement: neither changes what a posting says.

The parts are joined by a separator that cannot occur in any of them, so no
wording can shuffle words across the boundary between title and company and
collide with a different posting.

## Capture

The endpoint's shape and its status codes are in the system briefing, with the
API contract. What belongs here is where the pieces live.

- **A form request holds the rules, and the rules are the contract.** The
  OpenAPI document's request body is generated from them, so a field added to
  the form request reaches the extension's client when the document is
  regenerated. There is nowhere else to state the shape of a capture.
- **A rejected capture is a warning in the logs, naming the fields only.** The
  values are the posting's own text. Without the line, an extension whose
  extraction has broken fails silently on the only side anybody is watching.
- **The body limit is middleware, not a rule.** A field rule is checked after
  the body has been read and decoded, which is exactly the cost the limit
  exists to avoid. A body with no declared length is left to PHP's
  `post_max_size`, which is the only ceiling available before the bytes arrive.
- **The action's result carries the posting's identity rather than the
  posting.** That is all an answer to a capture needs, and keeping Eloquent out
  of the response path is what lets the OpenAPI document be generated from the
  types alone — see below.

### The OpenAPI document

Generated from the routes, the form requests and the controllers, and
committed, so the extension's client can be built from it without this
application running.

- **`php artisan cv:openapi` regenerates it**, and **a test fails when the
  committed file and the code disagree.** A contract nothing checks is a
  contract that drifts: the backend changes and the extension carries on being
  built against the old shape.
- **The command provisions a throwaway in-memory database and migrates it.**
  The generator types a document by reading Eloquent models' own schema, so it
  needs one; the document describes the code, and must not come out differently
  on a machine whose database is behind, missing or full.
- **Refusals are read off the code rather than asserted beside it** — `401`
  from the route's `auth:sanctum`, `422` from the form request's rules, `413`
  from the presence of the body-limit middleware. A document maintained by hand
  beside the code is a document that disagrees with it.

## Generation

### Queueing

`captured → queued` is a scheduled step of its own and not part of capture. It
needs a master CV that parses and lints, and capture must succeed without one:
a posting found while the CV is mid-edit is still a posting worth having. So
capture records a posting and nothing else, and a later run decides it is ready
to be generated from by giving it one `pending` revision pointing at the
version of the CV it will be generated from.

- **A master CV that cannot be used leaves the postings at `captured`**, rather
  than failing them. Nothing is lost and nothing has to be retried by hand:
  the first run after the CV is fixed picks up everything that was waiting. It
  is an alarm all the same — the system is waiting on the owner, and a silent
  wait is indistinguishable from a stopped one — so the run warns, and
  `cv:queue` exits non-zero for whoever ran it by hand. The warning is the
  whole alarm: a scheduled run does not also report a failure, which would
  name no command and carry a stack trace that is all framework.
- **A run with no work does not read the CV at all.** A CV mid-edit is not
  worth an alarm until something is waiting on it.
- **The CV is parsed once per run, not once per posting.** A run that queues
  five postings reads and lints one file.
- **A posting is claimed with a conditional update** — status `captured` to
  `queued` — and the revision is created inside the same transaction. Two runs
  at once therefore cannot both give one posting a revision, and the claim is
  ordinary SQL rather than a row lock, which SQLite does not have.
- **A pending revision already names a model and a prompt version**, because
  neither column may be null. Neither is a fact yet: the prompt version is
  written again when the request is built and the model when the response is
  stored, since a revision that waited in `pending` across either change was
  generated by the new one. What a pending revision carries is what would
  generate it now.

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

**The ID enums are scoped to the kind each field takes**, and live in `$defs`
so a few hundred IDs are listed once rather than at every field that takes one.
Skills-index rows in `key_skills`, employment roles in `roles`, independent
projects in `independent_projects`, bullets in a selected item, roles and
projects in a promotion. Only evidence — `summary.supports`, an item's
`supports`, a requirement's evidence — takes the broad list of every ID,
because evidence legitimately spans every kind.

So an enum guarantees that an ID **exists** in the master CV and, where the
field is scoped, that it is **of the right kind**. It does not guarantee that
the ID **belongs** where it was used: an achievement of one employer's filed
under another is a misattribution within a kind, and catching that is the
validators' work. One broad list would have been smaller and would have given
up the kind as well, leaving three classes of type confusion — a skill offered
where a role belongs, and the reverse — for a later step to maybe catch.

**The two containers have shapes of their own** rather than sharing one, since
the kind of ID is what differs between them. The duplication buys an
impossibility: an independent project cannot be returned under an employer, so
one of the deterministic checks is enforced a layer earlier.

A `$ref` carries no sibling keywords — not every reader of JSON Schema takes
them — so anything that needs saying about a referenced field is said in the
prompt.

**Content marked `TODO` is left out of the enums entirely**, rather than
offered and flagged. The author has said the fact is unknown, so there is
nothing to offer, and anything selectable is eventually selected.

**A schema the provider has not seen before costs one slow first request**
while it is compiled; the compilation is then cached for a day. Editing the
master CV therefore carries a small one-off cost rather than a standing one.

**The model never controls role order or dates.** Roles render in reverse
chronological order with dates from the parsed CV, whatever the model returns.

**Code computes each role's default mode** from the presentation policy's two
cutoff years. The model may **promote** a role — `omitted` to `condensed`,
`condensed` to `full` — and must give its reason in `review_notes`. It may not
demote. The schema cannot express that: the mode enum is the same three values
for every role, and constraining it per role would mean a separate object shape
per role. So the direction is checked on the way back, where a demotion is a
validation finding and so is a promotion with no reason given.

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
instructions. **A revision records it when its request is built**, not when the
revision was created: one that waited in `pending` across a prompt change is
generated by the new prompt, so the new version is what it was generated with,
and recording it any earlier records an intention rather than a fact.

**The word budget is a soft target.** The policy's target length reaches the
prompt as a number of words, with the instruction to grow more selective as it
is approached. Structured outputs cannot express a length, so nothing enforces
it, and **nothing is truncated** — a cut CV loses half a claim and keeps the
half that reads as finished. Output over its budget is a validation finding for
the owner to weigh. A response has a token ceiling all the same, because an
uncapped request is unbounded; one that reaches the ceiling comes back cut off
mid-document and is reported as unusable rather than stored.

**Generation refuses a master CV that fails `cv:lint`.** The version was linted
before it was stored, and the check is repeated where the request is built:
that is the last point before a request leaves, and a CV with duplicate or
missing IDs produces a selection nobody can resolve back to a fact.

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

Generation uses the provider's batch API: nothing is interactive, and a batch
costs half as much as the same requests sent live.

- **Submit** (scheduled): gather every `pending` revision and send it as one
  batch. Each request's custom ID is the revision's ID.
- **Poll** (scheduled): ask about every batch that still owes us results, and
  drain every ended one in the same run. Streaming a batch's results dispatches
  one queued job per result; the job applies it.
- A batch that stays open beyond a configured age is reported by `cv:status`
  and logged as a warning: an uncollected batch eventually expires.
- **"Generate now"** in the review UI bypasses batching with a live call for a
  single revision, for deadlines.

**The batch API's discount applies to a batch of one, so there is no minimum
batch size and no maximum wait.** A quorum would buy nothing and cost every
revision a wait for company that may never arrive. A batch of one is the normal
case here, which is also why a single bad batch is not evidence of anything.

**The request a revision is submitted with comes from an injected contract**,
implemented by generation. The batch lifecycle moves revisions and batches
between states and knows nothing about prompts; generation knows about prompts
and nothing about batches. One interface is cheaper than either reading the
other.

**The revisions are claimed after the provider has accepted the batch**, not
before. A failure on the way out then leaves every revision `pending`, which
the next run picks up — where claiming first would leave them `submitted` with
no batch to collect them, a state nothing in the system moves out of. The cost
is the other way round: a crash between the provider accepting a batch and the
claim being written loses track of a batch that was paid for, and the client's
own `llm.batch_submitted` line carries the provider's ID, which is the one
thing needed to find it again.

#### `ended` and `collected` are separate states

The provider reports that it has finished; only we know whether the results
were actually taken. Collapsing the two would make an uncollected batch look
finished right up until its results expired — and a batch that is submitted,
paid for and never collected is the expensive failure here, not a request that
errors.

A batch's terminal state says what became of its results rather than only that
they were read: every request expired makes the batch `expired`, every request
errored makes it `failed`, and anything else is `collected`. A reader of
`cv:status` then sees a batch that expired as expired without counting rows.

#### The join table carries an outcome per submission

`llm_batch_revision` holds one row per *submission*, not per revision, so a
revision that expires in one batch and goes out again in the next has two rows
and the history of which batch carried which attempt survives.

**The `outcome` column is what makes collection resumable.** A row stands at
`awaiting` until a result has been applied to it, so a poller that died halfway
through a batch re-runs and dispatches only what is left — applying nothing
twice and dropping nothing. The batch closes only once no row is awaiting,
which is also why it cannot close in the same run that dispatched the work.

#### A result is applied only to a revision still awaiting it

The submission row must still be `awaiting` and the revision must still be
`submitted`. The case that makes the guard necessary: a revision goes out in
one batch, that batch expires, the revision returns to `pending` and goes out
again — and then the first batch's results arrive after all. Keyed only on the
custom ID, the stale output would be written over a revision that has moved on.

The guard is a conditional update of the submission row, so two runs holding
the same result cannot both pass it. The row is claimed before the revision is
looked at, because the outcome is a fact about the submission whatever the
revision has since become; a row left `awaiting` would hold its batch open for
ever. A result that is dropped is logged, because one dropped in silence is
indistinguishable from one that never arrived.

#### What each result does to a revision

| Result | Then |
| --- | --- |
| `succeeded` | Output and usage stored, revision `generated` |
| `succeeded` but unusable | Back to `pending`, an attempt spent |
| `errored`, server-side | Back to `pending`, an attempt spent |
| `expired` or `canceled` | Back to `pending`, an attempt spent |
| `errored`, malformed request | Stays `pending`, attempts untouched, alarm raised |
| An attempt spent past the limit | `failed`, with `error` recorded |

**A malformed request leaves the revision `pending` with its attempts
untouched.** The request we built was refused, so the revision is not at fault,
and `failed` is terminal and immutable: driving it there would leave a child
revision to create for every posting caught by one bug. Left `pending`, a fix
and a re-arm pick up everything that was waiting, intact. An attempt that is
never spent also means this failure can never exhaust the limit.

**A response that cannot be read as a selection spends an attempt.** It is what
a response that reached the token ceiling looks like — cut off mid-document —
and the answer is a larger ceiling. The limit is what stops the same truncation
being bought over and over.

#### The submitter stops itself

**Two consecutive batches whose every result is a malformed request halt the
submitter.** One such batch is more likely a single pathological job
description than a broken schema, and because a batch of one is normal here, a
single-batch rule would let one oversized posting stop all generation. A batch
the provider was willing to serve breaks the streak, and a batch collected over
more than one run counts once.

**The poller is never halted.** Batches already in flight still need
collecting, and stopping both would turn a bug in the request builder into work
paid for and thrown away.

The halt lives in the cache, keyed as the heartbeats are, so it survives a
container restart without owning schema, and **only `cv:batch-resume` clears
it** — not a deploy, not a restart, not a timer. A state that cleared itself
would let a restart quietly resume submitting requests the provider refuses.
The halt is an error in the log when it happens and a line of its own in
`cv:status` for as long as it lasts: a stopped submitter has to be the first
thing a reader sees rather than something inferred from a pile of pending
revisions that are not moving.

### LLM client

The provider sits behind an `LlmClient` interface (`submitBatch`, `pollBatch`,
`fetchResults`, `complete`), with model names in configuration — one for
generation, one for validation. Swapping providers is a new implementation,
not a refactor. The default implementation targets Anthropic's API through the
official PHP SDK rather than hand-rolled HTTP, and is the only part of the
application that names a provider type.

- **`fetchResults` streams**, and a caller keys results by the custom ID the
  result carries. A batch result set is unbounded in principle, so reading one
  into memory is a trap waiting for a longer CV; and the provider returns
  results in any order, so position-indexing is a bug that only shows up once a
  batch holds more than one request.
- **A malformed request is distinguishable from a provider-side failure** at
  the client boundary — a bad request, a rate limit and a server error are
  three separate failure classes, carried on both a thrown failure and an
  `errored` batch result. The batch lifecycle retries one of those and must
  never retry the other, and it cannot tell them apart if the client flattens
  them.
- **Retries are not the client's job.** Failures are reported, not retried. The
  revision's `attempts` counter is the single place an attempt is counted, and
  a retry buried in the client would hide attempts from the state machine that
  counts them.
- **Provider exceptions are translated into the application's own failure
  type**, so no caller ever names an SDK class. The translation keeps the
  provider's request ID, which is the only thing that lets a provider-side
  problem be traced, and deliberately drops the provider's error body: an
  error body can quote the request that caused it, and a chained exception is
  reported with its whole chain.
- **The batch API refuses some live-only request parameters**, so a request is
  built only from the fields the pinned SDK accepts on both paths. Which fields
  those are is read off the SDK after an upgrade, not assumed.
- **The provider's SDK client is built on first use, not when the client is
  injected.** Scheduled work resolves the client to find out whether it has
  anything to do, and a run with nothing to do must not need credentials.
  Built eagerly, an instance whose API key is not set yet reports a stack trace
  every few minutes for runs that were never going to call anybody — which
  teaches the owner to ignore the one channel that has to stay worth reading.
  So the implementation holds a factory and resolves it once, at the first call
  that needs the provider, and ordinary constructor injection stays right for
  every caller. **Whether the key is set is a precondition, and a precondition
  belongs in `cv:status`** beside the master CV's state, where it can be seen
  and fixed. The key itself is never printed, in part or in whole.

#### Cost and rates

- **Per-model cost rates live in configuration**, beside the model names. They
  change without notice, and a rate compiled into the code misreports forever
  with nothing in the system to say so. A model with no configured rate raises
  rather than costing nothing.
- **Cost is computed and returned in integer micros**, matching the
  `cost_micros` column: money is never a float, and a rate is expressed per
  million tokens so the arithmetic stays integer. The division is rounded up,
  so a cost is never reported as less than it was.
- **Cache-write and cache-read tokens are reported separately**, because the
  two are priced differently and a revision that cannot tell them apart cannot
  report what it cost. The configured cache-write rate is the one-hour-lifetime
  rate, since that is the only lifetime this application asks for.
- **The batch API's discount is configuration as well**, as a percentage of the
  live rate rather than a multiplier, and a response knows whether it came from
  a batch. A batch price applied to a live call, or the reverse, is wrong by a
  factor the owner would never notice.

## Validation

Validation runs after generation and before rendering. Findings are stored on
the revision and shown in the review UI. **Deterministic failures block the
revision from `ready`; faithfulness findings are flagged for the owner, not
auto-rejected.**

**Every check runs on every revision, whatever produced it**, including a
manual edit. The guarantee cannot depend on where content came from: words the
owner typed are no better evidenced than words a model wrote, and a manual edit
has met no output schema at all, so it is the revision that needs the checks
most.

The checks read a parse and an output and return findings — no database, no
log, no status. That is what lets the same checks be re-run over a revision that
already exists, against a master CV version it was not generated from, to see
what a change to the CV or to a check would say about it.

### The links between the steps are queued jobs

Storing a generated result queues validation, and validation with no blocking
findings queues rendering. Nothing joins the steps by hand, and neither link is
inline work: a revision arriving from a collected batch is already being handled
in the worker, and an LLM call or a PDF conversion inside that job would make
one slow revision hold up every other result the batch carried.

Validation is queued where the result is stored rather than by the caller that
stored it, so every path that produces output — a collected batch, a live call
for a single revision — continues the chain without having to remember to.

Whether to render is the render guard's answer and not the link's, since the
guard is the one place that decides whether a revision may become a PDF. A
revision the guard refuses stays `validated` and waits for the owner: nothing
is dispatched, and `revision.validation_blocked` already says why.

### Each link is idempotent

**A duplicate dispatch is the ordinary consequence of a retried job**, so
neither link may write twice. The checks are deterministic over a revision's
output and the master CV version it names, so running them again writes the same
findings over themselves rather than a second set of them, and a revision has
one PDF at a path derived from its ID.

A link that finds its revision complete does nothing at all. That is what keeps
a retry off a revision which has since been rendered — a write there would be
refused as a mutation of a complete revision — and it is why the guard reads the
stored status rather than the one the job was dispatched with.

Nothing in the chain reads where the content came from, so a manual edit takes
the same path as a generated revision, for the same reason every check runs on
every revision.

### A finding

A finding records **the check that raised it, its severity, the master CV IDs
and the output field involved, and a message written for the owner**. The field
is a path into the output (`roles[0].items[1].text`), because the owner has to
be told where to look and the same ID may be used correctly in one place and
wrongly in another.

The message is a **language catalogue entry like any other word on a screen**,
and the rendered sentence is what is stored: one locale, one owner, and a
message stored as written still reads the same after the check that raised it
has been rewritten. A finding quotes CV and output content, so it belongs on the
revision and never in a log line.

### Severity, and what it costs

- **Blocking** — the revision claims something the master CV does not support.
  It is never rendered.
- **Advisory** — the revision is untidy. Recorded, shown, and never a refusal.

The two role-mode checks are deliberately on opposite sides. **A demotion
blocks**: code owns each role's default mode, so showing less of a role is a
decision taken out of the owner's hands, and a CV that quietly drops a role the
owner wanted shown is wrong in a way only the owner would notice. **A promotion
with no reason given is advisory**: the promotion itself is the judgement the
model was asked to make, and nothing in the CV is a false claim because the
explanation is missing.

### `validated` means validation has run

Generation leaves a revision `generated`; validation always leaves it
`validated`. **`validated` does not mean it passed** — the findings say that. A
revision with a blocking finding stays `validated` and is never rendered. It is
not `failed`: `failed` is for a revision that could not be produced, not one
produced wrongly, and the owner regenerates from this one with instructions,
which needs the revision and its findings intact. Rendering takes `validated`
with no blocking findings to `ready`.

**An empty findings list is not a null one.** A revision that passed cleanly
stores an empty list; one that has never been validated stores null. Nothing may
treat the two as the same thing, or a revision nobody checked would render like
one that passed. The render gate reads both, and refuses a revision that has
never been validated as firmly as one that failed — and a finding whose severity
nothing can read is treated as blocking, because a finding nobody can classify
must not be the reason a CV is let through.

### The deterministic checks

All seven block.

- **IDs resolve** against the referenced master CV version, and nothing marked
  `TODO` was selected. An ID is in the CV and unavailable, or not in the CV at
  all, and the owner is told which: a `TODO` is a fact the author declined to
  state, so an output naming it has filled in a blank.
- **Rewordings carry `supports`**, and every supporting ID resolves. Without a
  citation an uncited rewording is not a weak claim but an unfalsifiable one,
  with nothing for this check or the faithfulness check to read it against. The
  summary always needs support: the master CV has no summary for it to repeat.
  **This check owns the IDs inside a `supports` list and the ID check owns the
  rest**, so between them every ID is checked exactly once — a finding repeated
  in two vocabularies tells the owner nothing twice.
- **Technology-to-role**: a technology named in a reworded item was used in
  that role. **The skills index decides what counts as a technology; the role's
  own tech stack decides whether that role may claim it.** The two sources are
  different on purpose — the index is a property of the person and the stack is
  a property of the role, and the claim is about the role. The index decides for
  everything in it, practices included: it is the author's own statement of what
  they have worked with, and second-guessing which rows count would invent a
  taxonomy the author never wrote.
- **Versions**: a version in a reworded item is one the role's own material
  records — its stack, its context, its own bullets, and nothing from another
  role. **A technology the role pins no version to refuses every version**,
  because the CV's rule is that a version it does not give is not to be added.
  A version in the summary belongs to no role and is checked against the whole
  document instead: a version the CV records nowhere is wrong wherever it
  appears, and the summary is the field most worth checking.
- **Do not claim**: no forbidden term appears in **the fields that can reach
  the page** — the headline, the summary, the skills and every selected item.
  `jd_analysis` and `review_notes` are not searched: they are review surfaces,
  read in the UI and never rendered, and they are where the output names the
  requirements the master CV does not support, which is exactly what the CV's
  rules instruct it to do. Blocking a revision for honestly reporting
  "Kubernetes — gap" would fire on nearly every job, and a guardrail that always
  fires is one that gets ignored.

  **The terms are each item's bold lead-in**, which is the section's convention,
  plus a parenthetical sitting directly after that lead-in, where the brackets
  carry the list. Reading the rest of the line would take the explanation's
  "RabbitMQ and AWS SQS are" for forbidden terms and block a CV for claiming
  something the CV supports.
- **Items belong to their role**: an item shown under a role is that role's
  own. This is the quietest fabrication there is — a *verbatim* bullet from one
  employer, selected under another, passes every other check, and the CV then
  says the work happened somewhere it did not with every word still the
  author's. It is checked here because the schema cannot reach it: scoping the
  item enum to one role would need a separate object shape and a separate enum
  per role. **Ownership is read from the parse, not from the ID's prefix** —
  they agree on every ID `cv:id` writes, and the parse is the fact, since IDs
  are written by hand and an author may give a bullet a name of its own.
- **Independent projects stay independent**, in both directions, for an item's
  own ID and for the IDs a rewording cites. The output schema's two container
  shapes already make the wrong kind unreturnable for a generated revision; this
  covers the items inside a container, and a manual edit. **It overlaps the
  containment check and is kept** — containment does not read the IDs a
  rewording cites, because evidence legitimately spans the whole document and
  the one crossing that is never legitimate is this one. Two overlapping checks
  are cheaper than one with a gap.

### The lint's skill vocabulary is not reused

Its matching is generous by design, and across a few hundred rows the union of
words is large enough that almost any single word is found somewhere. The
validator matches instead against *one role's* handful of stack entries, each
read as a set of words, so the generosity that lets "Slim" stand for "Slim
Framework" cannot leak between roles. A parenthetical in the index becomes
another name for a technology only when it pins a version — "Slim Framework
(Slim 4)" does and "AWS CloudWatch (logs, metrics, alarms)" does not, and
taking "logs" for a technology would make the word a claim.

**A validator that blocks a correct CV is a validator that gets switched off**,
which costs the guarantee far more than the case it was reaching for. That is
the reasoning behind this and behind the fields "Do not claim" leaves alone, and
it is the only thing a scope decision here is ever allowed to buy.

### The word budget

Advisory, and advisory is the point: a length is a target stated in the prompt
and never a limit, and a long CV that is true beats a short one that is not. It
counts **the words the selection chose** rather than the words on the page —
headings, dates and contact details are the same on every CV this instance
produces, so they are not what a budget is spent on, and the selection is the
only thing a regeneration can trade against it. Items under an omitted role cost
nothing, and a verbatim item costs the master CV's own sentence.

### The faithfulness check

For rewordings and the summary only: a live call to the small validation model
asking whether the text asserts anything its cited sources do not. Live rather
than batched — it is cheap, and batching it would double the time to a ready CV.

## Rendering

### `resolved_json` is the document, and the only input to rendering

The validated selection is resolved against the master CV version it names into
a complete document model: no IDs left to look up and nothing further to fetch.
**It is format-agnostic.** Nothing in it knows about HTML, pages or paper, so a
second output format — `.docx`, plain text — is a second template rather than a
second pipeline, and two formats say the same thing because both read the same
model.

That is why anything a renderer would otherwise decide for itself is decided at
resolve time: which wording a rewording put on the page, what order the roles go
in, whether a condensed role keeps its prose, which bullet sits under which.

### What the model decides, and what the code decides

The model chooses which roles appear, which of their bullets appear and in what
order, which skills to lead with, and the words of a rewording. Everything else
is read from the parse.

- **Role order and dates come from the parsed CV** — reverse chronological,
  whatever order the output returned — and so do the employer and the title.
- **The header comes from the parsed contact section**, which never reaches the
  model. That is what makes a mistyped phone number impossible rather than
  unlikely: nothing can alter a number it has never seen. The headline is the
  one header field the model chooses, and it chooses among the CV's own headline
  titles; one the CV does not offer is dropped.
- **Which contact fields the header carries, and whether awards and interests
  appear, are presentation policy** — `contact_fields`, `include_awards`,
  `include_interests` — and so are the author's to set rather than anyone's to
  infer. The reasoning is with the rest of the policy, above.
- **An item the role does not own, or one the author marked `TODO`, is dropped
  rather than printed.** Validation blocks a revision that asks for either; a
  renderer that printed it anyway would be a second route onto the page.

### "Do not elaborate" is honoured at resolve time

Content the author marked "do not elaborate" resolves to their own sentence even
when the output supplied a rewording. The parser sets the flag and the resolver
reads it, because resolving is the last point before the words reach a page and
a check that merely reported it would leave them there.

### A revision has exactly one PDF

The path is derived from the revision's ID, so rendering the same revision again
replaces the file rather than leaving a second copy nobody can tell from the
first. Generated PDFs live on the `pdfs` disk, whose root is `CV_PDF_ROOT` and a
named volume in the deployment; `pdf_path` holds the path relative to that root.
An absolute path would be wrong the first time the volume moved.

### A converter failure leaves the revision `validated`

Resolving is recorded before the conversion is attempted, so a converter that is
down leaves behind the document it would have rendered. The revision stays
`validated` and never becomes `failed`: it was produced correctly and only the
conversion did not happen, so the next attempt has everything it needs.
`pdf_path` is left alone, because a path with no file behind it is worse than no
path at all.

A refused render — a blocking finding, or no validation at all — records no
error on the revision. The verdict is already there in `validation_json`, and
restating it would be the same fact in two places.

**The queue does not try again.** The render job gets one attempt: a converter
that is down is not up again seconds later, and three goes at it would bury the
first failure under two more. The revision is left `validated` with its error
recorded, which is where the owner sees it and acts on it.

### The template

`resolved_json` → Blade → HTML → Gotenberg → PDF. **Section headings and every
other word the template supplies come from `lang/en_GB/cv.php`**; the CV's
content never does, because it arrives from the master CV through the pipeline
and a catalogue is for words the owner reads on a screen.

A master CV's bullets carry inline Markdown — a bold lead-in is the format's own
convention — so the template converts it. The text is escaped first and every
tag is the converter's own, which is what stops a master CV or a model's output
contributing one.

**No test starts Gotenberg.** The converter sits behind an interface and the
test environment binds a fake, for the same reason the LLM client does.

### Seeing what the template does

`php artisan cv:preview` renders the whole master CV through the CV template —
every role at its policy default, every selectable bullet, every skill, verbatim
— with no model involved and nothing written to the database. A diff cannot show
a CV that clips a column or breaks a page in the wrong place, and neither can a
test.

**It is not a way round the render guard.** The guard stands between a
*revision* and a PDF because a revision carries model output. A preview carries
none: every word of it is the author's own, taken from their own file. It is
linted and run through the same validators all the same, and refuses on a
blocking finding exactly as the guard would.

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

### A page's content is schema components, not a template

Filament ships its panel CSS prebuilt from its own class names, and this
application has no front-end build to add to it. A hand-written Blade template
therefore has no utility classes to reach for: everything a page renders is
assembled from schema components, which carry the styles that exist.

### The master CV page

The master CV is the part of the system its owner touches most, so it has a
page: what the application makes of the file, every lint finding against it,
and the two actions worth having.

- **It reports and offers; it does not author.** The CV is a Markdown file its
  owner edits for years in an editor, and the mount is read-only by decision —
  so "add missing IDs" hands back a corrected copy to **download**, exactly as
  `cv:id` does, and the author reads a diff and adopts it themselves. A page
  that wrote to the CV would be a second editing surface for a document that
  already has one, and would cost the read-only mount.
- **It re-reads on demand**, because the file changes outside the application:
  the parse is made afresh on every request and never remembered, so a CV saved
  in an editor is seen without a restart, and "re-check" is a button whose job
  is to cause that read.
- **Reporting on a CV is not generating from one.** The page's read applies the
  same gate as generation — a CV that fails lint never becomes a
  `master_cv_versions` row — so looking at a broken CV cannot leave behind
  something a revision could claim it was built from.
- **A file the application cannot read is an outcome, not an exception.** The
  page names what is at the path, because a directory and a permissions problem
  send its reader somewhere different, and `cv:status` answers that question
  from the same place so the two surfaces cannot disagree.
- **Lint's own findings are the exception to the catalogue rule.** They are
  written for the CV's author and come from the linter; the page prints them as
  it is given them, and supplies only the heading of the check that raised each
  one.

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
- **A scheduled task runs in the scheduler's own process.**
  `Schedule::command()` is the obvious way to schedule a command and discards
  exactly what matters here: it runs the command in a subprocess whose output
  the scheduler sends to `/dev/null`, and this application's log is its
  processes' standard error, so every event the command emitted would be
  thrown away before anything collected it. Scheduled work therefore runs
  in-process — `Schedule::call()` — and `docker compose logs scheduler` carries
  its events.
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
- **`LlmClient` is bound to the fake in the test environment**, so reaching a
  live model from a test takes deliberately undoing that rather than
  forgetting to fake. The provider's own SDK client refuses to be constructed
  there at all, which catches a test that resolves the real implementation
  instead of the interface.
- **The fake emits the same log events as the real client**, from the same
  class, so the event names cannot drift apart and observability is testable
  without a provider.
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
| Regenerate the OpenAPI document | `docker compose run --rm toolchain php artisan cv:openapi` |

`cv:openapi` writes `backend/openapi.json`, or somewhere else with `--path`. It
needs nothing running and no database of its own: see The OpenAPI document,
above. Regenerating the extension's client from the result is part of the same
change — the command for that is in the extension briefing.

These run against the **running** stack and so use `exec app`, not the
toolchain:

| Task | Command |
| --- | --- |
| What the stack is doing | `docker compose exec app php artisan cv:status` |
| Create or update the owner | `docker compose exec app php artisan cv:owner` |
| Issue an extension token | `docker compose exec app php artisan cv:token` |
| Check the master CV | `docker compose exec app php artisan cv:lint` |
| Queue captured postings for generation | `docker compose exec app php artisan cv:queue` |
| Submit every pending revision as one batch | `docker compose exec app php artisan cv:batch-submit` |
| Collect the results of every finished batch | `docker compose exec app php artisan cv:batch-poll` |
| Arm the submitter again after it halted itself | `docker compose exec app php artisan cv:batch-resume` |
| Add the IDs a master CV is missing | `docker compose exec app php artisan cv:id /var/lib/cv-tailor/master-cv.md > master-cv-with-ids.md` |
| See the master CV through the CV template | `docker compose exec app php artisan cv:preview /tmp/cv-preview.pdf` |

`cv:owner` prompts, and takes `--name`, `--email` and `--password` when a
script needs it. `cv:token` prints the token once; only its hash is stored.

`cv:batch-submit` and `cv:batch-poll` are scheduled as well, and run by hand
when somebody wants the answer now. `cv:batch-resume` is the only way to clear
a halted submitter, and `cv:status` is where the halt is visible.

`cv:lint` checks the mounted CV, and takes a path when another file is wanted.
`cv:id` reads the mounted CV and writes elsewhere — standard output above, or a
path given as a second argument; the mount is read-only, so there is nowhere
inside the container to write it but `/tmp`. `cv:preview` is the same: it reads
the mounted CV, takes `--cv` for another file, and writes the PDF where it is
told, which has to be somewhere writable. `docker compose cp` brings it out to
be read.

### Things that are easy to get wrong

- **The test suite supplies its own environment** and is handed no `.env`, so a
  result never depends on how a particular machine is configured. Test values
  belong in `phpunit.xml`.
- **Configuration is never cached.** It arrives as container environment
  variables, and a cached config file would freeze whatever happened to be set
  when the image was built.
- **A route memoises the controller it resolved**, and a route lives as long as
  the application does. So a test that swaps a binding *between* two requests
  is served the second time by the instance the first request built, and the
  swap appears to have been ignored. Install the double before the first
  request of the test.
- **The queue's retry window and the worker's timeout are one decision in two
  files.** `DB_QUEUE_RETRY_AFTER` is when the queue gives up on a reserved job
  and offers it to another worker; `--timeout` on the worker's `queue:work` is
  when a worker kills a job that is still running. The window has to be the
  longer of the two, or a job that is merely slow is handed to a second worker
  while the first is still working on it, and the pipeline's jobs are the slow
  ones. Change either number and change both.
