# Sprint 4 — Learning content: simulators, AI courses, real progress

**Status:** direction and decisions approved by Srini (28 Nov 2026); building starts on his go-ahead.
**Moves forward:** C3 (translations: course text gets an AI draft, then first-language review), and the
foundation for C1 (staff tools read the same progress data).

Sprints 1–3 built the business: providers, programmes, seats, billing, learners, cohorts, QCTO. Learners can now
sign in and see their programme, but there is nothing to *do* yet. Sprint 4 delivers the product promise: **3D
workplace simulators and AI courses**, starting with one complete programme (Technical Support), with real
progress that drives the seat rules, the QCTO file and, later, assessment.

## What the market leaders do, and where they fall short

| Product | What it does well | Weakness for our market | What we do instead |
|---|---|---|---|
| **Transfr** (VR job training) | 330+ job simulations, built in VR for realism | One licence per headset, typically shared by 5–10 learners; simulations run only in its headset app and cannot be opened through an LMS. Meta withdrew its commercial Quest headsets in February 2026 | Runs on the learner's own phone and in the browser, with no headset; licensed per learner; inside the programme, next to the course and the evidence |
| **Bodyswaps** (AI role-play) | AI feedback on conversations; VR, desktop, browser and mobile | Soft skills only, not hands-on technical tasks; English-first | Hands-on tasks mapped to QCTO outcomes; scenarios in four SA languages |
| **Labster** (virtual science labs) | 300+ browser labs with automatic grading | Needs a laptop or desktop; plans start at USD 5,000 a year; reviewers report freezes that lose progress | Phone-first and data-light; progress saved step by step and synced later, so nothing is lost offline |
| **Synthesia** (AI avatar video) | Fast presenter videos in 160+ languages | Video is heavy on data; a talking head is not practice; no link to outcomes or progress | Lightweight animated lessons (text, pictures, narration audio, short checks) at a fraction of the data cost, each tied to outcomes; video is optional |
| **SA learner-management systems** | QCTO and SETA paperwork | No learning content at all | Content, practice and compliance in one place |

**Our additions:**
- **Phone-first and data-light:**
  - The 3D engine downloads once, about 135 KB, and is then cached.
  - A scene is at most 3 MB; a course lesson is about 150 KB with narration.
  - Whole programmes can be downloaded on Wi-Fi and used offline.
- **Mapped to QCTO:** every simulator step and lesson links to the programme's outcomes (Sprint 2), so the
  coverage map turns "linked", and assessors (Sprint 5) see the evidence.
- **Built by HQ without code:** HQ uses Scenario Studio and Course Studio. AI writes the first draft from the
  outcomes; people review it and publish it.
- **Progress is real:** it drives the 10% withdrawal rule, at-risk alerts, and the learner's own home screen.

## Gaps we fill and best recommendations (approved by Srini, 28 Nov 2026)

**Gaps against the leaders:**
1. **Access:** the learner's own phone, with no headset and no laptop; offline downloads; scenes ≤ 3 MB and
   lessons of about 150 KB.
2. **Language:** English, isiZulu, isiXhosa and Afrikaans, with narration. Switch language in the middle of a
   lesson and keep your place.
3. **Feedback on hands-on tasks:** coaching like the AI role-play tools give for conversations, but for
   technical tasks.
4. **Never losing work:** every step is saved on the device first and synced later.
5. **Price:** per learner seat; unused seats come back automatically.

**Interactive (in this sprint):**
- **Mistakes have consequences:** the scene reacts realistically (the RAM fails, the PC won't boot, a customer
  gets impatient). *(4.4)*
- **Practice mode and "real job" mode:**
  - Practice gives hints that get more specific each time the learner asks.
  - Real job mode has no hints, a clock and a customer waiting, and counts towards EISA readiness. *(4.4)*
- **Replay and debrief:** a short replay of each attempt with mistakes marked, a "why it matters" note, and a
  link to the lesson block that covers it. *(4.4 and 4.5)*
- **Branching:** the same fault can have different causes, so learners diagnose instead of memorising. *(4.5)*
- **Lesson → try it → fix it:** lessons of 3–5 minutes end with "try it in the simulator", and mistakes link
  back to the exact lesson block. *(4.2 and 4.3)*
- **AI coach in the simulator:** "Why?" and "Help" answer in the learner's language, limited to that scenario
  and its QCTO outcomes. *(4.7)*

**Easy to use (in this sprint):**
- **Audio first, for low-literacy learners:** every instruction can be heard; icons back up text; large touch
  targets; captions. *(4.3 and 4.4)*
