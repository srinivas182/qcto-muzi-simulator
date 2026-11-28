# Sprint 5: Assessment, evidence and the road to the EISA

**Status:** draft for approval (Srini). Nothing is built until this is approved.

## Why this sprint
Learning is never the end of a QCTO occupational programme. The learner must:
- pass the provider's **internal summative assessments** in all three components (knowledge, practical skills
  and workplace experience);
- receive a **Statement of Results (SoR)** from the provider;
- submit a completed **Statement of Work Experience**;
- then register for and pass the **External Integrated Summative Assessment (EISA)** at an accredited
  assessment centre.

The SoR must state the learner's achievement in every component. It must carry the wording that it is not an
Occupational Certificate, because only the QCTO issues certificates. Today providers do this with paper
portfolios of evidence (PoE), spreadsheets and scanned logbooks. Sprint 5 makes all of it digital, and turns
the simulator work from Sprint 4 into **evidence an assessor can trust**.

## What the market leaders do, and where they fall short

| Leader | What they do well | Where they fall short for our learners and providers |
|---|---|---|
| **OneFile** (UK) | Mature e-portfolio; learners submit evidence; assessors sign off; employer tasks | Built for UK apprenticeship rules (ESFA/Ofsted), not QCTO. Evidence means uploaded files. Mentors need accounts. Desktop-first. English only. |
| **ePortfolio / Smart Assessor** (OneAdvanced, UK) | Evidence, off-the-job hours, internal quality assurance built in, reporting | Same UK rule set. Heavy uploads (photos and video) cost South African learners data. No link to simulations. |
| **Aptem / Bud** (UK) | End-to-end apprenticeship delivery, reviews, gateway to end-point assessment | Priced for UK providers. Many modules. Not mobile-first or offline. |
| **Cloud Assess** (AU) | Observation checklists, workplace and practical assessments, audit-ready records | Australian VET rules. Observation forms are paper turned digital. No simulation evidence. |
| **CloudPOE, eduSYMS/skillSYMS, P4P** (SA) | QCTO and SETA aware; digital PoE; moderation workflow; MIS exports | They mostly **digitise paper**, so the PoE is still scans and uploads. No built-in practical simulation. Assessors still observe everything in person. Limited language support. |

**The common weakness:** in every tool, evidence is something the learner *uploads* and the assessor *trusts*.
None of them produces evidence of practical competence by itself.

## Gaps we fill and best recommendations

**Gaps against the leaders (planned for this sprint):**
1. **Evidence that proves itself.**
   - A real-job simulator run is recorded move by move, re-scored by the server, and sealed with a hash.
   - The assessor replays it step by step instead of trusting a photo. *(5.2)*
2. **Mentors without accounts.**
   - Workplace mentors confirm logbook days and tasks from a WhatsApp or SMS link with a one-time code. No
     sign-up and no password. *(5.3)*
3. **Data-light evidence.**
   - Logbook photos are compressed on the phone (about 100 KB each).
   - Most evidence is simulator data measured in kilobytes, not video. *(5.3)*
4. **QCTO-native outputs.**
   - The SoR follows the QCTO template and wording.
   - The Statement of Work Experience and the EISA readiness checklist are produced automatically. *(5.5)*
5. **Moderation that is fair.** The sample is drawn automatically: a percentage of each assessor's decisions,
   every "not yet competent" decision, and every first decision by a new assessor. *(5.4)*
6. **Four languages** for learners and mentors, as in Sprint 4. *(all)*

**Interactive and easy to use (in this sprint):**
- **A "ready for the EISA" progress ring** for each learner: knowledge, practical, workplace and documents.
  It shows exactly what is still missing. *(5.5)*
- **Assessor review on the phone**, including offline observation checklists for workplace visits. *(5.2)*
- **Feedback that teaches:** a "not yet competent" decision must say what to fix, and links the learner to the
  lesson or simulator step. *(5.2)*
- **The logbook as a 30-second daily check-in:** today's tasks are ticked from the workplace outcomes, with an
  optional photo or note. *(5.3)*
- **One-tap mentor confirmation** in the mentor's language. *(5.3)*

**Proposed for later:**
- Photo-ID check at the start of online summative tests (needs a POPIA impact assessment).
- Recognition of Prior Learning (RPL) route.
- A direct upload to the QCTO or AQP portals, if they open an API.
- An AI first-pass check of written answers, always confirmed by a person.

