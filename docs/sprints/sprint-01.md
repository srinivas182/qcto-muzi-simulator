# Sprint 1 — Foundations: identity, multi-tenancy, design system

Status: **approved 27 Nov 2026** · Owner: Mayura Consultancy Services

## Why first
Every later screen depends on how fast the app loads, how easily people get in and how clean it feels.
Learners abandon slow, confusing or phone-hostile platforms, so these are fixed on day one.

## Market leaders and their weaknesses

| Leader | Strength | Weakness we fix |
|---|---|---|
| Transfr (VR career training) | 330+ job simulations mapped to standards | Needs a managed VR headset; licensed per headset; simulations not accessible through an LMS → **we run on the learner's own phone, licensed per learner** |
| Labster (virtual labs) | Engaging 3D labs with quizzes | Does not support phones; reviews describe it as laggy → **phone-first, fast** |
| Docebo (enterprise LMS) | Polished, multi-audience white-label | Reviewers cite an overly complex admin interface, weak reporting, difficult setup → **one main action per screen** |
| TalentLMS (simple LMS) | Very easy setup | Missing / limited features → **simple without losing depth** |
| Moodle Workplace (common with SA providers) | Flexible multi-tenancy | Outdated, confusing UI; slow on mobile; app differs from desktop → **one consistent design system on web and mobile** |

## Additions agreed for Sprint 1
1. Mobile-number sign-in with OTP by WhatsApp or SMS; email + password for staff.
2. Join by invite link or QR code, pre-filled.
3. Speed budget enforced in CI (small first load, cached app shell, prefetched routes).
4. Tenant branding from day one: logo, colours, fonts, **own custom domain**.
5. One design system for web and mobile.
6. Four interface languages ready: English, isiZulu, Afrikaans, isiXhosa.
7. POPIA consent at first sign-in; audit log from the first screen.
8. Role-aware home for all 12 roles.
9. ⌘K quick search for admins.

## Platform rule (non-negotiable)
Every tenant page — portal, learner app and any tenant website — shows the footer
**"Developed and Maintained by Mayura Consultancy Services"** with *Mayura Consultancy Services*
linked to <https://www.mayuraconsultancy.com>. Providers cannot change, hide or restyle it away.
It is rendered by the platform (not tenant-editable content) and covered by automated tests.

## Sub-sprints

| # | Scope | Done when |
|---|---|---|
| 1.1 | Monorepo: `api/` (Laravel 12), `web/` (React + TypeScript + Vite), `mobile/` (Expo shell), `packages/ui` (design tokens). Docker dev environment (PostgreSQL, Redis). CI with path filters, quick checks on drafts, full tests when ready. | Fresh clone runs with one command; CI green in under 10 minutes |
| 1.2 | Multi-tenancy core: tenants (training providers), centres, sub-domain and custom-domain resolution with verification, PostgreSQL row-level security, platform vs tenant context | Tests prove one tenant can never read another's data |
| 1.3 | Identity: invite link/QR, mobile + OTP (WhatsApp/SMS adapters), email + password, 2FA (TOTP), password reset, device sessions, rate limits, POPIA consent | Every sign-in route tested and rate-limited |
| 1.4 | 12 roles + custom roles, permission matrix, audit log, audited support login | PRD v1.2 permission table enforced in tests |
| 1.5 | Design system + shells (Super Admin, provider portal, learner web) per mock-ups v1.2; branding; language switcher; ⌘K; skeletons; responsive; locked MCS footer; performance budgets in CI | Matches mock-ups; Lighthouse mobile ≥ 95; shell ≤ ~170 KB gzip |

## Backlog items raised during approval
- **Website builder (new module M20):** each academy builds its public site (home, programmes, enrol) on its own domain, following its brand guidelines; platform footer locked. To be specified in its own sprint with a market-leader review.

## Performance targets
- Repeat navigation: tens of milliseconds (cached + prefetched).
- First load: < 1.5 s on mid-range Android over 4G.
- API p95 < 150 ms for standard reads.
