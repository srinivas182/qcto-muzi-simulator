import { useState } from 'react';
import { Link } from 'react-router';
import { ArrowUpRight, Palette, ScrollText, ShieldCheck } from 'lucide-react';
import { useSignedIn } from '../../app/access';
import { Card, cx } from '../../components/ui';
import { navIcon } from '../../lib/icons';
import { sectionKey, useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';
import { greetingKey } from '../../lib/time';

/** HQ and provider home: greeting, set-up checklist and every area this person can open. */
export default function Dashboard() {
  const session = useSignedIn();
  const { t, tx } = useI18n();
  const hq = session.area === 'platform';
  const [hour] = useState(() => new Date().getHours());
  const first = session.user.name.split(' ')[0] ?? session.user.name;
  const academy = session.tenant?.branding.display_name ?? '';

  const setup = [
    !hq && can(session, 'branding.manage') && { to: '/admin/branding', icon: Palette, title: t('home.setupBrand'), body: t('home.setupBrandBody') },
    can(session, hq ? 'roles.manage_platform' : 'roles.manage_tenant') && { to: hq ? '/hq/roles' : '/admin/roles', icon: ShieldCheck, title: t('home.setupRoles'), body: t('home.setupRolesBody') },
    can(session, hq ? 'audit.view_all' : 'audit.view_tenant') && { to: hq ? '/hq/audit' : '/admin/audit', icon: ScrollText, title: t('home.setupAudit'), body: t('home.setupAuditBody') },
  ].filter(Boolean) as { to: string; icon: typeof Palette; title: string; body: string }[];

  const areas = session.navigation.slice(1);

  return (
    <div className="sw-in">
      <div className="relative mb-8 overflow-hidden rounded-3xl bg-nav px-7 py-8 text-white ring-1 ring-white/5 sm:px-10 sm:py-10">
        <div aria-hidden className="pointer-events-none absolute -right-20 -top-24 size-80 rounded-full bg-brand opacity-50 blur-[90px]" />
        <div aria-hidden className="pointer-events-none absolute -bottom-28 right-40 size-64 rounded-full bg-accent opacity-30 blur-[90px]" />
        <p className="relative text-[13px] font-semibold uppercase tracking-[0.14em] text-accent">{hq ? t('auth.hqTitle') : academy}</p>
        <h1 className="relative mt-2 text-[30px] font-bold tracking-tight sm:text-[34px]">{t(greetingKey(hour), { name: first })}</h1>
        <p className="relative mt-1.5 max-w-xl text-[15px] text-white/75">{hq ? t('home.hqLead') : t('home.adminLead', { academy })}</p>
      </div>

      {setup.length > 0 && (
        <section className="mb-10">
          <h2 className="mb-3 text-[13px] font-bold uppercase tracking-[0.12em] text-muted">{t('home.setup')}</h2>
          <div className="grid gap-4 md:grid-cols-3">
            {setup.map(({ to, icon: Icon, title, body }) => (
              <Link key={to} to={to} className="group">
                <Card className="h-full p-5 transition group-hover:-translate-y-0.5 group-hover:border-brand/40 group-hover:shadow-lg">
                  <div className="flex items-start justify-between">
                    <span className="flex size-10 items-center justify-center rounded-xl bg-brand-soft text-brand"><Icon className="size-5" /></span>
                    <ArrowUpRight className="size-4 text-muted transition group-hover:text-brand" />
                  </div>
                  <h3 className="mt-4 text-[15px] font-bold text-ink">{title}</h3>
                  <p className="mt-1 text-[14px] text-muted">{body}</p>
                </Card>
              </Link>
            ))}
          </div>
        </section>
      )}

      <section>
        <div className="mb-3 flex items-baseline justify-between">
          <h2 className="text-[13px] font-bold uppercase tracking-[0.12em] text-muted">{t('home.quickLinks')}</h2>
          <p className="hidden text-[13px] text-muted sm:block">{t('home.statsSoon')}</p>
        </div>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
          {areas.map((item) => {
            const Icon = navIcon(item.icon);
            return (
              <Link key={item.id} to={item.path} className={cx('group flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 transition hover:border-brand/40 hover:bg-brand-soft')}>
                <Icon className="size-5 shrink-0 text-muted transition group-hover:text-brand" aria-hidden />
                <div className="min-w-0">
                  <div className="truncate text-[14px] font-semibold text-ink">{tx('nav.' + item.id, item.label)}</div>
                  <div className="truncate text-[12px] text-muted">{tx(sectionKey(item.section), item.section)}</div>
                </div>
              </Link>
            );
          })}
        </div>
      </section>
    </div>
  );
}
