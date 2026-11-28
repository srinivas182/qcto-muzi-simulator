# Sprint 3 — Providers, packages, licences and enrolments

Status: **approved** (Srini, 28 Nov 2026)

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
  - HQ chooses how each package is billed (decision 1):
    - **fixed term**: a price per seat for N months, invoiced once;
    - **monthly**: a price per seat per month, invoiced every month while seats are held.
  - Each package has a price per seat in ZAR excluding VAT, volume price bands and a minimum number of seats.
  - Packages are drafted, published or retired. Changing a package never changes existing licences.
- **Provider shop and orders:** packages with a price calculator; order seats or top up an existing pool.
  - Orders are invoiced straight away.
  - A licence pool goes live when the invoice is paid, or straight away if HQ allows that provider to pay later
    (on account).
- **HQ billing settings (Super Admin):**
  - Seller details printed on every invoice: company name, registration number, VAT number, address.
  - EFT banking details shown on invoices and at checkout.
  - VAT rate, invoice number prefix and payment terms.
  - **Payment gateways:** Paystack, PayFast, Peach Payments, Ozow and Yoco. Each can be switched on or off, in
    test or live mode, with its own keys.
  - Keys are encrypted at rest, can be written but never read back (only the last 4 characters are shown), and
    have a "Test connection" button. Every change is audited.
- **Invoices and payments:**
  - Tax invoice PDF with VAT at 15%.
  - Payment by EFT: HQ records it against the invoice, using the invoice number as the reference.
  - Online payment through whichever gateways Super Admin has switched on. The provider picks one at checkout.
  - A paid invoice activates its pool automatically from the gateway's signed payment notification.
  - Due dates and overdue reminders; credit notes; HQ billing dashboard.
- **Licence pools:**
  - Seats bought, used and free; start and end dates.
  - Fixed term: top-ups are pro-rated to the pool's end date; renewal reminders at 30 and 7 days; seats freeze
    at expiry.
  - Monthly: an invoice on the 1st of each month for the seats held, with the first month pro-rated. Providers
    can lower seats for the next month, but never below the seats in use. Cancelling stops at the end of the
    paid month.
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
- `billing_settings` (platform, one row): seller details, EFT banking details, VAT rate, invoice prefix.
- `payment_gateways` (platform):
  - driver (paystack · payfast · peach · ozow · yoco), enabled, mode (test · live).
  - **encrypted** credentials, display order, last connection test result.
- `packages` (platform):
  - code, name, description, `programme_id`, includes simulators and/or courses.
  - `billing` (fixed term · monthly), `term_months` (fixed term only), `min_seats`, status (draft · published ·
    retired).
  - `package_prices`: seat bands `from_seats` → price per seat in cents, excluding VAT.
- `orders` (tenant, RLS):
  - package, seats, unit price locked at order time, new pool or top-up.
  - status (invoiced · paid · cancelled), who ordered.
- `invoices` (tenant, RLS; HQ reads in platform context):
  - Number from one platform-wide sequence that never skips or reuses a number.
  - Issue and due dates, provider details as they were on the day.
  - Lines, subtotal, VAT, total; status (issued · paid · overdue · credited); PDF.
- `credit_notes` (linked to an invoice).
- `payments`: method (EFT · gateway), gateway, amount, reference, recorded by or gateway event id.
- `payment_events`: every gateway notification, stored once by gateway and event id, and processed only once.
- `licence_pools` (tenant, RLS):
  - package, programme, seats, start and end dates.
  - billing (fixed term · monthly), seats for next month (monthly), status (pending payment · active · expired
    · cancelled), source orders.
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
| 3.3 | HQ billing settings (seller details, EFT details, VAT, invoice prefix), orders, tax invoices (PDF), EFT payments recorded by HQ, licence pools, fixed-term top-ups pro-rated, monthly invoicing run, renewal and overdue reminders with the decision 4 block, credit notes, HQ billing dashboard | Invoice numbers never skip or repeat; VAT and pro-rating calculated in cents and tested; a pool goes live only when its terms are met; every money action audited |
| 3.4 | Online payments: one gateway layer with Paystack, PayFast, Peach Payments, Ozow and Yoco; Super Admin switches each on or off, sets test or live keys, tests the connection; provider picks a gateway at checkout; signed notifications activate pools | Each gateway tested against recorded sandbox responses; a forged or repeated notification never marks an invoice paid; keys never returned by the API or written to logs |
| 3.5 | Learners: list, add, CSV/Excel import with row checks, SA ID validation, invitations (SMS · WhatsApp link · email), cohort QR join, encrypted ID numbers with masked display | Import of 500 rows in under 10 s with a clear error report; ID numbers never stored or logged in plain text; revealing a full ID needs its own permission and is audited |
| 3.6 | Cohorts and enrolments: pinned version, seat use, 14-day no-show return, withdrawal and transfer, licence health dashboard, QCTO deadline reminder and enrolment register export, learner home on web and mobile | A learner can never hold a seat they are not entitled to (concurrency tested); expired pools stop new enrolments; learner home shows programme and days left on both apps |

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
- **Payment notifications:** each gateway's notifications are verified by its signature, processed once
  (stored by event id) and checked against that gateway's API before an invoice is marked paid. If a gateway is
  down, the provider can pick another one or pay by EFT.
