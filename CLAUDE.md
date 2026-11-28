# Working rules for this repository

## Product
SimuWork (working name) — multi-tenant SaaS of 3D workplace simulators and AI courses for QCTO occupational
programmes. HQ (Super Admin) sells packages to training providers per programme and per learner seat.
Requirements: PRD v1.2 and UI/UX mock-ups v1.2 (Mayura Consultancy Services, 26 Nov 2026).

## Sprint process
1. Before each sprint: detailed spec in `docs/sprints/`, including what market leaders do, their weaknesses,
   and our additions. Get approval before building.
   Every sprint spec and every sprint report must also include a **"Gaps we fill and best recommendations"**
   section (Srini, 28 Nov 2026): the gaps against the leaders for our learners and providers, and the
   recommended improvements for interactivity and ease of use, each marked as in this sprint or proposed for later.
2. Small sub-sprints. Check locally first (Pint, PHPStan, tests, lint, types, build).
3. Open a PR per sub-sprint; merge only when CI is fully green. Draft PRs run quick checks only.
4. After merging, report status and propose the next sub-sprint.

## Non-negotiables
- **Locked footer:** "Developed and Maintained by Mayura Consultancy Services" linked to
  https://www.mayuraconsultancy.com on every tenant page (web, mobile, tenant websites). No tenant setting may
  change or hide it. Tests must cover it.
- **Tenant isolation:** no query may cross tenants; PostgreSQL row-level security plus tests.
- **Speed:** web JS ≤ 170 KB gzip for the shell; API p95 < 150 ms for standard reads.
- **POPIA:** consent, minimal data, audit log for sensitive actions.
- API is versioned under `/api/v1`. Controllers stay thin; business logic in services/actions.
- Strict types in PHP; TypeScript strict in web and mobile.
