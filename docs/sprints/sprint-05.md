# Sprint 5: Assessment, evidence and the road to the EISA

**Status:** approved by Srini, 28 Nov 2026, with the decisions below.

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
- Recognition of Prior Learning (RPL) route.
- A direct upload to the QCTO or AQP portals, if they open an API.
- An AI first-pass check of written answers, always confirmed by a person.

## Decisions (Srini, 28 Nov 2026)
1. **Knowledge tests:** our own question bank in Course Studio, with AI drafting and expert review. Tests are
   timed, run on web and mobile, and are auto-marked; short answers are marked by a person.
2. **Practical evidence:** a passed real-job simulator run, reviewed by the assessor through the replay, plus a
   short observation on the phone.
3. **Workplace mentors: both options, and the provider chooses.**
   - The default is a WhatsApp or SMS link with a one-time code, and no account.
   - A provider can also invite mentors to sign in with their own account (for example large host employers
     with many learners). Either way a mentor sees only the learners linked to them.
4. **Test integrity: both levels, and the provider chooses per test.**
   - **Light (the default):** a server-enforced time limit, shuffled questions, one sitting, and a device log.
   - **Camera check (optional):** after the learner gives POPIA consent, the phone or browser takes a small,
     low-resolution photo at the start and a few at random during the test. Not video, so a 40-minute test
     costs about 200 KB.
     - Only the assessor or moderator of that test can see the photos.
     - Photos are deleted automatically 90 days after the result is final.
     - Learners who refuse consent, or have no camera, are referred to a supervised sitting at the provider.
     - The provider must enable it and accepts the POPIA impact assessment template we supply.

## Sub-sprints

| # | Scope | Main deliverables |
|---|---|---|
| **5.1** | Assessment model and knowledge tests | Assessment plan per programme version: modules → summative tasks (knowledge test, practical task, workplace task) mapped to outcomes. A question bank in Course Studio (multiple choice, short answer), with AI drafting and expert review. Timed tests on web and mobile that survive a dropped connection. Auto-marking, with short answers marked by a person. Integrity level per test: light, or camera check (consent, small photos, 90-day deletion). |
| **5.2** | Assessor workspace | Review queue. Simulator replay with the step-level evidence and its hash. An observation checklist on the phone that works offline. Decision of competent or not yet competent, with feedback linked to the lesson or step, and a re-assessment limit. Assessor declaration and sign-off; the audit log stores who decided what, when, and which evidence was seen. |
| **5.3** | Workplace logbook and mentor confirmation | Daily check-in against the workplace module outcomes, with a compressed photo or note. Mentors confirm weekly through a WhatsApp or SMS link with a one-time code, or by signing in with a mentor account (the provider chooses). The Statement of Work Experience is built from confirmed entries. Hours and days count towards QCTO minimums. |
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

## Sub-sprint 5.1a notes (assessment plan and question banks)

**Built**
- **`assessment_tasks`** (HQ content, versioned with the programme like activities):
  - each task has a component (knowledge, practical or workplace) and a kind (test, simulator, observation or
    logbook), restricted per component;
  - linked QCTO outcomes, settings, and a question bank for tests;
  - draft → review → publish;
  - carried forward when a new programme version is published, with simulator tasks re-pointed to the new
    copies.
- **Assessment tab** on the HQ programme page:
  - tasks per component;
  - an outcome coverage table (K, P, W), with uncovered outcomes highlighted and counted;
  - "New assessment task" (title, component, kind, simulator, outcomes).
- **Task editor** (`/hq/programmes/:code/assessment/:id`):
  - **Settings per kind:**
    - test: questions per sitting, time, pass mark, attempts, and integrity (light or camera check, with a
      plain explanation);
    - simulator: a real-job run plus assessor review;
    - observation: a checklist;
    - logbook: minimum confirmed days.
  - **Question bank:**
    - question types: one right answer, several right answers, or a short written answer with a model answer
      for the assessor;
    - each question has marks and a QCTO outcome.
  - **Drafting and translation:**
    - **AI drafts 10 questions** from the task's outcomes; only programme content is sent;
    - AI translation sits side by side with the English, with expert review per language;
    - **model answers are never translated** and stay with the assessor.
  - **Publishing** needs a reviewed English version, a bank big enough for the questions per sitting, and a
    published simulator.

**Checks:** API 5 new tests, web 2 new tests.

