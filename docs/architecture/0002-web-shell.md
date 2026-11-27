# ADR 0002 — Web app shell, sign-in cookie and design approach

Status: accepted (Sprint 1.5) · Date: 2026-11-27

## Separate designs for web and mobile
The web portals and the mobile app share only **invisible foundations**: brand colours (`--brand-*`), the
type scale and icon names, translations and the locked MCS footer wording. Each has its own components and
layouts: web is built for desks (side menu, dense tables, ⌘K, side panels), mobile for thumbs (bottom tabs,
gestures, bottom sheets, offline). Forcing one component set onto both makes both feel second-best.

## Sign-in on the web: HttpOnly cookie, not a token in JavaScript
- When the web app signs in (`X-Client: web`), the API moves the Sanctum token from the response body into an
  `HttpOnly`, `Secure` (production), `SameSite=Lax` cookie scoped to `/api` on that exact host. Every academy
  domain therefore has its own sign-in, and XSS cannot read the token.
- The `WebTokenCookie` middleware turns the cookie into the bearer token before tenancy and authentication run,
  so row-level security, support sessions and permissions work exactly as for mobile.
- **CSRF:** cookie-authenticated writes must send `X-Requested-With: XMLHttpRequest`; a cross-site form cannot
  add that header without a CORS pre-flight, which the API does not allow. Missing header → `419`.
- A `401` or sign-out clears the cookie. Mobile keeps bearer tokens (secure device storage).
- Rejected: Sanctum's stateful SPA mode — it needs every tenant domain in a static `stateful` list and a
  session store; custom academy domains make that brittle.

## One bootstrap request
`GET /api/v1/session` (and `/platform/session`) returns the person, permissions, menu, branding and any HQ
support session. The shell renders from it; there is no waterfall of calls on load.

## Speed
- Pages and languages load on demand; the first load is React, the router, the query cache and the shell
  (≈ 129 KB gzip; CI budget 170 KB, measured from `dist/index.html`).
- Lighthouse (mobile, simulated 4G) runs in CI: performance and accessibility must be ≥ 95.
- Skeletons shaped like the final layout; no spinners on first paint; no layout shift.

## Languages
English, isiZulu, Afrikaans and isiXhosa. TypeScript and a test require every key in every language with the
same placeholders. **The isiZulu, Afrikaans and isiXhosa texts are drafts and need first-language review
before launch.** API validation messages are still English (to be localised with the reports module).
