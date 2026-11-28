# Sprint 4 report: Simulators and AI courses

**Status:** complete. Twelve sub-sprint PRs (#24–#35, after the spec in #22 and #23), each merged only with CI fully green: API, web,
mobile, the Android release build with its size budget, and cold start.

## What was delivered

| Sub-sprint | Delivered |
|---|---|
| 4.1 Foundation | Activities and attempts; offline-safe attempt sync (device ids, sending twice is harmless); progress from required activities; language files split by area |
| 4.2 Course Studio | Lessons built from blocks; AI draft from the QCTO outcomes; AI translation; expert review per language; publishing with versions; uploads |
| 4.3 Course player | Web and mobile lessons; Continue card; quick checks; data saver; narration; offline attempts |
| 4.4 Simulator runtime | Scenario format; practice and real-job modes; consequences; server-side scoring; debrief linked to lessons; three.js 3D (own 150 KB budget); 2D fallback; read aloud |
| 4.5 Scenario Studio | Form-based step editor with live preview; branching causes; translations and review; first simulator "A PC won't boot" with two causes |
| 4.6a | Facilitator progress board; at-risk flag after 7 quiet days; job-ready checklist against the QCTO outcomes |
| 4.6b | Gentle reminders by WhatsApp or SMS in the learner's language; checklist links the learner can share and revoke |
| 4.6c | Offline packs (lessons, simulators, pictures, audio, 3D) with 304 update checks; 3D simulator in the app (WebView) |
| 4.6d | "Fix it" retry in lessons; HQ language part |
| 4.7a | AI coach ("Why?" and "Ask the coach") in four languages; never gives the answer; no learner data sent |
| 4.7b | Class mode: the whole class runs one simulator; live board showing who is stuck |
| 4.7c | Pilot measures and the 3-question learner rating |

## Quality and speed

- **Tests:** API 264 (about 2,100 assertions), web 106, mobile 38.
- **Static checks:** Pint, PHPStan, oxlint and strict TypeScript, all clean.
- **Web first load:** 147.2 KB of 170 KB.
- **3D engine (web):** 127.8 KB of 150 KB.
- **App 3D view:** 130.8 KB of 160 KB.
- **Language parts (isiZulu):** learner 7 KB, staff 11 KB, HQ 2.5 KB (each limited to 15 KB).
- **Android:** release APK size budget and cold start checked on every PR that touches mobile.
- **POPIA:**
  - Attempts carry no personal data beyond the enrolment link.
  - AI drafting, translation and the coach receive only programme or scenario text.
  - Shared checklists show only a first name and initial; their tokens are hashed, they expire and can be
    revoked, and both actions are audited.
  - Reminders can be turned off and are audited.
- **Tenant isolation:** the new tables (`attempts`, `checklist_shares`, `class_sessions`, `learner_ratings`)
  use PostgreSQL row-level security, with cross-tenant tests.
- **Locked footer:** kept on every new page, including the public checklist page (tested).

## Changes from the plan
- **3D in the mobile app** moved from 4.4 to 4.6c, together with offline packs, because the WebView loads the
  3D view from the downloaded pack. The browser on the phone had full 3D from 4.4 onwards.
- **4.6 and 4.7 were split into smaller parts** (4.6a–d and 4.7a–c) to keep each PR reviewable.

## Gaps we fill and best recommendations

### Gaps against the leaders (Transfr, Labster, Bodyswaps, Synthesia), all filled in this sprint
1. **Access**
   - Any phone or browser, with no headset and no laptop.
   - The whole programme works offline, 3D included.
   - The size is shown before downloading on mobile data.
2. **Language**
   - Lessons, simulators, reminders and the AI coach work in isiZulu, isiXhosa, Afrikaans and English.
   - Learners only see translations an expert has reviewed.
3. **Feedback on hands-on tasks**
   - Mistakes have visible consequences.
   - The debrief explains why each step matters and links back to the lesson.
   - The AI coach covers technical steps, not just conversations.
4. **Learners diagnose, not memorise**
   - Branching causes are picked at random.
   - Real-job mode has a clock, a customer, no hints, and safety steps that fail the job.
5. **Trustworthy results**
   - The server re-scores every simulator run.
   - "Shown on a real job" on the QCTO checklist needs a real-job pass.
6. **Facilitators act in time**
   - Quiet learners are flagged after 7 days and nudged in their own language.
   - Class mode shows who is stuck during the class.
7. **Employers can see what a learner can do**, through a read-only checklist link that the learner controls.
8. **Authoring without code**
   - HQ builds and changes lessons and simulators the same day, with a live preview.
   - AI helps with drafting and translation.

### Recommendations for interactivity and ease of use

**Done in this sprint**
- Lesson → try it → fix it: lessons link to the simulator, the debrief links back, and quick checks give a
  "Fix it" retry.
- Audio first: read aloud, narration, and large touch targets.
- One clear next step: the Continue card, and "Your class is doing this now".
- Gentle encouragement: no public leaderboard; at most 3 reminders, and learners can turn them off.

**Proposed for later**
- Voice for narration and the coach, once the text-to-speech provider is chosen.
- An animated 3D replay of each run.
- Pinch-to-zoom in 3D.
- Live class updates by WebSockets (Laravel Reverb), plus "send a hint to this learner" from the class board.
- Route WhatsApp replies to the facilitator's inbox.
- A printable or PDF checklist with a verification QR code.
- Automatic pack updates on Wi-Fi, offline packs in the web browser (service worker), and a "free up space"
  screen.
- Providers suggest scenario changes for HQ to review.
- More ready-made scenes for the other 12 programmes (call-centre desk, retail till, reception counter),
  alongside content production.
- Step-level evidence for assessors (Sprint 5).

## Open items for Srini
1. **Anthropic API key:** switches on AI drafting, translation and the coach. Until then the coach uses the
   author's hints.
2. **Text-to-speech provider** for narration in isiZulu, isiXhosa and Afrikaans.
3. **Avatar-video provider** and its licence. Until then, uploaded MP4 files are used.
4. **WhatsApp reminder template** approved by Meta in four languages. Until then reminders go by SMS.
5. **Pilot:** choose one of Muzi's cohorts and dates. The measures are in place under each class's progress
   board.
6. Still open from earlier sprints:
   - the real-phone C4 check;
   - one sandbox test per payment gateway;
   - the real seller details.

## Proposed next: Sprint 5, assessment and evidence
Assessor review of simulator runs with step-level evidence, sign-off, the portfolio of evidence for the EISA,
and the workplace logbook. The spec will be written for approval first.
