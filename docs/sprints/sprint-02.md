# Sprint 2 — Programmes and workplace outcomes

Status: **approved** (Srini, 27 Nov 2026) · Date: 27 Nov 2026

## Why this sprint
Every simulator, course, logbook entry, assessment and report hangs off one thing: **what a learner must be
able to do in the workplace for their programme.** This sprint turns the Services SETA *Workplace Practical
Expectations* guide (13 programmes) into structured, versioned data that HQ manages and every other module
uses.

## What we start from
The guide describes each of the 13 programmes the same way:

| Part of each profile | Example (Technical Support) | Becomes |
|---|---|---|
| Reference points | SAQA 78964 (legacy, NQF 4, 163 credits); related QCTO route: Computer Technician, SAQA 101408, NQF 5, 282 credits | Qualification references (legacy vs current QCTO) |
| To confirm before use | Which qualification learners are registered on; logbook format and hours required by the SDP | Provider checklist |
| Typical occupations | IT support technician, help desk analyst … | Occupations (careers on learner screens) |
| What the workplace must provide | Live users, ticketing system, a mentor who can do the work … | Workplace requirements (readiness checklist) |
| Purpose of the workplace component | "Receive a problem from a real user, diagnose it, fix or escalate it, document it …" | Programme purpose |
| Duty areas (6 for Technical Support) | 1. Service desk and ticket management | Duty areas |
| What the learner should be doing | "Log each request as a ticket with accurate details" | **Workplace outcomes** (the unit everything links to) |
| Evidence the mentor should see | "Ticket extract showing a spread of ticket types" | Evidence requirements (feed the logbook and portfolio) |
| Tools, systems and equipment | Ticketing system, remote support software, antistatic kit … | Tools list (and simulator props) |

The guide also has general chapters (how occupational qualifications are structured, principles of
competence assessment, what a mentor is expected to do, workplace readiness checklist). These become
platform reference pages that mentors and providers can read in the app.

**Timing matters:** from 1 July 2026 the move from legacy SETA-registered qualifications to QCTO occupational
qualifications is fully in effect. Several programmes in the guide still cite legacy SAQA IDs next to the
current QCTO route, so the data must hold both and say clearly which is current.

## What market leaders do, and where they fall short

| Platform type | What they do well | Weakness for our learners and providers | Our answer |
|---|---|---|---|
| Moodle / Moodle Workplace (common with SA providers) | Competency frameworks, learning plans, evidence review by a teacher | Generic frameworks with no idea of QCTO modules, duty areas or SAQA IDs; set up by hand per provider; dense admin screens; no link from a competency to practice | Structure that mirrors the guide and QCTO; imported once by HQ, shared by every provider |
| Corporate skills platforms (Docebo, Cornerstone and similar) | Skills taxonomies, AI tagging, dashboards | Built for corporate HR, not occupational qualifications; expensive; English only | Plain-language outcomes, four languages on screens, priced per programme and seat |
| SA learner-management / SDP admin systems | Learner records and SETA/QCTO compliance reports | Compliance-first: outcomes are a list to tick, with no practice, no simulator and no learner-facing view | Each outcome links to the simulator scenarios and course lessons that practise it, and to the evidence the mentor must see |
| Paper / PDF mentor guides (today) | Clear and complete | Not trackable; changes reach providers late; nobody can tell which version a cohort used | Versioned data; each cohort stays on the version it started with; changes are visible and audited |

## Our additions (the "why SimuWork" for this module)
1. **One-click import of all 13 programmes** from the guide, checked by tests (programmes, duty areas,
   outcomes, evidence, tools all counted).
2. **Versioning that protects cohorts:** HQ edits a draft; publishing creates a new version. Providers and
   learners stay on the version they enrolled under until the provider chooses to move them. Every change is
   audited and shown as a readable "what changed" list.
3. **Review before publish:** Scenario Designer / Content Manager drafts → SME Reviewer approves → Content
   Manager publishes (the roles from Sprint 1.4). Nothing reaches providers unreviewed.
4. **Coverage map:** for every outcome, HQ sees whether it is practised by a simulator, taught by a course,
   both, or neither, with a readiness percentage per programme. This drives what the content team builds
   next and what we can honestly sell.
5. **Legacy ↔ QCTO clarity:** each programme shows its current QCTO occupational certificate and any legacy
   SAQA qualification, with registration status, so providers enrol learners on the right one.
6. **Provider "to confirm" checklist:** the guide's "to confirm before use" items become a checklist each
   provider completes per programme (qualification registered, logbook format, hours), recorded for QCTO.
7. **Learner-friendly wording:** each outcome has the formal statement plus a short "in plain words" line
   for learner and mentor screens (written by HQ, translated later with the course pipeline).
8. **Evidence types** (document, photo, observation, sign-off, log/extract, feedback) so the logbook
   (later sprint) can ask for the right thing and check that photos show no personal data.

## Scope

### In
- Platform-owned programme catalogue (not tenant data): programmes, versions, duty areas, outcomes, evidence
  requirements, tools, workplace requirements, occupations, qualification references, guide reference pages.
- Import of the 13 programmes from the guide (structured JSON in the repo, produced from the guide and
  checked against it).
- HQ **Programme Studio** (web): list, programme page, inline editing, drafts, review and publish, version
  history with "what changed", coverage map.
- Provider **Programme catalogue** (web, read-only): published programmes, outcomes, evidence, workplace
  requirements, and the provider's "to confirm" checklist.
