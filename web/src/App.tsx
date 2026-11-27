import { useQuery } from '@tanstack/react-query';
import { PlatformFooter } from '@simuwork/ui';
import { getJson, type HealthResponse } from './lib/api';

/**
 * Sprint 1.1 placeholder home. Replaced by the role-aware app shells in Sprint 1.5.
 * The platform footer is always rendered at the root, outside any tenant-controlled area.
 */
export default function App() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: () => getJson<HealthResponse>('/health'),
    retry: false,
  });

  const status = health.isPending ? 'checking' : health.isError ? 'offline' : health.data.status;
  const dot = status === 'ok' ? 'bg-emerald-500' : status === 'checking' ? 'bg-amber-400' : 'bg-red-500';

  return (
    <div className="flex min-h-full flex-col">
      <main className="flex flex-1 items-center justify-center p-6">
        <section className="w-full max-w-md rounded-2xl border border-[var(--color-line)] bg-white p-8 shadow-sm">
          <div className="flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--brand-primary)] text-lg font-bold text-white">
              S
            </span>
            <div>
              <h1 className="text-lg font-bold tracking-tight">SimuWork</h1>
              <p className="text-xs uppercase tracking-widest text-[var(--brand-accent)]">Working name</p>
            </div>
          </div>
          <p className="mt-6 text-sm leading-relaxed text-[var(--color-muted)]">
            Interactive workplace simulators and AI courses for QCTO occupational programmes.
          </p>
          <p className="mt-6 flex items-center gap-2 text-sm" aria-live="polite">
            <span className={`inline-block h-2.5 w-2.5 rounded-full ${dot}`} />
            API status: <strong>{status}</strong>
          </p>
        </section>
      </main>
      <PlatformFooter />
    </div>
  );
}
