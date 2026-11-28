/**
 * Simulator scenarios: the format, the ready-made scenes, and the runtime as a pure reducer.
 *
 * The same reducer drives the 3D view, the 2D step-by-step view (phones without WebGL) and the mobile app, so a
 * learner gets the same result everywhere. Scoring mirrors the server (api/app/Learning/ScenarioContent.php),
 * which re-scores every attempt from its events: the device never decides a score on its own.
 */

import type { ContentLocale, StudioActivity } from './attempts';

export type SimMode = 'practice' | 'job';
export type SimEffect = 'none' | 'spark' | 'static' | 'beep' | 'customer';

export interface SimWrong { on: string; effect: SimEffect; message: string }

interface StepBase {
  id: string;
  prompt: string;
  hints: string[];
  why: string;
  outcome: string | null;
  lesson: { code: string; block: string | null } | null;
  critical: boolean;
  /** Branching: the causes this step belongs to (empty = every cause). */
  only: string[];
  sets: Record<string, string>;
  wrong: SimWrong[];
}
export type ScenarioStep =
  | (StepBase & { type: 'tap'; target: string })
  | (StepBase & { type: 'choose'; options: string[]; answer: number })
  | (StepBase & { type: 'place'; target: string; slot: string });

export interface ScenarioContent {
  scene: string;
  pass_mark: number;
  job: { minutes: number; customer: string } | null;
  /** Different causes of the same symptom; each run plays one. Learners see only ids, never the labels. */
  variants: { id: string; label?: string; start: Record<string, string> }[];
  steps: ScenarioStep[];
}

export interface LearnScenario {
  id: string;
  code: string;
  title: string;
  summary: string | null;
  minutes: number;
  content_version: number;
  locale: string;
  content: ScenarioContent;
  /** Lesson code → lesson id, so the debrief can send the learner to the lesson that covers a mistake. */
  lessons: Record<string, string>;
  results: Record<SimMode, { best: number; tries: number; passed: boolean } | null>;
}

/** What a learner did, in order. `t` is seconds since the start. */
export interface SimEvent { step: string; action: 'tap' | 'choose' | 'place' | 'hint'; value?: string; t: number }

/** Ready-made scenes: the objects a learner can touch and how each starts. Labels are `sim.obj.<id>` strings. */
export const SCENES: Record<string, Record<string, string>> = {
  'pc-bench': {
    'pc-power': 'idle', 'wall-switch': 'on', strap: 'off', 'case-panel': 'closed', 'ram-old': 'in', 'ram-new': 'bench',
    'slot-a1': 'full', monitor: 'off', screwdriver: 'bench', 'psu-cable': 'in',
  },
};

export interface SimFeedback { kind: 'right' | 'wrong'; message: string; effect: SimEffect }

export interface SimState {
  mode: SimMode;
  /** The cause being played (branching scenarios), or null. */
  variant: string | null;
  /** The steps of this run. */
  steps: ScenarioStep[];
  step: number;
  events: SimEvent[];
  /** How many hints have been shown on each step (practice only). */
  hints: Record<string, number>;
  mistakes: Record<string, number>;
  /** Object id → state, changed by each finished step (`sets`). */
  scene: Record<string, string>;
  /** In a place step, the object picked up and waiting for a slot. */
  holding: string | null;
  feedback: SimFeedback | null;
  critical: boolean;
  done: boolean;
}

export type SimAction =
  | { type: 'tap'; object: string; t: number }
  | { type: 'choose'; index: number; t: number }
  | { type: 'hint'; t: number }
  | { type: 'dismiss' };

/** The steps of one run: all of them, or those of the chosen cause. */
export function stepsFor(content: ScenarioContent, variant: string | null): ScenarioStep[] {
  return content.steps.filter((st) => !st.only?.length || (variant !== null && st.only.includes(variant)));
}

/** Pick the cause for a run at random (`rand` is injectable for tests). */
export function pickVariant(content: ScenarioContent, rand: () => number = Math.random): string | null {
  const v = content.variants ?? [];
  return v.length ? v[Math.floor(rand() * v.length) % v.length]!.id : null;
}

export function startRun(content: ScenarioContent, mode: SimMode, variant: string | null = pickVariant(content)): SimState {
  const steps = stepsFor(content, variant);
  const start = content.variants?.find((v) => v.id === variant)?.start ?? {};
  return { mode, variant, steps, step: 0, events: [], hints: {}, mistakes: {}, scene: { ...(SCENES[content.scene] ?? {}), ...start }, holding: null, feedback: null, critical: false, done: steps.length === 0 };
}

function isRight(step: ScenarioStep, action: SimEvent['action'], value: string): boolean {
  if (step.type === 'tap') return action === 'tap' && value === step.target;
  if (step.type === 'choose') return action === 'choose' && value === String(step.answer);
  return action === 'place' && value === `${step.target}>${step.slot}`;
}

/** The consequence of a wrong move: the step's own message for that object or option, else its catch-all. */
export function wrongFor(step: ScenarioStep, on: string): SimWrong | null {
  return step.wrong.find((w) => w.on === on) ?? step.wrong.find((w) => w.on === '*') ?? null;
}