- **One clear next step:** a "Continue" card on the home screen. *(4.3)*
- **Job-ready checklist:** built from the QCTO outcomes, ticked as skills are shown, shareable with a mentor
  or employer. *(4.6)*
- **Class mode:** the facilitator runs a scenario with the whole class and sees live who is stuck and where.
  *(4.7)*
- **Gentle encouragement:** no public leaderboard by default; reminders by SMS or WhatsApp in the learner's
  own language. *(4.6)*

**Proving it:** a pilot with one of Muzi's provider cohorts at the end of Sprint 4, measuring:
- completion of the first simulator;
- time taken and number of tries;
- data used per learner;
- how often learners ask for help;
- a 3-question learner rating.

We fix what the pilot shows before producing content for the other 12 programmes.

## Decisions (Srini, 28 Nov 2026)

1. **Simulators are real-time 3D.** Learners handle tools and parts in a 3D workplace on their own phone or in
   the browser. Phones without WebGL get a 2D step-by-step fallback.
2. **The 3D engine has its own budget of 150 KB gzip.** It downloads only when a simulator opens and is then
   cached. It is checked in CI like the other budgets. First load stays at 170 KB and pages at 25 KB.
3. **Courses use both kinds of lesson:**
   - **By default, animated lessons.** Claude drafts the text, checks and translations from the outcomes; HQ
     reviews and publishes. Narration comes from a text-to-speech provider, chosen in 4.2 by voice quality in
     isiZulu, isiXhosa and Afrikaans.
   - **Optional avatar video for key lessons.** This is a "video" block in Course Studio, with a low-data
     version and captions; the learner's data saver offers text instead.
   - **Still to choose (in 4.2, with costs for Srini):** the avatar-video provider and its licence. Until then
     the video block accepts an uploaded MP4.

## Sub-sprints

| # | Scope | Main deliverables |
|---|---|---|
| **4.1** | Foundation | Language files split by area (HQ / provider / learner) so each stays under 15 KB. The content model (activities, attempts, progress events), with an offline-safe sync API where each attempt has an ID from the device, so sending twice is harmless. Enrolment progress is calculated from required activities. |
| **4.2** | Course Studio (HQ) | Lessons made of blocks (text, picture, key points, narration, avatar video (optional), a short check, "try it in the simulator"). AI draft from the outcomes, then review, publish, and version with the programme. AI draft translations are marked "needs first-language review" (C3). |
| **4.3** | Course player | Web and mobile; narration with a data-saver setting (text only); resume where you left off; checks feed progress; works offline from a downloaded pack. |
| **4.4** | Simulator runtime | Scenario format: scene, steps (instruction, tap or inspect an object, choose, drag to place), hints, scoring linked to outcomes, attempts saved. Touch-first 3D on web; on mobile, the same runtime in a WebView, downloaded with the pack (the app size stays under 25 MB). A step-by-step 2D fallback for phones without WebGL. |
| **4.5** | Scenario Studio (HQ) and the first simulator | A form-based step editor on ready-made scenes, with preview. First scenario for Technical Support: "A PC won't boot — diagnose it and replace the faulty RAM safely" (antistatic strap, power off, reseat, test). |
| **4.6** | Offline packs and progress views | Download a programme on Wi-Fi; attempts are queued offline and synced later. Facilitators see progress per learner in a cohort, and learners who haven't been active for 7 days are flagged (feeds C1). Job-ready checklist; gentle SMS/WhatsApp reminders. |
| **4.7** | AI coach and class mode | An AI coach in the simulator ("Why?" and "Help"), answering in the learner's language and limited to the scenario and its outcomes; no learner personal data is sent. Class mode: the facilitator starts a scenario for the cohort and sees each learner's step live. Pilot measures in place. |

## Data model (outline)

- `activities`: programme version × kind (lesson | scenario), title, outcome links, required flag, weight,
  status (draft | review | published), version-locked like programmes.
- `lesson_blocks` and `scenario_steps`: ordered JSON content per locale; review status per locale.
- `attempts`: tenant (RLS), enrolment, activity, client attempt ID (unique), started, finished, score, result
  per step (JSON), duration, device.
- `progress_events`: append-only log of what the learner did, kept small, with a retention policy.
- `enrolments.progress`: recalculated from required activities after each sync.
- `content_assets`: scenes and audio, stored with content hashes for caching and integrity.

## Budgets and quality bars