## Decisions for Srini (see the questions sent with this spec)
1. **Knowledge summative tests:** our own question bank with timed online tests (recommended), or only record
   results from tests run elsewhere.
2. **Practical summative:** a passed real-job simulator run, reviewed by an assessor, counts as practical
   evidence alongside a short observation (recommended); or observation only.
3. **Workplace mentors:** a link with a one-time code and no account (recommended), or mentor accounts.
4. **Online test integrity:** light (time limit, shuffled questions, one sitting, device log) (recommended);
   or webcam proctoring (heavy on data, plus a POPIA risk).

## Sub-sprints

| # | Scope | Main deliverables |
|---|---|---|
| **5.1** | Assessment model and knowledge tests | Assessment plan per programme version: modules → summative tasks (knowledge test, practical task, workplace task) mapped to outcomes. A question bank in Course Studio (multiple choice, short answer), with AI drafting and expert review. Timed tests on web and mobile that survive a dropped connection. Auto-marking, with short answers marked by a person. |
| **5.2** | Assessor workspace | Review queue. Simulator replay with the step-level evidence and its hash. An observation checklist on the phone that works offline. Decision of competent or not yet competent, with feedback linked to the lesson or step, and a re-assessment limit. Assessor declaration and sign-off; the audit log stores who decided what, when, and which evidence was seen. |
| **5.3** | Workplace logbook and mentor confirmation | Daily check-in against the workplace module outcomes, with a compressed photo or note. Mentors confirm weekly through a WhatsApp or SMS link with a one-time code. The Statement of Work Experience is built from confirmed entries. Hours and days count towards QCTO minimums. |
| **5.4** | Internal moderation and appeals | Automatic sampling rules per provider. Moderator review that agrees or sends back to the assessor. Appeals by the learner, with a deadline. Moderation report per cohort for SETA and QCTO visits. |
| **5.5** | Statement of Results and EISA readiness | SoR PDF on the QCTO template (provider letterhead, qualification and level, learner, achievement per component, the "not an Occupational Certificate" wording, signatures). EISA readiness per learner and per cohort (SoR, work experience, ID copy, QCTO details from Sprint 3). PoE export as a single PDF or ZIP for the AQP or assessment centre. |
| **5.6** | Pilot hardening and Sprint 5 report | Evidence retention and deletion policy (POPIA); evidence storage limits; performance on a cohort of 200 learners; the report with "Gaps we fill and best recommendations". |

## Data model (outline)
- `assessment_tasks`: programme version × module (knowledge, practical or workplace) × kind (test,
  simulator, observation, logbook); outcomes; pass rule; attempts allowed.
- `questions`: bank per programme version; type; text per language with review status; answer key; outcome.
- `assessments`: tenant (RLS); enrolment; task; status (open, submitted, competent, not yet competent,
  moderated); assessor; decision; feedback; evidence references; sealed evidence hash.
- `evidence_items`: tenant (RLS); kind (simulator run, test sitting, observation, logbook entry, file); hash;
  size; retention date.
- `logbook_entries` and `mentor_confirmations`: tenant (RLS); date; tasks (outcome codes); note or photo;
  mentor name and phone; one-time code confirmation; channel.
- `moderation_samples`, `appeals`, `statements_of_results`: tenant (RLS), audited.

## Budgets and quality bars
- **Web:** first load stays ≤ 170 KB, and each new page ≤ 25 KB.
- **Phone photos:** compressed to ≤ 150 KB before upload.
- **A test sitting** works offline once started. Answers are queued like attempts, and the timer is enforced on
  the server.
- **Every decision is audited**, and evidence can't be changed after sign-off (hash checked on view).
- **POPIA:** minimal data, evidence retention per policy, mentors see only the logbook entries they confirm.
- **Tenant isolation:** row-level security plus cross-tenant tests on every new table.
- **The locked footer** is on every new page, including the mentor confirmation page.

## Not in Sprint 5
- Running the EISA itself: that is the assessment centre's job. We prepare the learner and the documents.
- QCTO certificate issuing: only the QCTO does this.
- Funding and grants (SETA discretionary grants), and payroll for learnerships.