### Gaps we fill and best recommendations (5.1a)
| Gap against the leaders | What we did | When |
|---|---|---|
| UK e-portfolios map to UK standards; SA PoE tools store scans | The assessment plan maps every summative task to the QCTO outcomes and shows the gaps | This sprint |
| Writing question banks takes weeks | AI draft from the outcomes; an expert reviews before publishing | This sprint |
| Tests only in English | Learners may take tests in isiZulu, isiXhosa or Afrikaans (reviewed); model answers stay in English for assessors | This sprint |
| Questions leak once learners share them | Each sitting draws a random set from the bank; answer keys never reach the device | 5.1b |
| One fixed pass mark | Per-task pass mark and attempts; providers set the integrity level | This sprint |
| Item statistics | Show which questions most learners get wrong, so HQ can fix unclear ones | Proposed for later |

## Sub-sprint 5.1b notes (learners take knowledge tests)

**Built**
- **`test_sittings`** (with RLS): each sitting fixes its paper when it starts, so a resumed sitting shows the
  same paper. The paper is a random draw of questions with each question's options shuffled.
  - The **deadline is the server's.**
  - Answers keep the order the learner saw; the server maps them back to the original options.
- **The learner's device never receives answers:** no answer keys, explanations or model answers (tested).
  Reviewed translations are used.
- **Offline-safe:**
  - every answer is kept on the device first (localStorage on web, AsyncStorage on mobile) and sent 1.5
    seconds later;
  - if sending fails, it is sent again when the connection is back;
  - the server accepts answers up to **15 minutes after the deadline**, flagged "late" and visible to the
    assessor;
  - after that, the sitting closes with the answers the server already has;
  - when time runs out on the device, it hands in automatically.
- **Marking:**
  - choice questions are marked straight away (multi-choice needs exactly the right set);
  - **short answers wait for an assessor**: `GET /sittings/{s}` shows the answers, the key and the model
    answers, and `POST /sittings/{s}/mark` records marks, after which the result is final;
  - blank short answers score 0 without needing marking;
  - the result is competent or not yet competent against the task's pass mark;
  - the attempts limit is enforced, and a passed test can't be retaken.
- **What the learner sees afterwards:** the score, the result, and **the outcomes to revise, never the
  answers**, so the question bank is protected.
- **Learner screens (web and mobile):**
  - an Assessments page grouped by component, showing questions, time, pass mark, tries left and status;
  - a "ready to start" page with the three rules in plain words;
  - the test itself, with a sticky timer, an answered count, an offline notice, a hand-in confirmation, and the
    result.
- **Audit:** every start, hand-in and marking.

**Checks:** API 4 new tests, web 2 new tests, mobile 1 new test.

### Gaps we fill and best recommendations (5.1b)
| Gap against the leaders | What we did | When |
|---|---|---|
| Online tests fail when the signal drops; learners lose their answers | Answers are kept on the phone, synced later, with a 15-minute grace period visible to the assessor | This sprint |
| Leaked answer sheets | Random draw and shuffled options per sitting; keys never sent; learners see the outcomes to revise, not the answers | This sprint |
| The device clock can be changed | The server sets and enforces the deadline | This sprint |
| Marking written answers on paper | Assessor screen with the answer, the model answer and marks (full workspace in 5.2) | This sprint (API), 5.2 (screens) |
| Learners who need more time | Accommodations per learner (extra time, read-aloud), set by the provider | Proposed for 5.2 |
| Read-aloud of test questions | Questions read aloud, as in the simulator | Proposed for 5.2 |

## 5.1c notes: optional camera check (done)

**Built**
- **Provider switch** on the Academy page: *Camera check on tests*, off by default. Switching it on shows the four promises (consent each time, four small photos and no video or sound, assessors and moderators only, deleted after 90 days) and needs the DPIA tick. Who switched it on, and when, is stored and audited (`academy.camera_checks_on/off`).
- **Per test**: HQ marks a test `integrity: camera`. It only runs with the camera when the provider has switched it on. Otherwise it runs at the light level.
- **Learner consent**: the start screen explains the check. *Start* stays disabled until the learner ticks *I agree*. The opt-out line points to a supervised sitting, which counts the same. The server refuses a camera test that is started without consent.
- **Photos**: on web, 160×120 JPEG at quality 0.5. On the phone, the camera's smallest picture size at quality 0.2. One photo is taken at the start and three at random times, with at most 6 per sitting and at most 60 KB each (JPEG/WebP only). Uploads are throttled. Photos are stored on private storage, never public, and nothing is kept on the device. If the camera can't start, the test carries on.
- **Access**: photos are listed only on the assessor's sitting view, and streamed only to `assessments.review` with `no-store`. Learners get 403, and other learners get 404.
- **Deletion**: `learning:daily` deletes photos 90 days after the result is final, for every provider whatever its status.

