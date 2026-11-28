# Sprint 4 — Learning content: simulators, AI courses, real progress

**Status:** draft, awaiting Srini's approval (28 Nov 2026).
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

## Decisions needed (asked separately)

1. **How simulators look:**
   - **Real-time 3D (recommended):** about 135 KB engine plus scenes of up to 3 MB.
   - **360° photos:** realistic, but you can only look, not handle objects.
   - **2D illustrated:** lightest, least immersive.
2. **Budget for the 3D engine:** pages and helpers are limited to 25 KB each. The 3D engine needs its own
   limit, proposed at **150 KB gzip**, downloaded only when a simulator opens and then cached. The first load
   (170 KB) does not change.
3. **AI for courses:**
   - **Recommended:** Claude drafts lesson text, checks and translations from the outcomes; HQ reviews and
     publishes. Narration audio comes from a text-to-speech provider (chosen in 4.2 by voice quality in
     isiZulu, isiXhosa and Afrikaans).
   - **Alternative:** avatar video from Synthesia or similar, at higher data and licence cost.

## Sub-sprints

| # | Scope | Main deliverables |
|---|---|---|
| **4.1** | Foundation | Language files split by area (HQ / provider / learner) so each stays under 15 KB. The content model (activities, attempts, progress events), with an offline-safe sync API where each attempt has an ID from the device, so sending twice is harmless. Enrolment progress is calculated from required activities. |
| **4.2** | Course Studio (HQ) | Lessons made of blocks (text, picture, key points, narration, a short check, "try it in the simulator"). AI draft from the outcomes, then review, publish, and version with the programme. AI draft translations are marked "needs first-language review" (C3). |
| **4.3** | Course player | Web and mobile; narration with a data-saver setting (text only); resume where you left off; checks feed progress; works offline from a downloaded pack. |
| **4.4** | Simulator runtime | Scenario format: scene, steps (instruction, tap or inspect an object, choose, drag to place), hints, scoring linked to outcomes, attempts saved. Touch-first 3D on web; on mobile, the same runtime in a WebView, downloaded with the pack (the app size stays under 25 MB). A step-by-step 2D fallback for phones without WebGL. |
| **4.5** | Scenario Studio (HQ) and the first simulator | A form-based step editor on ready-made scenes, with preview. First scenario for Technical Support: "A PC won't boot — diagnose it and replace the faulty RAM safely" (antistatic strap, power off, reseat, test). |
| **4.6** | Offline packs and progress views | Download a programme on Wi-Fi; attempts are queued offline and synced later. Facilitators see progress per learner in a cohort, and learners who haven't been active for 7 days are flagged (feeds C1). |

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
- **Lazy files:** pages ≤ 25 KB; 3D engine ≤ 150 KB (if approved).
- **Content size:** a scene is ≤ 3 MB; a lesson is ≤ 200 KB with audio.
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
