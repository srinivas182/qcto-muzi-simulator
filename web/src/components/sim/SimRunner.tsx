import { lazy, Suspense, useEffect, useReducer, useRef, useState } from 'react';
import { Box, Clock, Hand, Lightbulb, Rows3, Square, UserRound, Volume2, X } from 'lucide-react';
import { simReducer, startRun, touchable, type ScenarioContent, type SimMode, type SimState } from '@simuwork/core';
import { Button, cx } from '../ui';
import { useI18n } from '../../lib/i18n';
import { canWebGL } from '../../lib/webgl';

const Scene3D = lazy(() => import('./Scene3D'));

const now = () => Date.now();
const secondsSince = (t0: number) => Math.max(0, Math.round((now() - t0) / 1000));
const clock = (s: number) => `${Math.floor(Math.abs(s) / 60)}:${String(Math.abs(s) % 60).padStart(2, '0')}`;

const VIEW_KEY = 'sw.sim.view';
const savedView = (): '3d' | '2d' => {
  try { return localStorage.getItem(VIEW_KEY) === '2d' ? '2d' : '3d'; } catch { return '3d'; }
};

/** Read the instruction aloud (audio first, for learners who prefer to listen). */
const VOICES: Record<string, string> = { en: 'en-ZA', zu: 'zu-ZA', xh: 'xh-ZA', af: 'af-ZA' };
function speak(text: string, locale: string) {
  if (typeof speechSynthesis === 'undefined') return;
  speechSynthesis.cancel();
  const u = new SpeechSynthesisUtterance(text);
  u.lang = VOICES[locale] ?? 'en-ZA';
  speechSynthesis.speak(u);
}

/**
 * One run of a scenario. Every move goes through the shared reducer and is reported to `onMove`, so the
 * attempt is saved on the device as the learner goes; `onDone` fires once the last step is done.
 */
