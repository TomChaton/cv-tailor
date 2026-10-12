---
document: master-cv
subject: Jordan Avery
purpose: Source of truth for tailoring CVs and application answers
presentation:
  target_pages: 2
  condense_roles_ending_before: 2018
  omit_roles_ending_before: 2012
  headline_titles:
    - Senior Backend Engineer
    - Platform Engineer
---

# Jordan Avery — Master CV (source of truth)

## Rules for any agent using this file

1. **This file is the only source of facts.** Every skill, technology, metric, employer, date and achievement used in a tailored CV must appear here. Do not infer, generalise or round up.
2. **No transferring between roles.** A technology listed under one role must not be attributed to a different role.
3. **No version upgrades.** If a role lists PHP 7.4, do not write PHP 8 for that role. If no version is given, do not add one.
4. **No proficiency upgrades.** "Evaluated", "integrated" and "designed" mean different things; keep the verb used here.
5. **Independent projects are not employment.** Technologies used only under *Independent projects* must be presented as personal work, never as commercial experience at an employer.
6. **`TODO` means unknown.** Never fill a `TODO` with a guess. Omit the point, or ask.
7. **Respect the "Do not claim" section** at the end of this file.
8. Tailoring may **select, reorder, condense and reword** — it may not **add**.

---

## Contact

- **Name:** Jordan Avery
- **Location:** Didsbury, Manchester, UK
- **Phone:** 07700 900123
- **Email:** jordan.avery@example.com
- **Full postal address (use only if a form requires it):** 14 Beech Grove, Didsbury, Manchester, M20 0AA
- **LinkedIn:** https://www.linkedin.com/in/example-jordan-avery/
- **Right to work:** British citizen
- **Notice period / availability:** One month

## Headline titles (any may be used)

- Senior Backend Engineer
- Platform Engineer
- Search Specialist

## Profile facts

- 16 years' commercial experience in PHP and relational database systems (since 2008).
- Domains: passenger transport and dispatch, event ticketing, public-sector open data, independent publishing.
- Hands-on engineer who leads by writing code and reviewing it.
- Comfortable working remotely and asynchronously, and in a room with the business.

---

## Skills index

Each entry lists where the skill was used. Years are role dates, not claimed proficiency.

### Languages

| Skill | Where used |
|---|---|
| PHP 8.x (8.3) | Harlequin Transit (2021–present) |
| PHP 7.x (7.0, 7.4) | Harlequin Transit (legacy, upgraded), Northgate Tickets, Pelham Civic Data |
| PHP 5 | Larkspur Media |
| JavaScript | Northgate Tickets, Pelham Civic Data |
| TypeScript | Harlequin Transit (generated client library) |
| Rust | Independent: Fathom Index (indexer) |
| SQL | Harlequin Transit, Northgate Tickets, Pelham Civic Data |

### Frameworks & libraries

| Skill | Where used |
|---|---|
| Laminas | Harlequin Transit (legacy platform) |
| Slim Framework (Slim 4) | Harlequin Transit (dispatch API) |
| Symfony (4.4) | Northgate Tickets |
| Zend Framework 1 | Pelham Civic Data |
| Vue 3 | Harlequin Transit (operations console), Quayside Charts |
| D3 | Quayside Charts |

### Data stores & search

| Skill | Where used |
|---|---|
| PostgreSQL (13, 15) | Harlequin Transit |
| MySQL (5.0, 5.6, 5.7) | Northgate Tickets, Pelham Civic Data, Larkspur Media |
| SQLite | Independent: Fathom Index, Quayside Charts |
| Redis | Harlequin Transit |
| Elasticsearch (7, 8) | Harlequin Transit, Northgate Tickets |
| Apache Solr | Pelham Civic Data (migrated away from) |

### Infrastructure, messaging & tooling

| Skill | Where used |
|---|---|
| Docker | Harlequin Transit, Northgate Tickets |
| Terraform | Harlequin Transit |
| AWS ECS | Harlequin Transit |
| AWS SQS (incl. dead-letter replay) | Harlequin Transit |
| AWS S3 | Harlequin Transit |
| AWS CloudWatch (logs, metrics, alarms) | Harlequin Transit |
| RabbitMQ | Northgate Tickets |
| nginx | Northgate Tickets |
| Apache | Pelham Civic Data |
| GitHub | Harlequin Transit |
| GitHub Actions | Harlequin Transit |
| GitLab | Northgate Tickets |
| Subversion | Pelham Civic Data, Larkspur Media |
| Jira | Harlequin Transit, Northgate Tickets |
| Trac | Pelham Civic Data |