- **Gateway keys:** encrypted with the app key, never shown again after saving, never logged. Only Super Admin
  can change them, and each change is audited.

## Decisions (Srini, 28 Nov 2026)
1. **Pricing model: offer both.** HQ chooses per package:
   - **fixed term:** a price per seat for N months, invoiced once when seats are bought;
   - **monthly:** a price per seat per month, invoiced on the 1st of each month for the seats held.
   Both have volume price bands, and the price is locked at purchase.
2. **Seat rule: no-show and early withdrawal.**
   - A seat is a named learner.
   - It returns to the pool automatically if the learner does not start within 14 days.
   - It returns on withdrawal before 10% of the programme is done. After that, it stays used for the term (or,
     on monthly packages, until the provider lowers its seats).
3. **Payments: EFT plus several gateways, chosen by Super Admin.** HQ records EFT payments against the invoice
   number. Online gateways: **Paystack, PayFast, Peach Payments, Ozow and Yoco**. Super Admin switches on
   whichever are needed and enters their keys; providers choose at checkout. A confirmed payment activates the
   licence pool automatically.
4. **Unpaid invoices: block new, keep learners.**
   - Reminders at the due date, then after 7 and 14 days.
   - After 30 days unpaid, the provider cannot buy more seats or enrol new learners.
   - Learners already enrolled keep learning to the end of their term.

5. **Seller details are settings, not code.** Muzi's South African company is the seller. Super Admin enters
   its company details, address, VAT number, EFT banking details and the gateway keys in HQ → Billing settings.
   Nothing is hard-coded; development and tests use placeholder details.

## Sub-sprint 3.1 notes (provider management)
- **Onboarding:** HQ → Training providers → New provider. HQ enters the academy name, web address (suggested
  from the name; reserved and used addresses refused), optional accreditation number and expiry, and the first
  admin's name and email. One step creates the provider (status *trial*), its sub-domain and a "Main campus"
  centre, and emails a TP Admin invitation. The invitation link is also shown once, for sharing by WhatsApp or
  SMS. HQ can send more admin invitations later.
- **Details:** company (name, registered name, registration and VAT numbers), QCTO accreditation (number,
  expiry), contact and billing (emails, phone, address) and payment terms (*pay first* or *on account* with N
  days, used in 3.3). These are stored on `tenants` (one row per provider) rather than a separate
  `provider_profiles` table. HQ edits everything; the provider's **Academy details** page shows everything
  and lets it edit only contact, billing and address (new permission `academy.manage`, TP Admin).
- **Accreditation warnings:** *expiring* within 60 days, *expired*, or *not captured*, shown in the HQ list,
  on the provider page and on the provider's Academy details. QCTO rejects enrolment uploads outside the
  accreditation period.
- **Status:** trial → active · suspended · archived; suspended → active · archived; archiving is final. A
  reason is required and kept in the audit log, which is recorded under the provider.
  - **Suspended:** staff see "account on hold" (API answers 423 on provider routes). Learners keep learning,
    and HQ support sessions still work.
  - **Archived:** the academy's addresses stop resolving, so nobody can sign in.
- **Provider page (HQ):** status actions, invite admin, people and roles, centres, the own-outcomes switch,
  and the provider's activity from the audit log. Archiving asks HQ to type the provider's name.

## Sub-sprint 3.2 notes (packages and price list)
- **Package:** one programme; simulators, courses or both (at least one, checked by the database); billing
  **fixed term** (1–60 months, a price per seat for the whole term) or **monthly** (a price per seat per month);
  minimum seats; up to 10 price bands ("from N seats, R x per seat", cents, excluding VAT).
- **Life cycle:** draft → published (on sale) → retired. Published and retired packages are **frozen by a
  database trigger** (fields and prices), so a purchase always matches what was on sale; the only allowed
  change is published → retired. To change a package, HQ duplicates it into a new draft. Publishing needs a
  price band that starts at or below the minimum seats, and a programme with a published version. Every step
  is audited.
- **Pricing rules** (API `PriceCalculator`, web `quotePackage` in `packages/core`, tested to give the same
  results): the highest band at or below the number of seats; subtotal = seats × band price; VAT (15% until the
  billing settings in 3.3) rounded once, half up; "per learner per month" shown for comparison.
- **HQ → Packages:** on sale / drafts / retired, editor drawer with price bands and a live price preview,
  publish, retire, duplicate, delete draft. `packages.manage` edits; `billing.view` can look.
- **Provider → Licences & packages:** the shop (published packages on programmes with a published version)
  with a live calculator and a hint for the next price band. Ordering arrives in 3.3 (`licences.manage`).
- Demo: three packages on sale (Technical Support 12-month bundle, Data Analytics monthly courses,
  Cybersecurity Defender 6-month simulators).
