# Sprint 3 — Providers, packages, licences and enrolments

Status: **proposed, awaiting approval** · Date: 28 Nov 2026

## Why this sprint
Sprint 2 gave every module the same backbone: programmes, versions and workplace outcomes. Sprint 3 turns that
into a business. HQ onboards training providers, sells them **packages** (simulators and/or courses for a
programme), and providers pay for **learner seats**. Providers then add learners, put them in **cohorts** and
**enrol** them. Each enrolment uses one seat and stays on the programme version the cohort started with.

When this sprint is done, money comes in. Every later module (simulators, courses, attendance, logbook,
assessment) checks one thing: *does this learner have an active enrolment for this programme?*

## What we start from
- Providers exist as tenants (trial · active · suspended · archived), with roles, invitations, branding and
  audit (Sprint 1). HQ has a first, minimal Training providers page (Sprint 2.3).
- Permissions already defined: `providers.view/manage`, `packages.manage`, `billing.view/manage` (HQ);
  `learners.view/manage`, `licences.manage`, `billing.tenant` (provider).
- The published programme catalogue with versions that never change once published (Sprint 2).
- QCTO rules that shape the data:
  - Accredited providers must send learner enrolment details to QCTO **within 21 days** of starting a full or
    part qualification. For skills programmes the limit is **5 days**.
  - QCTO's enrolment upload needs, on every record, the provider's **SDP accreditation number** and the
    learner's **national ID**. Uploads outside the provider's accreditation period are rejected.

## What market leaders do, and where they fall short

| Platform | How they license and enrol | Weakness for SA training providers | Our answer |
|---|---|---|---|
| Labster (virtual labs) | Institutional licence; each unique student who opens a simulation counts once per year; quote-only pricing | Annual, quote-only, US-dollar deals built for universities; a small provider cannot see a price or buy 20 seats today | Published ZAR price list per programme; buy any number of seats online in minutes |
| Docebo | Billed on active users (monthly, yearly or registered active), quote-only | Bills change with usage, so they are hard to budget; enterprise contract sizes | One clear rule: a seat is a named learner for the term, with a free return for no-shows |
| TalentLMS | Plans either cap registered users or count monthly active users | With registered-user caps, admins must delete people to "clear the seat" for new learners, and lose their history | Seats return to the pool without deleting anyone; the learner's record and evidence stay for QCTO |
| Transfr (VR careers) | Licensed per headset | Needs managed hardware; not per learner | Learner's own phone; licensed per learner per programme |
| Moodle (common with SA providers) | Enrolment methods and cohorts; selling needs plugins | No seat pools, no invoices, no link to QCTO enrolment rules; dense admin screens | Seats, invoices, cohorts and the QCTO 21-day deadline built in |
| SA learner-management / SDP admin systems | Learner registers, SETA/QCTO upload files | Records only: learning happens somewhere else; data typed twice; manual uploads | Learner is added once; the same record drives the simulator, course, logbook and the QCTO enrolment file |

## Our additions (the "why SimuWork" for this module)
1. **Buy seats like a shop, not a procurement process.** A published ZAR price list per programme with volume
   prices, a live price calculator, and a VAT-inclusive total before you click Buy.
2. **Fair, predictable seats.** A seat is one named learner on one programme for the licence term. If a learner
   never starts within 14 days, the seat returns to the pool automatically. Withdrawing a learner never deletes
   their record or evidence.
3. **Prices locked at purchase.** Changing the price list never changes what a provider already bought,
   including later top-ups in the same pool.
4. **Proper tax invoices.** Sequential numbers that are never reused, VAT shown, PDF download, and paid status
   visible to both sides. Credit notes are used instead of deleting invoices.
5. **Cohort protection.** A cohort is pinned to the programme version it started on (from Sprint 2), so a new
   HQ version never changes a class mid-way.
6. **Learner onboarding on phones.**
   - Add learners one by one or import a spreadsheet, with row-by-row checks: SA ID check digit, duplicates and
     missing details.
   - Invite them by SMS, WhatsApp link or email.
   - In a classroom, learners can scan a cohort QR code to join.
7. **QCTO deadline built in.** Each cohort shows "QCTO enrolment due in N days" (21 for qualifications, 5 for
   skills programmes) and exports the enrolment register with the fields QCTO asks for.
8. **POPIA by design.**
   - ID numbers are encrypted at rest and masked on screen.
   - Showing a full ID needs a separate permission and is audited.
   - We collect only what QCTO needs, and consent is recorded at first sign-in.
