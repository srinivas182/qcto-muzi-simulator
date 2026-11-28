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

## Sub-sprint 4.3 notes (course player)

**Built**
- API: `GET /learn/enrolments/{e}/lessons/{a}?locale=` returns the lesson in the learner's language (reviewed
  translations only, otherwise English, and says which it served), its pictures and audio, and the titles of
  the scenarios it links to. Another learner's enrolment, a draft or a scenario gives 404.
  `GET /learn/home` now carries `next` per programme (the first required activity not yet done).
- Web: programme page (`/learn/enrolments/:eid`), lesson player, simulator placeholder until 4.4,
  Continue / Start here card on learner home.
- Mobile: same three screens in expo-router (`app/learn/[eid]/…`), a native lesson view, narration via
  expo-audio, the Continue card on home.
- Both apps save each attempt on the device first (core `AttemptQueue`: localStorage on web, AsyncStorage on
  mobile) and sync on reconnect (online / visibility on web, NetInfo on mobile). The done screen says
  "Saved to your record" or "Saved on this phone".
- Data saver on by default: narration as text, pictures only on tap, video replaced by its text.

**Checks:** API 246 tests, web 86, mobile 32; first load 143.4 KB of 170; learner language part ≤ 5.1 KB.

### Gaps we fill and best recommendations (4.3)
| Gap against the leaders | What we did | When |
|---|---|---|
| Labster / Transfr need a laptop or headset and a steady connection | Lessons run on any phone, work offline, sync later | This sprint |
| Video-only courses (Synthesia-style) burn data | Data saver: text-first, pictures on tap | This sprint |
| Learners lose their place | Continue card on home, Next after every lesson | This sprint |
| Quizzes only at the end | Quick checks inside the lesson with an instant why | This sprint |
| Learners in Zulu / Xhosa / Afrikaans get English-only content | Reviewed translations, clear "in English for now" note | This sprint |
| Narration has no voice yet | Choose a TTS provider (open decision) | Proposed for later |
| A wrong answer just shows the answer | "Fix it" retry with a hint before the answer | Proposed for 4.5 |
| Learners cannot jump from a lesson into practice | "Try it" opens the simulator | Wired now, live in 4.4 |

## Sub-sprint 4.4 notes (simulator runtime)

**Built**
- **Scenario format** (`api/app/Learning/ScenarioContent.php`, mirrored in `packages/core/src/scenario.ts`):
  - A scene from the library (first: `pc-bench`), a pass mark, and a real-job brief (minutes and the customer).
  - Steps of three kinds: `tap`, `choose` and `place`. Place is tap-then-tap, so there is no precise dragging.
  - Each step has hints, a "why it matters" note, a QCTO outcome, a lesson link, a `critical` (safety) flag,
    `sets` (how the scene changes) and `wrong[]` (the consequence of each wrong move).
  - Reviewed translations only, the same rule as lessons.
- **Runtime:** a pure reducer shared by the web 3D view, the web simple view and the mobile app.
- **Server-side scoring:** a simulator attempt's score, completion and pass are worked out on the server
  from the learner's moves. Whatever the device claims is ignored.
  - Each step is worth 1, less 0.25 per mistake and 0.15 per hint.
  - A safety mistake fails real-job mode.
- **Modes:**
  - Practice has hints that get more specific each time.
  - Real job has no hints, a countdown and the customer's brief.
  - The mode screen shows the best score and tries for each mode.
- **Consequences:** a live PC sparks, static crackles blue and a wrong part beeps red, each with a message.
- **Debrief:** score and verdict, then every step marked (right first time, mistakes, hints), with when it
  happened, why it matters and "Review the lesson".
- **3D:** three.js in its own lazy chunk, 127.8 KB of 150 KB. The scene is built from shapes, so it needs no
  model download.
  - It renders only on change, at a pixel ratio of 1.5 or less.
  - Drag to look around; the camera steps back on narrow screens.
  - An object can't be tapped through the case wall.
  - Every object is also a big button below the scene (accessibility).
- **2D fallback:** phones without WebGL get the simple view, and anyone can switch to it.
- **Read aloud:** every instruction can be read aloud (browser speech on web, expo-speech on mobile).
- **Mobile:** the native step-by-step runtime with the same scoring, modes and debrief.

**Change from the plan (for Srini):** the 3D view on mobile (the WebView with a bundled runtime) moves to 4.6,
together with offline packs. The WebView has to load from the downloaded pack anyway. Until then, the app uses
the step-by-step view, and the browser on the same phone gives full 3D.

**Checks:** API 248 tests, web 92, mobile 33. First load is 144.2 KB of 170. The 3D engine is 127.8 KB of 150,
now checked in CI. The learner language part is at most 5.9 KB.

