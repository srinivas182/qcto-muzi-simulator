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

## Sub-sprint 1.3 decisions (identity)
- **Learners:** mobile number + 6-digit one-time code by WhatsApp, falling back to SMS (Clickatell). Codes stored hashed, 10-minute life, 5 attempts, 3 sends per 10 minutes per number. Unknown numbers get the same answer (no account discovery).
- **Staff:** email + password (5 attempts/minute), optional authenticator-app 2FA (RFC 6238) with 8 single-use recovery codes. HQ staff sign in only on platform hosts (`/api/v1/platform/auth/*`).
- **Tokens:** Sanctum bearer tokens per device (web 7 days, mobile 60 days); people can see and sign out devices. A token only works at the provider that issued it (row-level security hides the user elsewhere).
- **Invitations:** link or QR (`/join/<token>`), 14 days, single use; phone invites prove the number with a code, email invites set a password.
- **POPIA:** terms + privacy notice accepted at join or first sign-in, versioned; a new version asks again. Records keep time, IP and device. The `consented` middleware guards learning routes from Sprint 1.5 onwards.
- **Password reset:** email link valid 60 minutes, single use, signs out all devices; never reveals whether an email exists.
- Web token storage approach is finalised in 1.5 together with the app shell (memory + secure refresh).

## Sub-sprint 1.4 decisions (roles, audit, support login)
- **12 system roles live in `config/roles.php`**, not the database, so improvements reach every provider at once.
  Shared rows in `roles` (tenant_id NULL) are only labels; providers build **custom roles** from the plain-language
  catalogue in `config/permissions.php`. `RoleMatrixTest` enforces the PRD §4 permission table.
- **No privilege escalation:** nobody can create or assign a role holding admin permissions they lack. Functional
  permissions (`learning.use`, `review_pack.view`, `attendance.confirm_workplace`) are exempt, so a centre manager can
  enrol learners without being one. Built-in roles cannot be edited or deleted; roles in use cannot be deleted; a
  provider always keeps one TP Administrator; HQ roles cannot be given inside a provider.
- **RLS for shared rows:** `roles` has two policies — SELECT sees shared + own rows; INSERT/UPDATE/DELETE reach only own
  rows, so a provider cannot delete a system role even with raw SQL.
- **"Preview as this role":** `GET /roles/{id}/preview` returns the plain-language permissions and the menu from
  `config/navigation.php`; Sprint 1.5 uses the same file for the role-aware home.
- **Audit log is append-only at the database level** (trigger refuses UPDATE/DELETE, even for HQ) and has no foreign
  keys so history outlives records. Recorded so far: sign-in, failed sign-in, sign-out, device revoked, 2FA on/off,
  password reset, consent granted/withdrawn, invitation accepted, roles created/updated/deleted/assigned, support
  started/ended. Data exports will be added with the reports module.
- **Support login:** HQ staff with `support.login` **and 2FA** open a session with a written reason (≥10 chars) for
  5–60 minutes. They get a separate token (ability `support`) that works only on that provider, only for GET requests,
  and stops when the session ends or expires. The provider sees every session (who, why, until when) and can end it.
  Mechanism: the database exposes the staff member's own user row inside the provider through `app.support_user_id`;
  nothing else of HQ becomes visible.