- **First load:** stays ≤ 170 KB.
- **Lazy files:** pages ≤ 25 KB; 3D engine ≤ 150 KB.
- **Content size:** a scene is ≤ 3 MB; a lesson is ≤ 200 KB with audio; an avatar video's low-data version is ≤ 4 MB per minute.
- **Phone performance:** a simulator runs at 30 fps or better on a budget Android phone (checked in the
  emulator in CI, and on a real phone under C4).
- **Offline:** finish a lesson and a simulator offline, reconnect, and progress appears with no duplicates.
- **Accessibility:** every simulator step has a text instruction and an alternative to precise dragging.
- **POPIA:** attempts hold no personal data beyond the enrolment link; AI drafting never receives learner data.

## Not in Sprint 4

- Assessment, evidence and assessor sign-off (Sprint 5).
- Workplace logbook (later).
- AI role-play conversations.
- VR headsets.
- Courses for the other 12 programmes (the studios make them possible; content production runs alongside).

## Sub-sprint 4.1 notes (foundation)

- **Language files split by area:** each language still has one source file (translators and the mobile app
  see no change). The web build splits it into a learner part and a staff part:
  - Learners download **4.6 KB** of isiZulu, isiXhosa or Afrikaans instead of 14.3 KB.
  - Staff also load the staff part (10.5 KB) when a staff screen opens.
  - English still ships whole, so the app never shows a blank label.
  - Tests check that the two parts together are exactly the whole file, and that every text a learner screen
    uses is in the learner part.
- **Content model:**
  - `activities` holds HQ's lessons and scenarios for a programme version, linked to outcome codes, each with
    a weight and a "required" flag.
  - Publishing an activity marks its outcomes as "linked" on the coverage map (course or simulator channel).
- **Attempts (per provider, RLS):**
  - Each attempt has an ID made on the device, unique per provider.
  - Sending the same attempt twice changes nothing; a finished attempt never changes; an unfinished one can be
    continued after going offline.
  - Each record is checked on its own (the learner's own enrolment, the published activity of the
    enrolment's pinned version, the right mode, plausible times), so one bad record never blocks the rest.
  - The first activity counts as starting the programme, so the no-show rule stops.
- **Progress:**
  - Calculated as the weighted share of required activities completed at least once, and stored on the
    enrolment with the time of the last activity (for at-risk alerts in 4.6).
  - Progress now drives the 10% withdrawal rule.
- **Offline queue (shared by web and mobile):**
  - Saves every attempt on the device first, then syncs in batches of 50.
  - Survives an app restart, never loses a change when two happen at once, and drops records the server
    rejects for good so they are not retried forever.
- **Demo:** four published Technical Support activities (three lessons and the "A PC won't boot" scenario),
  with their content arriving in 4.2 and 4.5.

## Sub-sprint 4.2 notes (Course Studio)

- **Content tab** on each programme in HQ: its lessons and simulators, with a review chip per language
  (EN, ZU, XH, AF) and a status of draft, published or "unpublished changes".
- **Lesson editor** with a live preview on a phone-sized screen. Block types:
  - narration, with optional audio;
  - text and key points;
  - picture, with a description that is read aloud;
  - quick check, linked to an outcome;
  - video, optional, with a low-data version and the text shown on data saver;
  - "try it in the simulator".
- **AI draft:** Claude drafts a lesson from the outcomes it links, written for South African learners on cheap
  phones in plain words, with at least two quick checks. Only programme content is sent; never learner or
  provider data. The model and key are settings (`ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`). Without a key the AI
  buttons are hidden.
- **AI translation:** isiZulu, isiXhosa and Afrikaans drafts in everyday language, with workplace terms kept in
  English. Only text fields can be translated, so a translation can never change the right answer.
- **Review and publish:**
  - Authors (`content.author`), subject experts (`content.review`) and publishers (`content.publish`) are
    separate roles.
  - English must be reviewed before publishing.
  - Changing the English text clears every review; changing a translation clears that language's review.
  - **Learners see a translation only after a subject expert reviews it**; until then they get English (C3).
  - Unreviewed translations are kept, so review can carry on after publishing.
  - Republishing a changed lesson raises its content version.
- **Uploads:** checked by the file's real type, not its name, with limits that suit learners' data:
  pictures 400 KB, audio 1.5 MB, video 25 MB. Each file is stored once per content hash under a name that never
  changes, so phones can cache it for good.
- **New programme versions:** lessons and simulators carry forward automatically, keeping links only to outcomes
  that still exist. Learners on the old version keep their copies.
- **Demo:** three Technical Support lessons with real content, reviewed in English.
- **Still to decide:** the narration voice provider and the avatar-video provider, with costs, before content
  production. Until then narration is text, or an uploaded recording, and video is an uploaded MP4.