### Gaps we fill and best recommendations (4.4)
| Gap against the leaders | What we did | When |
|---|---|---|
| Transfr needs a headset; Labster needs a laptop | 3D in any phone browser; step-by-step view in the app and on phones without WebGL | This sprint |
| Wrong moves just say "incorrect" | Consequences you see (sparks, static, beeps) plus a plain-language reason | This sprint |
| One mode for everyone | Practice with graded hints; real job with clock, customer, no hints, and safety steps that fail the job | This sprint |
| Scores can be gamed on the device | Server re-scores every attempt from the moves | This sprint |
| No link back to learning | Debrief marks each step and links to the lesson that covers it | This sprint |
| Low-literacy learners | Read aloud on every instruction, big object buttons, icons | This sprint |
| Same fault, same fix every time | Branching causes for the same symptom | Proposed for 4.5 |
| Debrief is a list, not a picture | An animated 3D replay with mistakes marked | Proposed for later |
| Isolated practice | AI coach ("Why?" / "Help") inside the run | Proposed for 4.7 |
| 3D in the app offline | WebView runtime shipped in the offline pack | Proposed for 4.6 |
| Pinch to zoom on phones | Add pinch zoom to the 3D view | Proposed for later |

## Sub-sprint 4.5 notes (Scenario Studio and the first simulator)

**Built**
- **Scenario Studio (HQ)** at `/hq/programmes/:code/scenarios/:id`. The "Scenario Studio" and "Course Studio" menu items
  now open a list of every scenario or lesson, grouped by programme (`GET /platform/activities?kind=`).
  - **Settings:** scene, pass mark, real-job time and the customer brief.
  - **Steps are built with forms.** Choose the step type (touch, decide, move), then fill in:
    - the instruction;
    - the right object or answer, picked from the scene's objects;
    - up to 3 hints;
    - "why it matters";
    - the QCTO outcome;
    - the lesson for the debrief;
    - whether it is a safety step;
    - how the scene changes;
    - the wrong moves, each with what the learner sees and the message.
  - Steps can be reordered and removed.
  - **Live preview:** the real runtime (3D or simple view) beside the editor. Any edit restarts it. You can
    choose the cause and the mode to preview.
  - **Translations side by side**, AI draft translation, and review per language. The same rules as Course
    Studio apply, with one refinement: changing only the steps or answers clears the English review, while
    changing the words clears every review.
  - **Publishing** needs a reviewed English version and lesson links that exist in the programme.
- **Branching:** a scenario can list up to 4 causes of the same symptom, each with its own starting scene.
  - A step can belong to some causes only.
  - Each run picks a cause at random, so learners diagnose instead of memorising.
  - Learners never see the cause names, since those would give the answer away.
  - The attempt records which cause was played, and the server scores the run against that cause. Without
    it, the run scores nothing.
- **The first simulator, "A PC won't boot",** now has two causes:
  - **faulty RAM:** beeps, then strap, open, swap the RAM and test;
  - **loose power cable:** dead, then check the cable first, reseat it and test.
  - Both share the safety steps (switch off at the wall) and the final test.
  - The scene gained a power cable at the back of the PC.

**Checks:** API 253 tests, web 96, mobile 33. First load is 145.1 KB of 170. Staff language files are at most
12.5 KB of 15. The 3D engine is 127.8 KB of 150.

### Gaps we fill and best recommendations (4.5)
| Gap against the leaders | What we did | When |
|---|---|---|
| Transfr and Labster scenarios are built by the vendor only; providers wait months for changes | HQ builds and edits scenarios with forms, no code, with a live preview | This sprint |
| The same fault has the same answer every time, so learners memorise | Branching causes picked at random; learners diagnose | This sprint |
| Translations are bolted on | Side-by-side translation, AI draft, expert review per language | This sprint |
| Editing a live scenario breaks running attempts | Drafts are separate; publishing raises the content version; attempts keep their version | This sprint |
| Only HQ can author | Let large providers suggest scenario changes for HQ review | Proposed for later |
| One scene | More ready-made scenes for the other 12 programmes (a call-centre desk, a retail till, a reception counter) | Proposed with content production |
| "Fix it" retry after a wrong answer in lessons | A retry with a hint before the answer is shown | Proposed for 4.6 |
| Staff language files are nearing 15 KB (12.5 KB) | Split the staff part into HQ and provider parts | Proposed for 4.6 |

## Sub-sprint 4.6 plan (split into small parts)

| Part | Scope |
|---|---|
| **4.6a** | Facilitator progress board, at-risk flag (7 days), job-ready checklist (web and mobile) |
| **4.6b** | Gentle reminders by SMS or WhatsApp in the learner's language; sharing the checklist with a mentor or employer |
| **4.6c** | Offline packs: download a programme on Wi-Fi; 3D simulator in the app from the pack |
| **4.6d** | "Fix it" retry in lessons; split the staff language file into HQ and provider parts |

