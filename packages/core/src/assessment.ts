import type { ContentLocale } from './attempts';

/** Sprint 5: assessment plans (HQ), question banks and summative tasks. */
export type AssessmentComponent = 'knowledge' | 'practical' | 'workplace';
export type AssessmentKind = 'test' | 'simulator' | 'observation' | 'logbook';

export const KINDS_BY_COMPONENT: Record<AssessmentComponent, AssessmentKind[]> = {
  knowledge: ['test'], practical: ['simulator', 'observation'], workplace: ['logbook'],
};

interface QuestionBase { id: string; prompt: string; outcome: string | null; marks: number }
export type Question =
  | (QuestionBase & { type: 'single'; options: string[]; answer: number; explain: string })
  | (QuestionBase & { type: 'multi'; options: string[]; answer: number[]; explain: string })
  | (QuestionBase & { type: 'short'; model_answer: string });

export type QuestionI18n = Partial<Record<'zu' | 'xh' | 'af', Record<string, { prompt?: string; options?: string[]; explain?: string }>>>;

export interface TaskSettings { pick?: number; minutes?: number; pass_mark?: number; attempts?: number; integrity?: 'light' | 'camera'; items?: string[]; min_days?: number; mode?: 'job' }

export interface AssessmentTaskItem {
  id: string; code: string; title: string; component: AssessmentComponent; kind: AssessmentKind; required: boolean; activity_id: string | null;
  status: 'draft' | 'published' | 'retired'; content_version: number; has_draft: boolean; questions: number; outcomes: string[]; settings: TaskSettings;
  review: Partial<Record<ContentLocale, 'ai' | 'reviewed'>>;
}

export interface AssessmentTaskDetail extends AssessmentTaskItem {
  draft: { questions: Question[]; settings: TaskSettings; i18n: QuestionI18n; review: Partial<Record<ContentLocale, { by?: string; at?: string; ai?: boolean }>> };
  problems: string[];
  outcome_details: { code: string; statement: string }[];
  simulators: { id: string; code: string; title: string }[];
  ai_ready: boolean;
}

export interface AssessmentPlan {
  version: number;
  tasks: AssessmentTaskItem[];
  coverage: { code: string; statement: string; components: AssessmentComponent[] }[];
  uncovered: number;
}

/** A learner's view of their summative tasks (5.1b). */
export interface LearnAssessmentItem {
  id: string; code: string; title: string; component: AssessmentComponent; kind: AssessmentKind; outcomes: string[];
  minutes: number | null; questions: number | null; pass_mark: number | null; attempts_allowed: number | null; attempts_used: number;
  open_sitting: string | null; waiting_for_marking: boolean; camera?: boolean; result: 'competent' | 'not_yet' | null; best_score: number | null;
  /** Practical tasks (Sprint 5.2): the simulator to practise on, and the assessor's latest feedback. */
  simulator_id?: string | null; feedback?: { text: string | null; links: FeedbackLink[]; at: string } | null;
}

/** The paper a learner's device gets: questions and shuffled options, never answers. */
export interface TestPaper {
  id: string; task: { id: string; code: string; title: string; pass_mark: number }; attempt: number; locale: string; status: 'open';
  started_at: string; deadline_at: string; seconds_left: number; answers: Record<string, number | number[] | string>;
  /** Camera check: send up to photos_max small photos during the sitting. */
  camera?: boolean; photos_max?: number;
  questions: { id: string; type: 'single' | 'multi' | 'short'; prompt: string; marks: number; options: string[] }[];
}

export interface TestResult {
  id: string; status: 'submitted' | 'marked'; attempt: number; score: number | null; result: 'competent' | 'not_yet' | null; late: boolean;
  submitted_at: string | null; revise: { code: string; statement: string }[];
}

// Sprint 5.2: the assessor workspace
export type AssessResult = 'competent' | 'not_yet';
export interface FeedbackLink { type: 'step' | 'lesson'; ref: string; label: string; /** learner view: the lesson or simulator to open */ activity_id?: string | null }
export interface QueueBase { id: string; enrolment_id: string; learner: string; cohort: string; task: { id: string; code: string; title: string }; at?: string | null }
export interface QueueMark extends QueueBase { kind: 'mark'; late: boolean; camera: boolean }
export interface QueueRun extends QueueBase { kind: 'run'; round: number; rounds_allowed: number; score: number | null; passed: boolean; critical: boolean }
export interface QueueObserve extends QueueBase { kind: 'observe'; round: number; rounds_allowed: number; ready: boolean }
export interface QueueDone extends QueueBase { kind: 'decision'; result: AssessResult; round: number; assessor: string }
export interface AssessQueue { mark: QueueMark[]; runs: QueueRun[]; observe: QueueObserve[]; done: QueueDone[] }
export interface DecisionOut {
  id: string; round: number; result: AssessResult; feedback: string | null; links: FeedbackLink[];
  observation: { item: string; met: boolean; note: string | null }[] | null; attempt_id: string | null; assessor: string; at: string; hash: string | null;
}
export interface PracticalTask {
  id: string; code: string; title: string; kind: 'simulator' | 'observation'; items: string[]; rounds_allowed: number; rounds_used: number; competent: boolean;
  history: DecisionOut[]; link_options: { steps: { id: string; prompt: string }[]; lessons: { code: string; title: string }[] };
}
export interface RunMove { action: string; value: string | null; t: number; right: boolean | null }
export interface RunReplay {
  id: string; enrolment_id: string; learner: string; simulator: { id: string; code: string; title: string; version_now: number }; version_played: number;
  mode: string; device: string | null; started_at: string; finished_at: string | null; duration_seconds: number; score: number | null; pass_mark: number;
  passed: boolean; critical: boolean; variant: string | null; hash: string;
  steps: { id: string; prompt: string; critical: boolean; outcome: string | null; lesson: string | null; done: boolean; mistakes: number; hints: number; moves: RunMove[] }[];
  task: PracticalTask | null; decision: (DecisionOut & { hash_matches: boolean }) | null;
}
export interface SittingReview {
  id: string; status: string; score: number | null; result: AssessResult | null; late: boolean; learner: string; task: { code: string; title: string };
  camera: boolean; photos: { id: string; taken_at: string }[];
  questions: { id: string; type: 'single' | 'multi' | 'short'; prompt: string; marks: number; outcome: string | null; answer: string | string[] | null; key: string | string[]; awarded: number | null }[];
}