9. **Licence health at a glance.** Seats used and free, learners who have not started, pools expiring in 30
   days, unpaid invoices — for the provider and for HQ.

## Scope

### In
- **HQ provider management:**
  - Create a provider and invite its first admin.
  - Company and accreditation details: legal name, registration and VAT numbers, QCTO SDP accreditation
    number and expiry, billing contact.
  - Status changes with a reason (trial → active → suspended → archived), each audited.
  - Provider detail page with users, licences, invoices and audit.
- **Packages and price list (HQ):**
  - A package is a programme plus what it includes: simulators, courses or both.
  - Each package has a term in months, a price per seat in ZAR excluding VAT, volume price bands and a minimum
    number of seats.
  - Packages are drafted, published or retired. Changing a package never changes existing licences.
- **Provider shop and orders:** packages with a price calculator; order seats or top up an existing pool.
  - Orders are invoiced straight away.
  - A licence pool goes live when the invoice is paid, or straight away if HQ allows that provider to pay later
    (on account).
- **Invoices and payments:**
  - Tax invoice PDF with VAT at 15%.
  - Payment by EFT: HQ records it against the invoice, using the invoice number as the reference.
  - Online payment: depends on **decision 3**.
  - Due dates and overdue reminders; credit notes; HQ billing dashboard.
- **Licence pools:**
  - Seats bought, used and free; start and end dates.
  - Top-ups are pro-rated to the pool's end date.
  - Renewal reminders at 30 and 7 days; seats freeze at expiry.
- **Learners (provider):**
  - List, add, import from CSV or Excel, edit, deactivate.
  - Invitations by SMS, WhatsApp link or email, plus a cohort QR code to join.
  - Minimal profile with SA ID or passport.
- **Cohorts and enrolments:**
  - A cohort has a centre, a programme, a pinned version, dates and facilitators.
  - Enrolling a learner uses a seat. Learners can be withdrawn or transferred.
  - The 14-day no-show return; the QCTO 21-day (or 5-day) reminder; an enrolment register export.
- **Learner side (web and mobile):** a learner's home shows their programmes, cohort and days left on their
  licence. An expired licence shows a clear "ask your academy" state.
- API under `/api/v1/platform/{providers,packages,billing}…` (HQ) and
  `/api/v1/{shop,orders,invoices,licences,learners,cohorts,enrolments}…` (provider).

### Out (later sprints)
- Simulator and course content itself (Scenario Studio, Course Studio, player) — Sprints 4 onwards. Until then,
  an enrolled learner sees their programme and outcomes with "simulators coming soon".
- Debit orders and automatic monthly card billing — after the first gateway goes live.
- Provider websites with an online "enrol" button — website builder M20 (commitment C2).
- Equity fields (race, disability) for the full QCTO and NLRD return. This is special personal information
  under POPIA, collected only in the compliance-reports sprint, with its own consent screen.
- Learners paying for themselves (consumer checkout) — not in the current business model.
- Resellers, partner commissions, multiple currencies.

## Data model (summary)
- `provider_profiles` (1:1 with tenant; HQ edits, provider reads):
  - Legal name, trading name, company registration number, VAT number, physical address.
  - QCTO SDP accreditation number and expiry date, billing email and phone.
  - `payment_terms`: pay before activation, or on account with N days.
- `packages` (platform):
  - code, name, description, `programme_id`, includes simulators and/or courses.
  - `term_months`, `min_seats`, status (draft · published · retired).
  - `package_prices`: seat bands `from_seats` → price per seat in cents, excluding VAT.
- `orders` (tenant, RLS):
  - package, seats, unit price locked at order time, new pool or top-up.
  - status (invoiced · paid · cancelled), who ordered.
- `invoices` (tenant, RLS; HQ reads in platform context):
  - Number from one platform-wide sequence that never skips or reuses a number.
  - Issue and due dates, provider details as they were on the day.
  - Lines, subtotal, VAT, total; status (issued · paid · overdue · credited); PDF.
- `credit_notes` (linked to an invoice).
- `payments`: method (EFT · gateway), amount, reference, recorded by or gateway event id.
- `licence_pools` (tenant, RLS):
  - package, programme, seats, start and end dates.
  - status (pending payment · active · expired · suspended), source orders.