## Sub-sprint 4.6a notes (progress board and job-ready checklist)

**Built**
- **Progress board** (`GET /cohorts/{id}/progress`, page `/admin/cohorts/:id/progress`): every learner by every
  published activity.
  - Each cell shows done with the best score, passed as a real job, or started.
  - Summary tiles show learners, average progress, how many have been quiet for 7+ days, and how many are
    job-ready.
  - Quiet learners are listed first.
  - It is computed with a single grouped query over attempts.
- **At risk:** a learner who started and has done nothing for 7 days. They are flagged on the board and in
  the class list (`at_risk`, `last_activity_at`). This also feeds C1 and the reminders in 4.6b.
- **Job-ready checklist** (`GET /learn/enrolments/{e}/checklist` for learners, `GET /enrolments/{e}/checklist`
  for facilitators): every QCTO outcome, grouped by duty area and written in plain words, marked as:
  - **Shown**, when a real-job simulator covering it was passed;
  - **Practised**, when a practice run was passed or a lesson was finished with 70% or more;
  - **Not yet**.
  - On web and mobile. Facilitators open it from the board.

**Checks:** API 255 tests, web 98, mobile 34.

### Gaps we fill and best recommendations (4.6a)
| Gap against the leaders | What we did | When |
|---|---|---|
| Labster and Transfr report scores, not job readiness against the qualification | A checklist against the QCTO outcomes; only real-job passes count as "shown" | This sprint |
| Facilitators find out about drop-outs too late | Quiet learners (7+ days) are flagged and come first | This sprint |
| Dashboards are for admins, not learners | Learners see the same checklist in plain words | This sprint |
| Reminders | SMS or WhatsApp nudges in the learner's language for quiet learners | Proposed for 4.6b |
| Share with employer | A read-only checklist link the learner controls | Proposed for 4.6b |
| Step-level evidence | Show which step of which run proved each outcome, for assessors | Proposed for Sprint 5 (assessment) |

## Sub-sprint 4.6b notes (gentle reminders and shared checklists)

**Built**
- **Gentle reminders** (`learning:daily`, every day at 09:12 SAST; `LearnerNudges`):
  - Who gets one: a learner who started and has then been quiet for 7 days.
  - What they get: one short message in their own language (English, isiZulu, isiXhosa or Afrikaans), with a
    link back to where they left off.
  - **Limits:** at most one a week and three in a row. Any activity resets the count; after three, the
    facilitator follows up in person.
  - **Channel:** WhatsApp (an approved template, `WHATSAPP_REMINDER_TEMPLATE`) when configured, then SMS
    (Clickatell), then the log in development.
  - Only the first name, the academy, the programme and the link are sent.
  - Each reminder is written to the audit log.
  - **Learners can turn reminders off:** in My details on web, and in Me on mobile
    (`PATCH /learn/me/reminders`).
  - **Facilitator "Nudge"** on the progress board for quiet learners (`POST /enrolments/{e}/nudge`). It follows
    the same rules.
- **Shared checklists** (`checklist_shares`, with RLS):
  - **Making a link:** the learner makes a read-only link (`/c/<token>`), with a label such as "For my mentor
    at Acme". It can be copied or sent through the phone's share sheet.
  - **What the link shows:** first name and surname initial, the academy, the programme and the checklist.
    It never shows the ID number, contact details or scores.
  - **Safeguards:**
    - Links expire after 30 days, and there are at most 5 live links at a time.
    - The token is stored only as a hash.
    - Views are counted.
    - Pages are marked noindex.
    - Each link is tied to its provider's host.
    - The learner can stop a link at any time.
    - Creating and stopping links are audited.
  - The public page keeps the locked MCS footer (tested).

**Checks:** API 258 tests, web 100, mobile 36.

### Gaps we fill and best recommendations (4.6b)
| Gap against the leaders | What we did | When |
|---|---|---|
| Labster and Transfr send English e-mail nudges, if any | WhatsApp or SMS in the learner's own language, weekly at most, three at most | This sprint |
| Reminders feel like spam | The learner can turn them off; any activity resets the count; after three the facilitator takes over | This sprint |
| Employers cannot see what a learner can do | A checklist link the learner controls: plain words, and "shown on a real job" explained | This sprint |
| POPIA risk when sharing records | Minimal data, expiry, revocation, hashed tokens, audit log | This sprint |
| A reply to a WhatsApp reminder goes nowhere | Route replies to the facilitator's inbox | Proposed for later (needs a WhatsApp webhook) |
| Employers want a printed record | A print or PDF view of the shared checklist with a verification QR code | Proposed for later |
| Templates need Meta approval | Submit the reminder template in four languages; until then SMS is used | For Srini (operations) |
