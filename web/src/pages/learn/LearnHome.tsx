import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Box, CalendarClock, ClipboardList, MapPin, PlayCircle, QrCode, Sparkles, Users, Wifi } from 'lucide-react';
import type { LearnEnrolment, LearnHomeData } from '@simuwork/core';
import { useSignedIn } from '../../app/access';
import { Button, Card, inputClass, Skeleton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { greetingKey } from '../../lib/time';

/** Learner home: my programmes, days left, my details for QCTO, and joining a class by code. */
export default function LearnHome() {
  const session = useSignedIn();
  const { t } = useI18n();
  const [hour] = useState(() => new Date().getHours());
  const first = session.user.name.split(' ')[0] ?? session.user.name;
  const home = useQuery({ queryKey: ['learn-home'], queryFn: () => api<{ data: LearnHomeData }>('/learn/home') });
  const data = home.data?.data;

  return (
    <div className="sw-in mx-auto max-w-3xl">
      <p className="text-[14px] font-semibold text-muted">{t('home.learnLead')}</p>
      <h1 className="mt-1 text-[30px] font-bold tracking-tight text-ink">{t(greetingKey(hour), { name: first })}</h1>

      {data?.learner && data.learner.qcto_missing > 0 && (
        <Link to="/learn/details" className="mt-5 flex items-center gap-3 rounded-2xl border border-[#F5C26B] bg-[#FFF7E6] px-4 py-3 text-[14px] text-[#7A4A00] transition hover:brightness-[0.98] dark:border-[#9A5B00] dark:bg-[#9A5B00]/20 dark:text-[#F5C26B]">
          <ClipboardList className="size-5 shrink-0" />
          <span className="flex-1"><span className="font-semibold">{t('lh.detailsTitle')}</span> {t('lh.detailsBody', { n: data.learner.qcto_missing })}</span>
          <ArrowRight className="size-4 shrink-0" />
        </Link>
      )}

      <div className="mt-6 space-y-4">
        {!data ? <Skeleton className="h-48 rounded-2xl" /> : data.enrolments.length === 0 ? <Welcome /> : data.enrolments.map((e) => <ProgrammeCard key={e.id} e={e} />)}
      </div>

      <JoinByCode />
    </div>
  );
}

function ProgrammeCard({ e }: { e: LearnEnrolment }) {
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const start = useMutation({
    mutationFn: () => api(`/learn/enrolments/${e.id}/start`, { method: 'POST' }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['learn-home'] }); toast(t('lh.started')); },
    onError: () => toast(t('common.error'), 'error'),
  });
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' });
  const notYet = new Date(e.starts_on) > new Date();

  return (
    <Card className="overflow-hidden p-0">
      <div className="relative overflow-hidden bg-nav px-6 pb-5 pt-6 text-white">
        <div aria-hidden className="absolute -right-8 -top-10 size-40 rounded-full bg-brand opacity-50 blur-[60px]" />
        <p className="relative text-[12px] font-semibold uppercase tracking-wide text-white/70">{e.programme.prefix} · {t('lh.version', { n: e.version })}</p>
        <h2 className="relative mt-1 text-[22px] font-bold">{e.programme.title}</h2>
        <p className="relative mt-1 text-[14px] text-white/80">{e.cohort.name}</p>
      </div>
      <div className="p-6">
        <div className="grid gap-3 text-[14px] text-muted sm:grid-cols-3">
          <span className="flex items-center gap-2"><MapPin className="size-4 shrink-0" />{e.cohort.centre}</span>
          {e.cohort.facilitators.length > 0 && <span className="flex items-center gap-2"><Users className="size-4 shrink-0" />{e.cohort.facilitators.join(', ')}</span>}
          <span className="flex items-center gap-2"><CalendarClock className="size-4 shrink-0" />{notYet ? t('lh.startsOn', { date: date(e.starts_on) }) : e.days_left !== null ? t('lh.daysLeft', { n: e.days_left }) : t('lh.noEnd')}</span>
        </div>
        <div className="mt-5">
          <div className="flex justify-between text-[13px]"><span className="font-semibold text-ink">{t('lh.progress')}</span><span className="text-muted">{e.progress}%</span></div>
          <div className="mt-1.5 h-2.5 overflow-hidden rounded-full bg-surface-2"><div className="h-full rounded-full bg-brand transition-all" style={{ width: `${e.progress}%` }} /></div>
        </div>
        {e.started ? (
          <p className="mt-5 flex items-center gap-2 rounded-xl bg-surface-2 px-4 py-3 text-[14px] text-muted"><Sparkles className="size-4 shrink-0 text-brand" />{t('lh.comingSoon')}</p>
        ) : (
          <>
            <Button className="mt-5 w-full sm:w-auto" size="lg" icon={<PlayCircle className="size-5" />} loading={start.isPending} onClick={() => start.mutate()}>{t('lh.start')}</Button>
            {e.seat_returns_on && <p className="mt-2 text-[13px] text-muted">{t('lh.startBy', { date: date(e.seat_returns_on) })}</p>}
          </>
        )}
      </div>
    </Card>
  );
}

function Welcome() {
  const { t } = useI18n();
  return (
    <Card className="relative overflow-hidden p-0">
      <div className="relative h-40 overflow-hidden bg-nav">
        <div aria-hidden className="absolute -left-10 top-6 size-56 rounded-full bg-brand opacity-60 blur-[70px]" />
        <div aria-hidden className="absolute -right-6 -top-10 size-48 rounded-full bg-accent opacity-40 blur-[70px]" />
        <div aria-hidden className="absolute inset-0 flex items-center justify-center gap-5">
          {[Box, PlayCircle, Sparkles].map((Icon, i) => (
            <span key={i} className="flex size-16 items-center justify-center rounded-2xl border border-white/15 bg-white/10 text-white backdrop-blur-md" style={{ transform: `translateY(${i === 1 ? -10 : 6}px) rotate(${(i - 1) * 6}deg)` }}><Icon className="size-7" /></span>
          ))}
        </div>
      </div>
      <div className="p-6">
        <p className="text-[15px] text-ink">{t('lh.none')}</p>
        <p className="mt-3 flex items-center gap-2 text-[13px] text-muted"><Wifi className="size-4" /> {t('home.learnTip')}</p>
      </div>
    </Card>
  );
}

function JoinByCode() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const [code, setCode] = useState('');
  const submit = (e: FormEvent) => { e.preventDefault(); if (code.trim().length >= 6) void navigate(`/learn/join/${code.trim().toUpperCase()}`); };
  return (
    <form onSubmit={submit} className="mt-6 flex flex-col gap-3 rounded-2xl border border-dashed border-line p-5 sm:flex-row sm:items-center">
      <QrCode className="size-6 shrink-0 text-muted" />
      <div className="flex-1"><p className="text-[14px] font-semibold text-ink">{t('lh.joinTitle')}</p><p className="text-[13px] text-muted">{t('lh.joinBody')}</p></div>
      <label className="sr-only" htmlFor="join-code">{t('lh.joinCode')}</label>
      <input id="join-code" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={12} placeholder="ABCD2345" className={`${inputClass} font-mono tracking-widest sm:w-40`} />
      <Button type="submit" variant="secondary" disabled={code.trim().length < 6}>{t('join.button')}</Button>
    </form>
  );
}
