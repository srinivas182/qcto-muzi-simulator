# ADR 0001 — Multi-tenancy: shared database, two layers of isolation

Date: 27 Nov 2026 · Status: accepted (Sprint 1.2)

## Context
HQ sells to many training providers (tenants). Each provider's learners, results and evidence are
personal information under POPIA and must never be visible to another provider. The platform must
also scale to many providers without one database per provider.

## Decision
- **One shared PostgreSQL database**, every tenant-owned table has `tenant_id`.
- **Layer 1 — application:** tenant-owned models use `BelongsToTenant` (global scope + automatic
  `tenant_id`). With no tenant context, queries return nothing; creating data without a context throws.
- **Layer 2 — database:** PostgreSQL row-level security (`ENABLE` + `FORCE`) with policy
  `tenant_id = current_setting('app.tenant_id')` or platform access. `TenantContext` writes these
  settings on every change. With no context set, the database returns no rows (fails closed).
- The application connects as a **non-superuser role** (superusers bypass RLS). A test fails if not.
- **Tenant resolution:** `<slug>.<platform domain>`, verified custom domains, or the `X-Tenant`
  header on platform hosts (mobile app / API host). Unverified domains, reserved sub-domains and
  archived tenants never resolve.
- **Custom domains:** ownership proven by TXT record `_simuwork-verify.<host>`; HTTPS certificates are
  issued on demand only for hosts approved by `/api/v1/internal/domain-allowed`.
- Large tenants or government partners can later move to a dedicated database cell without code
  changes (the context and policies are the same).

## Consequences
- New tenant-owned tables must call `Rls::enable('<table>')` in their migration and use `BelongsToTenant`.
- Queued jobs must set the tenant context explicitly (added with the first tenant job).