- API under `/api/v1/platform/programmes…` (HQ) and `/api/v1/programmes…` (providers, published only).
- **C4 (real-device budget):** CI builds a release Android APK without EAS, reports the download size
  (budget < 25 MB) and measures cold start on an emulator. A real budget phone test follows when a device is
  available.

### Out (later sprints)
- Selling programmes and seats (packages, licences) — Sprint 3. Until then the provider catalogue shows all
  published programmes, marked "not licensed yet".
- Enrolments, cohorts and moving a cohort to a new version — Sprint 3–4.
- Linking real simulator scenarios and course lessons — the coverage map stores planned/linked status now;
  real links arrive with Scenario Studio and Course Studio.
- Translating programme content into isiZulu, Afrikaans and isiXhosa — with the course pipeline.
- Staff mobile tools (C1) — not moved by this sprint; they ship with attendance, logbook and assessment.

## Data model (summary)
- `programmes` — code (e.g. `technical-support`), title, family (Services SETA ICT), status, current
  published version.
- `programme_versions` — programme, number, status (draft · in review · published · retired), purpose,
  occupations, workplace requirements, tools, to-confirm items, change notes, reviewed/published by and when.
  Published versions are read-only (database trigger, like the audit log).
- `qualification_refs` — SAQA ID, title, type (QCTO occupational certificate · legacy · skills programme),
  NQF level, credits, status, notes; linked to programmes.
- `duty_areas` → `outcomes` (code like `TS-1.2`, statement, plain-words line) and `evidence_requirements`
  (description, type, minimum count) — all belong to a version; a new draft copies the published version.
- `outcome_coverage` — outcome × (simulator | course) with status planned · in production · linked.
- `provider_programme_checks` (tenant-owned, RLS) — the provider's answers to the "to confirm" checklist.
- `provider_outcomes` / `provider_evidence_requirements` (tenant-owned, RLS) — provider additions, allowed only
  when HQ has switched them on for that provider (`tenants.settings.programme_additions`, default off).
- `reference_pages` — the guide's general chapters (markdown), versioned with the guide edition.

Platform tables have no `tenant_id`: providers read **published** versions through the API only; the
checklist table is tenant-owned with row-level security like everything else.

## Sub-sprints

| # | Deliverable | Done when |
|---|---|---|
| 2.1 | Data model, read-only rules, guide import (13 programmes + reference pages), HQ and provider read APIs | Import is repeatable; tests count every programme, duty area, outcome, evidence item and tool against the guide; providers only ever see published versions |
| 2.2 | Programme Studio: list, programme page, inline editing of drafts, review → publish, version history with "what changed", audit | Every role in the workflow tested (author, reviewer, publisher; others refused); published versions cannot change |
| 2.3 | Coverage map and readiness %, provider catalogue and "to confirm" checklist, reference pages, provider additions (HQ switch, off by default) | Provider sees only published content; checklist answers and additions are tenant-isolated and audited; additions refused while the switch is off |
| 2.4 | Android release APK built in CI; download size budget; emulator cold-start measurement (C4) | APK < 25 MB enforced; cold-start number reported on every mobile PR |

Each sub-sprint is its own PR, merged only when CI is fully green.

## Risks and how we handle them
- **Guide wording vs official curricula:** the guide is a mentor guide, not the QCTO curriculum document.
  We store its source and edition on every programme and keep "to confirm before use" visible; HQ can add
  the official curriculum reference per programme.
- **Changing a published programme mid-cohort:** solved by versioning; nothing in use is edited in place.
- **Import accuracy:** structured JSON reviewed in the PR, plus count tests; a mismatch fails CI.

## Decisions (Srini, 27 Nov 2026)
1. **Provider additions:** HQ has a switch per provider, **off by default**. When on, that provider can add
   its own outcomes and evidence requirements on top of HQ's, shown as "provider addition", visible only to
   that provider (row-level security), audited, and never changing HQ's version. Delivered in 2.3.
2. **Official QCTO curricula:** only the guide is available now; Muzi is collecting the curriculum documents.
   The model already allows several qualification references per programme; knowledge and practical
   modules from the curricula will be added when the documents arrive.

## Sub-sprint 2.1 notes (catalogue and import)
- `tools/guide/` turns the guide PDF into `api/database/data/guide/workplace-practical-expectations.json`
  (the PDF itself is not stored in the repository). Result: **13 programmes, 90 duty areas, 320 outcomes,
  229 evidence items, 18 qualification references, 3 reference pages.** Outcome codes: `TS-1.1` style.
- Qualification references are typed: occupational (QCTO), legacy, skills programme, related, or a **note**
  where the guide gives guidance but no registered qualification (e.g. Robotics, Data Analytics, Cellphone
  Repair, Technopreneur, End User Computing). These programmes need the curricula Muzi is collecting.
- Evidence types are a first guess from the wording (document, record, feedback, observation, sign-off,
  photo); HQ confirms them in Programme Studio (2.2).
- `php artisan programmes:import [--publish]` is repeatable and never overwrites a draft HQ is editing.
  The demo seeder loads and publishes the catalogue.
- Published and retired versions are frozen by a database trigger (content and children); only
  published → retired is allowed, and only one published version per programme.
- New provider permission `catalogue.view` ("See programmes, workplace outcomes and the mentor guide"):
  TP Admin, Centre Manager, Facilitator, Assessor/Mentor, Moderator. Learners will see their own programme
  through enrolment (Sprint 3).
- Published content is cached forever by version id (it cannot change); the list is cleared on publish.