### Testing & quality

| Skill | Where used |
|---|---|
| PHPUnit | Harlequin Transit, Northgate Tickets |
| Behat | Northgate Tickets |
| Cypress | Harlequin Transit |

### Ways of working & leadership

- Code review as a team practice — Harlequin Transit
- Trunk-based development — Harlequin Transit
- Incident review — Harlequin Transit
- Mentoring two junior engineers — Northgate Tickets
- Agile, Scrum and Kanban

### Operating systems

- Linux: Debian 12, Ubuntu 22.04, CentOS 7
- macOS

---

## Employment history

### Harlequin Transit — Senior Backend Engineer {#harlequin-transit}

- **Dates:** September 2021 – present
- **Engagement:** Permanent
- **Work mode:** Remote, with one office week a quarter
- **Sector:** Passenger transport and vehicle dispatch
- **URL:** https://example.com/harlequin-transit
- **Team:** Platform team of six; leads the dispatch subsystem
- **Headcount:** 180 across the business

**Context**
Joined to modernise a dispatch platform that had grown unattended for a decade. Now the senior technical voice on the platform team, hands-on in code daily, and the engineer the business asks first about anything the dispatch system does.

**Responsibilities**
- Architecture and technical direction for the dispatch subsystem {#harlequin-transit.1}
- Code review and the engineering standards the team reviews against {#harlequin-transit.2}
- Production support: first escalation point for dispatch incidents {#harlequin-transit.3}

**Achievements & projects**

- **Dispatch API (designed and built).** {#harlequin-transit.4}
  - Replaced a nightly batch hand-off with a synchronous REST API on Slim 4, because a driver is waiting on the result. {#harlequin-transit.4.1}
  - PHP attributes generate the OpenAPI document, which generates a typed TypeScript client published as a package. {#harlequin-transit.4.2}
  - Dead-letter replay on the AWS SQS queues behind it, so a failed dispatch can be re-driven rather than reconstructed by hand. {#harlequin-transit.4.3}
- **Elasticsearch 8 upgrade and relevance work.** {#harlequin-transit.5}
  - Moved stop and route search from Elasticsearch 7 to 8 with no search downtime, using index aliases. {#harlequin-transit.5.1}
  - Retuned relevance for abbreviated stop names, which had been the largest source of driver complaints. {#harlequin-transit.5.2}
- **PHP 7.4 to 8.3 modernisation.** Upgraded the business-critical Laminas platform through its deprecations and dependency changes without a maintenance window. {#harlequin-transit.6}
- **Infrastructure in Terraform.** Took a hand-built AWS estate into Terraform: AWS ECS services, AWS SQS queues, AWS S3 buckets, and AWS CloudWatch logs, metrics and alarms. {#harlequin-transit.7}
- **Engineering discipline.** Introduced code review, trunk-based development and blameless incident review to a team that had none of them. {#harlequin-transit.8}
- **Vendor selection.** Took part in selecting a telematics vendor; do not elaborate, as the vendors were ultimately rejected. {#harlequin-transit.9}

**Tech stack**
- Languages: PHP 8.x (8.3), PHP 7.4 (legacy), TypeScript, SQL
- Frameworks: Laminas (legacy platform), Slim 4 (dispatch API), Vue 3 (operations console)
- Data: PostgreSQL 15, Redis, Elasticsearch 8
- AWS: ECS, SQS (incl. dead-letter replay), S3, CloudWatch
- Infra: Docker, Terraform
- Testing: PHPUnit, Cypress
- Tools: GitHub, GitHub Actions, Jira
- OS: Debian, macOS
- Practices: code review, trunk-based development, incident review

---

### Northgate Tickets — Backend Developer {#northgate-tickets}

- **Dates:** February 2018 – August 2021
- **Engagement:** Permanent
- **Work mode:** Manchester
- **Sector:** Event ticketing
- **URL:** https://example.com/northgate-tickets

**Achievements & projects**
- Built the seat-reservation service that holds a seat while a customer pays, on Symfony 4.4 and RabbitMQ. {#northgate-tickets.1}
- Introduced Behat tests for the reservation flow, which doubled as the handover documentation for the support team. {#northgate-tickets.2}
- Moved venue search onto Elasticsearch 7, replacing a LIKE query that scanned the whole venue table. {#northgate-tickets.3}
- Mentored two junior engineers through their first year, including their code review habits. {#northgate-tickets.4}
- Took part in a GDPR data-retention project; the detail is not recorded — do not elaborate beyond this line. {#northgate-tickets.5}

**Platform work**
- Containerised the application for local development and for its nginx deployment. {#northgate-tickets.6}
- Moved the build from a hand-run script to GitLab pipelines. {#northgate-tickets.7}

**Tech stack**
- Languages: PHP 7.4, JavaScript
- Frameworks: Symfony 4.4
- Data: MySQL 5.7, Elasticsearch 7
- Queues: RabbitMQ
- Infra: Docker, nginx
- Testing: PHPUnit, Behat
- Tools: GitLab, Jira
- OS: Ubuntu

---

### Pelham Civic Data — Developer {#pelham-civic-data}

- **Dates:** June 2014 – January 2017
- **Engagement:** Permanent
- **Work mode:** Leeds
- **Sector:** Public-sector open data

**Context**
A small team publishing council datasets as both a website and an API, on a platform inherited from a cancelled outsourcing contract.

**Achievements & projects**
- Built the dataset import pipeline that normalised spreadsheets from twelve councils into one schema. {#pelham-civic-data.1}
- Replaced Apache Solr with a simpler MySQL full-text search once the dataset count made Solr's upkeep the larger cost. {#pelham-civic-data.2}
- Maintained the Zend Framework 1 application the platform was built on. {#pelham-civic-data.3}

**Tech stack**
- Languages: PHP 7.0, JavaScript
- Frameworks: Zend Framework 1
- Data: MySQL 5.6, Apache Solr
- Infra: Apache
- Tools: Subversion, Trac
- OS: CentOS 7

---

### Larkspur Media — Junior Developer {#larkspur-media}

- **Dates:** July 2008 – November 2010
- **Engagement:** Permanent
- **Work mode:** Sheffield

**Achievements & projects**
- Built and maintained brochure sites and a classified-advertising section for a regional publisher. {#larkspur-media.1}
- TODO: the traffic the advertising section carried. {#larkspur-media.2}

**Tech stack**
- Languages: PHP 5
- Data: MySQL 5.0
- Tools: Subversion

---

## Independent projects (not employment)

### Fathom Index — Founder; product owner and architect (code written by AI agents) {#fathom-index}

- **Dates:** 2024 – present
- **Status:** In active development. Do not claim customers, revenue or a launch.
- **What it is:** A full-text index for personal document archives that runs entirely on the owner's own machine.

**Architecture and components**
- Rust indexer walking an archive and writing an inverted index to SQLite. {#fathom-index.1}
  - Incremental indexing keyed on file modification time and size. {#fathom-index.1.1}
  - A single-file index, so a backup of the archive is a backup of the index. {#fathom-index.1.2}
- Command-line query interface over the same index. {#fathom-index.2}

**Jordan's role:** product owner and architect; designed the agent delivery workflow and reviewed all of its output. All application code was written by AI agents.

**Tech stack**
- Languages (agent-written): Rust
- Data: SQLite
- OS: Linux, macOS

### Quayside Charts — Product owner and architect (code written by AI agents) {#quayside-charts}

- **Dates:** 2023
- **What it is:** A small chart library for tidal data, built for a sailing club's noticeboard.
- **Tech stack:** Vue 3, D3, SQLite

**Notable work**
- Tide-curve interpolation between published high and low water times. {#quayside-charts.1}
- Print stylesheet, because the noticeboard is a sheet of paper. {#quayside-charts.2}

---

## Awards

- 2019 — Ticketing Innovation Award, Northern Events Association (Northgate Tickets team)

## Personal interests

- Sailing: dinghy racing at a club on the Mersey, and working towards a Day Skipper qualification
- Running and hill walking
- Reader: history, biography and technical books
- Restores and repairs mechanical typewriters

---

## Do not claim

These are common job-description asks that are **not** supported by this file. Do not add them; flag them as gaps instead.

- **Kubernetes, GCP, Azure** — not recorded.
- **Kafka** — not recorded (RabbitMQ and AWS SQS are).
- **Rust in an employed setting** — Rust appears only in an agent-written independent project that Jordan designed, directed and reviewed.
- **Payments engineering** beyond holding a seat while a third-party checkout completes. No card processing, ledgers or reconciliation systems are recorded.
- **Formal line management** beyond mentoring two junior engineers at Northgate Tickets.
- **Formal on-call rota, SRE or SLO ownership** — not recorded. Jordan is the first escalation point for dispatch incidents at Harlequin Transit; describe it that way.
- **Observability tooling beyond AWS CloudWatch** (Datadog, Grafana, Prometheus, Sentry) — none recorded.
- **Education and qualifications** — do not include an education or qualifications section.
