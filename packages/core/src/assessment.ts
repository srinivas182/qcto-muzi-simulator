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
