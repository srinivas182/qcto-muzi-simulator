import { cx } from '../../components/ui';

/** A small radial gauge for a readiness percentage (0–100). */
export function ReadinessRing({ value, size = 56, label, className }: { value: number; size?: number; label: string; className?: string }) {
  const stroke = Math.max(4, Math.round(size / 11));
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const pct = Math.max(0, Math.min(100, value));
  const colour = pct >= 80 ? '#0B7A5C' : pct >= 40 ? 'var(--brand-primary)' : pct > 0 ? '#C98A00' : 'var(--sw-line)';

  return (
    <div role="meter" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-label={label} className={cx('relative shrink-0', className)} style={{ width: size, height: size }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--sw-line)" strokeWidth={stroke} opacity={0.6} />
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={colour} strokeWidth={stroke} strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - pct / 100)} style={{ transition: 'stroke-dashoffset 600ms ease' }} />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="font-bold tabular-nums text-ink" style={{ fontSize: Math.round(size / 4.2) }}>{pct}%</span>
        {size >= 96 && <span className="text-[11px] font-semibold text-muted">{label}</span>}
      </div>
    </div>
  );
}
