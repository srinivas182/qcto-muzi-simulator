import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Eye, Plus, Search, ShieldCheck, Trash2, Pencil } from 'lucide-react';
import { useSignedIn } from '../../app/access';
import { Badge, Button, Card, Drawer, EmptyState, Field, inputClass, PageHeader, Skeleton, useToast, cx } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { areaPrefix } from '../../lib/area';
import { navIcon } from '../../lib/icons';
import { sectionKey, useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';
import type { PermissionGroup, Role, RolePreview } from '../../lib/types';
import { sections } from '../../components/shell/nav';

const base = () => areaPrefix();

export default function Roles() {
  const session = useSignedIn();
  const { t } = useI18n();
  const manage = can(session, session.area === 'platform' ? 'roles.manage_platform' : 'roles.manage_tenant');
  const roles = useQuery({ queryKey: ['roles', session.area], queryFn: async () => (await api<{ data: Role[] }>(base() + '/roles')).data });
  const [previewing, setPreviewing] = useState<Role | null>(null);
  const [editing, setEditing] = useState<Role | 'new' | null>(null);

  const builtIn = roles.data?.filter((r) => r.is_system) ?? [];
  const custom = roles.data?.filter((r) => !r.is_system) ?? [];

  return (
    <div className="sw-in">
      <PageHeader
        title={t('roles.title')}
        lead={t('roles.lead')}
        actions={manage && <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>{t('roles.new')}</Button>}
      />

      {roles.isPending ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-40 rounded-2xl" />)}</div>
      ) : roles.isError ? (
        <Card><EmptyState icon={<ShieldCheck className="size-6" />} title={t('common.error')} action={<Button variant="secondary" onClick={() => roles.refetch()}>{t('common.retry')}</Button>} /></Card>
      ) : (
        <>
          {custom.length > 0 && <RoleGrid title={t('common.custom')} roles={custom} onOpen={setPreviewing} />}
          <RoleGrid title={t('common.builtIn')} roles={builtIn} onOpen={setPreviewing} />
        </>
      )}

      <PreviewDrawer role={previewing} onClose={() => setPreviewing(null)} onEdit={manage ? (r) => { setPreviewing(null); setEditing(r); } : undefined} />
      {editing && <RoleEditor role={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </div>
  );
}

function RoleGrid({ title, roles, onOpen }: { title: string; roles: Role[]; onOpen: (r: Role) => void }) {
  const { t } = useI18n();
  return (
    <section className="mb-9">
      <h2 className="mb-3 text-[13px] font-bold uppercase tracking-[0.12em] text-muted">{title}</h2>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {roles.map((role) => (
          <button key={role.id} onClick={() => onOpen(role)} className="group text-left">
            <Card className="flex h-full flex-col p-5 transition group-hover:-translate-y-0.5 group-hover:border-brand/40 group-hover:shadow-lg">
              <div className="flex items-start justify-between gap-3">
                <h3 className="text-[16px] font-bold text-ink">{role.name}</h3>
                <Badge tone={role.is_system ? 'neutral' : 'brand'}>{role.is_system ? t('common.builtIn') : t('common.custom')}</Badge>
              </div>
              {role.description && <p className="mt-1.5 line-clamp-2 text-[14px] text-muted">{role.description}</p>}
              <div className="mt-auto flex items-center justify-between pt-5 text-[13px] text-muted">
                <span>{t('roles.permissionsCount', { count: role.permissions.length })} · {t('common.assigned', { count: role.people })}</span>
                <span className="flex items-center gap-1 font-semibold text-brand opacity-0 transition group-hover:opacity-100"><Eye className="size-4" />{t('roles.preview')}</span>
              </div>
            </Card>
          </button>
        ))}
      </div>
    </section>
  );
}

function PreviewDrawer({ role, onClose, onEdit }: { role: Role | null; onClose: () => void; onEdit?: (r: Role) => void }) {
  const { t, tx } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const preview = useQuery({
    queryKey: ['role-preview', role?.id],
    queryFn: async () => (await api<{ data: RolePreview }>(`${base()}/roles/${role!.id}/preview`)).data,
    enabled: !!role,
  });
  const remove = useMutation({
    mutationFn: () => api(`${base()}/roles/${role!.id}`, { method: 'DELETE' }),
    onSuccess: () => { toast(t('roles.deleted')); qc.invalidateQueries({ queryKey: ['roles'] }); onClose(); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });

  return (
    <Drawer
      open={!!role}
      onClose={onClose}
      title={role?.name ?? ''}
      subtitle={role?.is_system ? t('roles.builtInNote') : role?.description}
      width="max-w-2xl"
      footer={role && !role.is_system && onEdit ? (
        <>
          <Button variant="ghost" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate()}>{t('roles.delete')}</Button>
          <Button icon={<Pencil className="size-4" />} onClick={() => onEdit(role)}>{t('roles.edit')}</Button>
        </>
      ) : undefined}
    >
      {preview.isPending ? (
        <div className="space-y-3"><Skeleton className="h-6 w-48" /><Skeleton className="h-40" /></div>
      ) : preview.data ? (
        <div className="grid gap-6 md:grid-cols-[220px_1fr]">
          <div>
            <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.12em] text-muted">{t('roles.sees')}</h3>
            <div className="rounded-2xl bg-nav p-2.5">
              {preview.data.navigation.length === 0 && <p className="p-2 text-[13px] text-white/60">{t('roles.noMenu')}</p>}
              {sections(preview.data.navigation).map(([section, items]) => (
                <div key={section} className="mb-2 last:mb-0">
                  <div className="px-2 pb-1 pt-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-white/45">{tx(sectionKey(section), section)}</div>
                  {items.map((i) => {
                    const Icon = navIcon(i.icon);
                    return (
                      <div key={i.id} className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-[13px] text-white/85">
                        <Icon className="size-4" aria-hidden />{tx('nav.' + i.id, i.label)}
                      </div>
                    );
                  })}
                </div>
              ))}
            </div>
          </div>
          <div>
            <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.12em] text-muted">{t('roles.canDo')}</h3>
            <ul className="space-y-1.5">
              {preview.data.permissions.map((p) => (
                <li key={p} className="flex items-start gap-2.5 rounded-xl border border-line px-3 py-2 text-[14px] text-ink">
                  <ShieldCheck className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />{p}
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}
    </Drawer>
  );
}

function RoleEditor({ role, onClose }: { role: Role | null; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [name, setName] = useState(role?.name ?? '');
  const [description, setDescription] = useState(role?.description ?? '');
  const [selected, setSelected] = useState<Set<string>>(new Set(role?.permissions ?? []));
  const [filter, setFilter] = useState('');
  const catalogue = useQuery({ queryKey: ['permissions', areaPrefix()], queryFn: async () => (await api<{ data: PermissionGroup[] }>(base() + '/permissions')).data });

  const groups = useMemo(() => {
    const q = filter.trim().toLowerCase();
    return (catalogue.data ?? [])
      .map((g) => ({ ...g, permissions: g.permissions.filter((p) => !q || p.label.toLowerCase().includes(q) || g.group.toLowerCase().includes(q)) }))
      .filter((g) => g.permissions.length > 0);
  }, [catalogue.data, filter]);

  const save = useMutation({
    mutationFn: () => api(role ? `${base()}/roles/${role.id}` : base() + '/roles', { method: role ? 'PATCH' : 'POST', body: { name, description: description || null, permissions: [...selected] } }),
    onSuccess: () => { toast(role ? t('roles.updated') : t('roles.created')); qc.invalidateQueries({ queryKey: ['roles'] }); qc.invalidateQueries({ queryKey: ['role-preview'] }); onClose(); },
  });
  const err = save.error instanceof ApiError ? save.error : null;
  const toggle = (key: string) => setSelected((s) => { const n = new Set(s); if (n.has(key)) n.delete(key); else n.add(key); return n; });

  return (
    <Drawer
      open
      onClose={onClose}
      title={role ? t('roles.edit') : t('roles.new')}
      width="max-w-xl"
      footer={<>
        <span className="mr-auto self-center text-[13px] text-muted">{t('roles.selected', { count: selected.size })}</span>
        <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
        <Button loading={save.isPending} disabled={name.trim().length < 3 || selected.size === 0} onClick={() => save.mutate()}>{role ? t('common.save') : t('roles.create')}</Button>
      </>}
    >
      <div className="space-y-4">
        <Field label={t('roles.name')} value={name} onChange={(e) => setName(e.target.value)} error={err?.field('name')} maxLength={80} autoFocus />
        <Field label={t('roles.description')} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} />
        <div>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-[13px] font-semibold text-ink">{t('roles.choose')}</span>
          </div>
          <div className="relative mb-3">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
            <input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder={t('roles.filter')} aria-label={t('roles.filter')} className={cx(inputClass, 'h-10 pl-9 text-[14px]')} />
          </div>
          {(err?.field('permissions') || err?.field('role')) && <p role="alert" className="mb-3 text-[13px] font-medium text-[#C92A2F]">{err.field('permissions') ?? err.field('role')}</p>}
          {catalogue.isPending ? <Skeleton className="h-60" /> : (
            <div className="space-y-4">
              {groups.map((g) => (
                <fieldset key={g.group} className="rounded-2xl border border-line">
                  <legend className="ml-3 px-1.5 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{g.group}</legend>
                  <div className="divide-y divide-line">
                    {g.permissions.map((p) => (
                      <label key={p.key} className="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-surface-2">
                        <input type="checkbox" checked={selected.has(p.key)} onChange={() => toggle(p.key)} className="size-[18px] accent-[var(--brand-primary)]" />
                        <span className="text-[14px] text-ink">{p.label}</span>
                      </label>
                    ))}
                  </div>
                </fieldset>
              ))}
            </div>
          )}
        </div>
      </div>
    </Drawer>
  );
}