- `learner_profiles` (tenant, RLS; 1:1 with the learner's user):
  - ID type (SA ID · passport).
  - ID number **encrypted**, plus a keyed hash so we can find duplicates without decrypting.
  - Date of birth, phone, home language, `qcto_learner_number` (when issued).
- `cohorts` (tenant, RLS):
  - name, centre, programme, **pinned programme version**, start and end dates.
  - facilitators, join code for the QR.
- `enrolments` (tenant, RLS):
  - learner, cohort, programme version, licence pool (the seat).
  - status (invited · active · completed · withdrawn · returned no-show).
  - When the seat was taken, when the learner started and finished, and a reason.
  - One active enrolment per learner per programme.

All provider data is tenant-owned with row-level security, like everything since Sprint 1. HQ reads it in the
audited platform context.

## Sub-sprints

| # | Deliverable | Done when |
|---|---|---|
| 3.1 | HQ provider management: list and detail, create provider + invite first admin, company and accreditation details, status changes with reasons and their effect on sign-in, audit. Provider "Academy details" page | Each status change is tested end to end (suspended provider staff see "account on hold"; archived cannot sign in); accreditation expiry warnings shown to both sides |
| 3.2 | Packages and price list (HQ), provider shop with price calculator | Published packages are frozen like programme versions; price bands tested; provider sees only published packages for active programmes |
| 3.3 | Orders, tax invoices (PDF), EFT payments recorded by HQ, online payment (per decision 3), licence pools, top-ups pro-rated, renewal and overdue reminders, credit notes, HQ billing dashboard | Invoice numbers never skip or repeat; VAT and pro-rating calculated in cents and tested; a pool goes live only when its terms are met; every money action audited |
| 3.4 | Learners: list, add, CSV/Excel import with row checks, SA ID validation, invitations (SMS · WhatsApp link · email), cohort QR join, encrypted ID numbers with masked display | Import of 500 rows in under 10 s with a clear error report; ID numbers never stored or logged in plain text; revealing a full ID needs its own permission and is audited |
| 3.5 | Cohorts and enrolments: pinned version, seat use, 14-day no-show return, withdrawal and transfer, licence health dashboard, QCTO deadline reminder and enrolment register export, learner home on web and mobile | A learner can never hold a seat they are not entitled to (concurrency tested); expired pools stop new enrolments; learner home shows programme and days left on both apps |

Each sub-sprint is its own PR, merged only when CI is fully green.

## Risks and how we handle them
- **Money mistakes:** all amounts are stored in cents (integers), and VAT is rounded once per invoice line.
  Invoice numbers come from a locked database sequence. Invoices are never edited or deleted, only credited.
  Tests cover pro-rating and price bands.
- **Two admins buying or enrolling at the same time:** taking a seat locks the pool row, and a database
  constraint stops a pool from going over its seats.
- **ID numbers are sensitive (POPIA):**
  - Encrypted with a key held outside the database.
  - Duplicate checks use a keyed hash, so we never need to decrypt to compare.
  - ID numbers are masked in lists, never written to logs, and every full reveal is audited.
- **QCTO format changes:** the enrolment register export follows QCTO's published data load specification. The
  exact columns are checked against the current document in 3.5, and the format is versioned so a QCTO change
  is a small update.
- **Unpaid accounts:** handled by clear, visible rules (decision 4), with reminders before anything happens,
  never a silent lock-out of learners mid-cohort.

## Decisions needed from Srini
1. **Pricing model.** Recommended: **price per seat per programme for a fixed term** (for example 12 months),
   invoiced once when seats are bought, with volume bands. The alternative is a monthly price per seat billed
   every month. The fixed term matches how cohorts run and how providers are funded, and is far simpler to
   collect by EFT.
2. **Seat rule.** Recommended:
   - A seat is a named learner for the term.
   - It returns to the pool automatically if the learner does not start within 14 days.
   - It returns on withdrawal only if the learner has done less than 10% of the programme. After that, the seat
     stays used.
3. **Payments.** Recommended: **EFT with HQ recording payments, plus Paystack for card, Instant EFT and Capitec
   Pay** in 3.3. Paystack is owned by Stripe, has a clean API, includes free settlement, and covers card and
   bank payments in one integration. The alternatives are PayFast (most payment methods, higher fees) or EFT
   only for now.
4. **Unpaid invoices.** Recommended:
   - Reminders at the due date, then after 7 and 14 days.
   - After 30 days the provider cannot buy more or enrol new learners.
   - Learners already enrolled keep learning until their term ends, because the learner is not at fault.
5. **Who invoices.** Which company and VAT number appears on invoices as the seller (Muzi's South African
   entity?), and its banking details for EFT. This is information, not a choice; it can come later, but is
   needed before 3.3 goes live.