export function SimRunner({ content, mode, customer, onMove, onDone, onQuit }: {
  content: ScenarioContent; mode: SimMode; customer: string | null;
  onMove: (s: SimState, seconds: number) => void; onDone: (s: SimState, seconds: number) => void; onQuit: () => void;
}) {
  const { t, tx, locale } = useI18n();
  const started = useRef(now());
  const [s, dispatch] = useReducer((st: SimState, a: Parameters<typeof simReducer>[2]) => simReducer(content, st, a), content, (c) => startRun(c, mode));
  const [view, setView] = useState<'3d' | '2d'>(() => (canWebGL() ? savedView() : '2d'));
  const [left, setLeft] = useState(() => (content.job?.minutes ?? 0) * 60);
  const [effect, setEffect] = useState<{ kind: NonNullable<SimState['feedback']>['effect']; key: number } | null>(null);
  const step = content.steps[s.step];
  const reported = useRef(0);

  useEffect(() => {
    if (s.events.length === reported.current) return;
    reported.current = s.events.length;
    const seconds = secondsSince(started.current);
    if (s.done) onDone(s, seconds); else onMove(s, seconds);
  }, [s, onMove, onDone]);

  useEffect(() => {
    if (mode !== 'job') return;
    const id = setInterval(() => setLeft((content.job?.minutes ?? 0) * 60 - secondsSince(started.current)), 1000);
    return () => clearInterval(id);
  }, [mode, content.job?.minutes]);

  const act = (object: string) => {
    const next = simReducer(content, s, { type: 'tap', object, t: secondsSince(started.current) });
    if (next.feedback?.kind === 'wrong') setEffect({ kind: next.feedback.effect, key: now() });
    dispatch({ type: 'tap', object, t: secondsSince(started.current) });
  };
  const choose = (index: number) => dispatch({ type: 'choose', index, t: secondsSince(started.current) });
  const switchView = (v: '3d' | '2d') => {
    setView(v);
    try { localStorage.setItem(VIEW_KEY, v); } catch { /* private mode: keep it for this visit only */ }
  };
  const name = (id: string) => tx(`sim.obj.${id}`, id);
  const state = (id: string) => tx(`sim.state.${s.scene[id] ?? 'idle'}`, s.scene[id] ?? '');
  const hintsShown = step ? step.hints.slice(0, s.hints[step.id] ?? 0) : [];
  if (!step) return null;

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2 text-[13px] text-muted">
        <span className="font-semibold text-ink">{t('sim.step', { n: s.step + 1, total: content.steps.length })}</span>
        <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-line"><span className="block h-full bg-brand transition-[width]" style={{ width: `${(100 * s.step) / content.steps.length}%` }} /></span>
        {mode === 'job' && <span className={cx('flex items-center gap-1 font-semibold tabular-nums', left < 0 ? 'text-[#C92A2F]' : 'text-ink')}><Clock className="size-4" />{left < 0 ? t('sim.overTime') : t('sim.timeLeft', { time: clock(left) })}</span>}
        <Button size="sm" variant="ghost" icon={<X className="size-4" />} onClick={onQuit}>{t('sim.quit')}</Button>
      </div>

      {mode === 'job' && customer && <p className="flex items-start gap-2 rounded-xl bg-[#FFF4E5] px-3 py-2 text-[14px] text-[#7A4B00]"><UserRound className="mt-0.5 size-4 shrink-0" /><span><b>{t('sim.customer')}: </b>{customer}</span></p>}

      <div className="rounded-2xl bg-nav p-4 text-white">
        <div className="flex items-start gap-3">
          <p className="flex-1 text-[17px] font-semibold leading-snug" aria-live="polite">{step.prompt}</p>
          <button type="button" aria-label={t('sim.readAloud')} title={t('sim.readAloud')} onClick={() => speak(step.prompt, locale)} className="grid size-11 shrink-0 place-items-center rounded-xl bg-white/10 hover:bg-white/20"><Volume2 className="size-5" /></button>
        </div>
        {hintsShown.map((h, i) => <p key={i} className="mt-2 flex gap-2 text-[14px] text-white/85"><Lightbulb className="mt-0.5 size-4 shrink-0 text-[#FCD34D]" />{h}</p>)}
        {mode === 'practice' && step.hints.length > (s.hints[step.id] ?? 0) && (
          <button type="button" onClick={() => dispatch({ type: 'hint', t: secondsSince(started.current) })} className="mt-3 inline-flex h-9 items-center gap-1.5 rounded-lg bg-white/10 px-3 text-[13px] font-semibold hover:bg-white/20"><Lightbulb className="size-4" />{t('sim.hint')}</button>
        )}
      </div>

      {s.feedback?.kind === 'wrong' && (
        <div role="alert" className="flex items-start gap-3 rounded-xl border border-[#F5C2C3] bg-[#FDECEC] p-3 text-[14px] text-[#7F1D1D]">
          <p className="flex-1"><b>{t('sim.wrong')} </b>{s.feedback.message}</p>
          <Button size="sm" variant="secondary" onClick={() => dispatch({ type: 'dismiss' })}>{t('sim.ok')}</Button>
        </div>
      )}
      {s.holding && <p className="flex items-center gap-2 rounded-xl bg-brand-soft px-3 py-2 text-[14px] text-ink"><Hand className="size-4 text-brand" />{t('sim.holding', { name: name(s.holding).toLowerCase() })}</p>}

      {step.type === 'choose' ? (
        <div className="grid gap-2">
          {step.options.map((o, i) => (
            <button key={i} type="button" onClick={() => choose(i)} className="min-h-[52px] rounded-xl border-[1.5px] border-line bg-surface px-4 text-left text-[15px] font-medium text-ink hover:border-brand">{o}</button>
          ))}
        </div>
      ) : (
        <>
          {view === '3d' && (
            <div className="relative h-[46vh] min-h-[280px] overflow-hidden rounded-2xl border border-line">
              <Suspense fallback={<div className="grid h-full place-items-center text-[14px] text-muted">{t('sim.loading3d')}</div>}>
                <Scene3D scene={s.scene} holding={s.holding} effect={effect} onTap={act} label={step.prompt} />
              </Suspense>
            </div>
          )}
          {/* Every object as a big button: the 2D view, and in 3D an alternative to precise tapping. */}
          <div className={cx('grid gap-2', view === '3d' ? 'grid-cols-3 sm:grid-cols-4' : 'grid-cols-2 sm:grid-cols-3')}>
            {touchable(content).map((id) => (
              <button key={id} type="button" onClick={() => act(id)} aria-pressed={s.holding === id}
                className={cx('flex min-h-[52px] flex-col items-start justify-center rounded-xl border-[1.5px] px-3 py-2 text-left', s.holding === id ? 'border-brand bg-brand-soft' : 'border-line bg-surface hover:border-brand')}>
                <span className={cx('font-semibold text-ink', view === '3d' ? 'text-[12px]' : 'text-[14px]')}>{name(id)}</span>
                {view === '2d' && <span className="text-[12px] text-muted">{state(id)}</span>}
              </button>
            ))}
          </div>
        </>
      )}

      <div className="flex items-center justify-between text-[12px] text-muted">
        <span>{t('sim.how')}</span>
        {canWebGL() ? (
          <span className="flex gap-1">
            <button type="button" aria-pressed={view === '3d'} onClick={() => switchView('3d')} className={cx('flex h-8 items-center gap-1 rounded-lg px-2', view === '3d' && 'bg-surface-2 text-ink')}><Box className="size-3.5" />{t('sim.view3d')}</button>
            <button type="button" aria-pressed={view === '2d'} onClick={() => switchView('2d')} className={cx('flex h-8 items-center gap-1 rounded-lg px-2', view === '2d' && 'bg-surface-2 text-ink')}><Rows3 className="size-3.5" />{t('sim.view2d')}</button>
          </span>
        ) : <span className="flex items-center gap-1"><Square className="size-3.5" />{t('sim.no3d')}</span>}
      </div>
    </div>
  );
}