**Tests**: API 274, web 111, mobile 42. First load 149.0 KB.

### Gaps we fill and best recommendations (5.1c)
| Gap against leaders | What we did / recommend | When |
|---|---|---|
| Proctoring tools (Proctorio, Honorlock, ExamSoft) record full video, need a desktop extension and break on low-end phones and weak data | Four 60 KB photos, phone-first, works with patchy signal; total per test ≈ 0.06 MB | In this sprint |
| Leaders make proctoring all-or-nothing, so learners without a camera or who refuse are locked out | Opt-out to a supervised sitting is shown on the consent screen and counts the same | In this sprint |
| POPIA: biometric-like data kept indefinitely, no DPIA | Provider-level DPIA acceptance, 90-day automatic deletion, assessor/moderator-only access, audit | In this sprint |
| Assessor must eyeball every photo | Show photos as a small strip in the 5.2 replay, beside the answers | 5.2 |
| Photo of the wrong person is not detected | Optional on-device face-present check (no face matching, no biometrics stored) to flag empty or multiple-face photos | Proposed for later |
| Phone photo size depends on the camera's smallest size | Add on-device resize (expo-image-manipulator), reused by the 5.3 logbook photos (≤150 KB) | 5.3 |

## 5.2a notes: assessor workspace (done)

**Built**
- **One review queue** at *To review* (`/assess`), with a cohort filter and four tabs:
  - *Simulator runs*: the newest real-job run not yet judged, with its score, whether a critical step was missed, and which opportunity it is.
  - *Observations*: marked *Ready* once the learner is competent on the simulator tasks of the same component.
  - *Written answers*: tests waiting for marking, flagged *Late* or *Camera check* where that applies.
  - *Done*: the last 50 decisions.
  The queue is built with a few set-based queries, so it stays fast for 200 learners.
- **Run replay**: every step in order, and each move with its time, shown as right ✓, wrong ✗, or hint/coach.
  - It also shows mistakes and hints per step, steps not done, critical steps, the version played against the current version, and the device used.
  - **Evidence fingerprint**: a SHA-256 over the moves, the version and the finish time. It is stored with the decision, and the replay shows whether the evidence is unchanged.
- **Decision form**:
  - competent or not yet competent;
  - feedback, which is required for not yet competent;
  - **links to a simulator step or a programme lesson** (the server only accepts real steps or lessons);
  - the declaration;
  - *Sign off*, which needs the `assessments.signoff` permission. Moderators can review but cannot sign off.
  - The draft is kept on the device.
- **Observation checklist**: met or not met for each item, with a note. The server refuses *competent* unless every item is met.
- **Rules**:
  - one decision per run;
  - no decision after competent;
  - **re-assessment limit**: each task's `attempts` setting, 3 by default;
  - every decision is audited (`assessments.decided` with the round and the hash).
- **Test marking screen**: the learner's answer beside the model answer or the right answer, marks for each short answer, and small camera-check photos (from 5.1c).
- **Learner view**: practical tasks show the assessor's feedback, *Practise this step* links (to the simulator) and *Redo the lesson* links, plus how many opportunities are left.

**Tests**: API 277, web 114, mobile 42. First load 150.1 KB.

### Gaps we fill and best recommendations (5.2a)
| Gap against leaders | What we did / recommend | When |
|---|---|---|
| OneFile, Smart Assessor and Aptem judge uploaded files: the assessor never sees *how* the learner worked | Move-by-move replay of the real job, with time, mistakes, hints and critical steps | In this sprint |
| Evidence can be swapped after sign-off | SHA-256 fingerprint stored at sign-off; the replay warns if the evidence has changed | In this sprint |
| Feedback is free text, and the learner has to work out what to redo | Feedback links open the exact simulator step or lesson | In this sprint |
| Unlimited re-submissions blur the QCTO "opportunities" rule | Opportunity counter on every task; the limit is enforced; a development plan is required after the last one | In this sprint |
| Observation is paper, typed up later | Checklist on screen, saved on the device as you go | In this sprint (full offline send in 5.2b) |
| Assessor can't watch the 3D run itself | A visual replay in the 3D scene, driven by the stored moves | Proposed for later |
| Assessors re-type the same feedback | Feedback snippets per step, learned from earlier decisions (AI-suggested, assessor-approved) | Proposed for later |