/** One learner action → the next state. Pure, so it can be replayed, tested and run offline. */
export function simReducer(s: SimState, a: SimAction): SimState {
  if (a.type === 'dismiss') return { ...s, feedback: null };
  const step = s.steps[s.step];
  if (s.done || !step) return s;

  if (a.type === 'hint') {
    if (s.mode === 'job' || (s.hints[step.id] ?? 0) >= step.hints.length) return s;
    return { ...s, hints: { ...s.hints, [step.id]: (s.hints[step.id] ?? 0) + 1 }, events: [...s.events, { step: step.id, action: 'hint', t: a.t }] };
  }

  let action: SimEvent['action'];
  let value: string;
  let on: string;
  if (a.type === 'choose') {
    action = 'choose'; value = String(a.index); on = `option:${a.index}`;
  } else if (step.type === 'place' && a.object === step.target && s.holding === null) {
    return { ...s, holding: a.object, feedback: null }; // picked up; now tap where it goes
  } else if (s.holding !== null) {
    action = 'place'; value = `${s.holding}>${a.object}`; on = a.object;
  } else {
    action = 'tap'; value = a.object; on = a.object;
  }

  const event: SimEvent = { step: step.id, action, value, t: a.t };
  if (isRight(step, action, value)) {
    const next = s.step + 1;
    return {
      ...s, step: next, events: [...s.events, event], scene: { ...s.scene, ...step.sets }, holding: null,
      feedback: { kind: 'right', message: '', effect: 'none' }, done: next >= s.steps.length,
    };
  }
  const w = wrongFor(step, on);
  return {
    ...s, events: [...s.events, event], mistakes: { ...s.mistakes, [step.id]: (s.mistakes[step.id] ?? 0) + 1 }, holding: null,
    critical: s.critical || step.critical, feedback: { kind: 'wrong', message: w?.message ?? '', effect: w?.effect ?? 'none' },
  };
}

export interface SimScore {
  completed: boolean;
  score: number;
  passed: boolean;
  critical: boolean;
  steps: Record<string, { mistakes: number; hints: number; done: boolean }>;
}

/** Same rules as the server: 1 per step, less 0.25 per mistake and 0.15 per hint; safety mistakes fail real-job mode. */
export function scoreRun(content: ScenarioContent, events: SimEvent[], mode: SimMode, variant: string | null = null): SimScore {
  const ids = (content.variants ?? []).map((v) => v.id);
  const list = ids.length === 0 ? stepsFor(content, null) : variant !== null && ids.includes(variant) ? stepsFor(content, variant) : [];
  const steps: SimScore['steps'] = Object.fromEntries(list.map((st) => [st.id, { mistakes: 0, hints: 0, done: false }]));
  let i = 0;
  let critical = false;
  for (const e of events) {
    const st = list[i];
    const p = st && steps[st.id];
    if (!st || !p || e.step !== st.id) continue;
    if (e.action === 'hint') { p.hints++; continue; }
    if (isRight(st, e.action, e.value ?? '')) { p.done = true; i++; } else { p.mistakes++; critical ||= st.critical; }
  }
  const completed = list.length > 0 && i === list.length;
  const points = Object.values(steps).reduce((sum, p) => sum + (p.done ? Math.max(0, 1 - 0.25 * p.mistakes - 0.15 * p.hints) : 0), 0);
  const score = list.length ? Math.round((100 * points) / list.length) : 0;
  return { completed, score, passed: completed && score >= content.pass_mark && !(mode === 'job' && critical), critical, steps };
}

/** The objects a step can involve, for the 2D view: everything in the scene except the monitor (it only shows). */
export function touchable(content: ScenarioContent): string[] {
  return Object.keys(SCENES[content.scene] ?? {}).filter((id) => id !== 'monitor');
}

/** Scenario translations, per language: the customer brief and each step's words (wrong-move messages in order). */
export type ScenarioI18n = Partial<Record<'zu' | 'xh' | 'af', {
  job?: { customer?: string };
  steps?: Record<string, { prompt?: string; why?: string; hints?: string[]; options?: string[]; wrong?: string[] }>;
}>>;

/** HQ Scenario Studio: a scenario's draft with everything the editor needs. */
export interface StudioScenarioDetail extends StudioActivity {
  steps: number;
  draft: { scenario: ScenarioContent; i18n: ScenarioI18n; review: Partial<Record<ContentLocale, { by?: string; at?: string; ai?: boolean }>> };
  problems: string[];
  outcome_details: { code: string; statement: string }[];
  lessons: { code: string; title: string }[];
  /** Ready-made scenes and their objects. */
  scenes: Record<string, string[]>;
  ai_ready: boolean;
}

/** A scenario in one language, for the studio preview (missing translations stay in English). */
export function localiseScenario(content: ScenarioContent, i18n: ScenarioI18n, locale: ContentLocale): ScenarioContent {
  if (locale === 'en') return content;
  const tr = i18n[locale] ?? {};
  return {
    ...content,
    job: content.job ? { ...content.job, customer: tr.job?.customer || content.job.customer } : null,
    steps: content.steps.map((st) => {
      const x = tr.steps?.[st.id] ?? {};
      const same = (a: string[] | undefined, b: string[]) => (a && a.length === b.length ? a : b);
      return {
        ...st, prompt: x.prompt || st.prompt, why: x.why || st.why, hints: same(x.hints, st.hints),
        wrong: st.wrong.map((w, i) => ({ ...w, message: x.wrong?.[i] || w.message })),
        ...(st.type === 'choose' ? { options: same(x.options, st.options) } : {}),
      } as ScenarioStep;
    }),
  };
}
