# SimuWork (working name)

Interactive 3D workplace simulators and AI-generated courses for QCTO occupational programmes,
sold as multi-tenant SaaS to training providers (licensed per programme and per learner seat).

Developed and maintained by [Mayura Consultancy Services](https://www.mayuraconsultancy.com).

## Repository layout

| Path | What it is |
|---|---|
| `api/` | Laravel 12 API (PHP 8.4, PostgreSQL, Redis), versioned under `/api/v1` |
| `web/` | React + TypeScript + Vite: Super Admin, provider portal and learner web |
| `mobile/` | React Native (Expo) learner and mentor app |
| `packages/ui/` | Shared design tokens and platform-owned UI (locked MCS footer) |
| `docs/` | Sprint specifications and architecture decisions |

## Run locally

```bash
docker compose up
```

- API health: <http://localhost:8000/api/v1/health>
- Web app: <http://localhost:5173>

Without Docker: run PostgreSQL 16 and Redis 7, then `cd api && cp .env.example .env && composer install && php artisan key:generate && php artisan migrate && php artisan serve`, and `npm install && npm run dev:web` from the root.

### Try the portals with demo data

Browsers send `*.localhost` to your own machine, so each academy and HQ get their own address:

```bash
cd api
export PLATFORM_DOMAIN=localhost MESSAGING_DRIVER=log
php artisan migrate:fresh && php artisan db:seed --class=DemoSeeder
php artisan serve                      # API on :8000
npm run dev:web                        # web on :5173 (from the repo root)
```

| Portal | Address | Sign in as (password `demo-password-2026`) |
|---|---|---|
| Training provider | <http://ubuntu.localhost:5173> | `admin@ubuntu.demo`, `centre@`, `facilitator@`, `assessor@`, `moderator@`, `learner@`, `qcto@ubuntu.demo` |
| HQ (Super Admin) | <http://admin.localhost:5173> | `srini@hq.demo`, `support@hq.demo`, `content@hq.demo` |

Phone sign-in codes are written to `api/storage/logs/laravel.log` in development.

## Quality gates (CI)

- **API:** Pint (style), Larastan level 6 (static analysis), PHPUnit on PostgreSQL.
- **Web:** oxlint, TypeScript (strict), Vitest, production build, first-load JavaScript budget (≤ 170 KB gzip), Lighthouse mobile (performance and accessibility ≥ 95).
- **Mobile:** TypeScript.
- Draft PRs run quick checks only; the full suite runs when a PR is marked ready. Only changed areas run.

## Platform rule

Every tenant page shows **"Developed and Maintained by Mayura Consultancy Services"**, linked to
<https://www.mayuraconsultancy.com>. Training providers cannot change or hide it. Tests enforce it.
