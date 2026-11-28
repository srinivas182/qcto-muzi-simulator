/** Cohorts and enrolments (Sprint 3.6). */
export type QctoType = 'qualification' | 'skills_programme' | 'none';
export type EnrolmentStatus = 'enrolled' | 'active' | 'completed' | 'withdrawn' | 'no_show';

export interface CohortItem {
  id: string;
  name: string;
  centre: { id: string; name: string };
  programme: { id: string; code: string; title: string };
  version: number;
  starts_on: string;
  ends_on: string | null;
  qcto_type: QctoType;
  qcto_submitted_at: string | null;
  qcto_due_on: string | null;
  qcto_days_left: number | null;
  qcto_qualification_id: string | null;
  readiness_type: string;
  join_open: boolean;
  archived: boolean;
  learners: number;
  counts: Partial<Record<EnrolmentStatus, number>>;
  facilitators: { id: string; name: string }[];
}

export interface EnrolmentItem {
  id: string;
  learner: { id: string; name: string; id_masked: string; status: string };
  status: EnrolmentStatus;
  holds_seat: boolean;
  progress: number;
  seat_taken_at: string;
  started_at: string | null;
  ended_at: string | null;
  reason: string | null;
  seat_returns_on: string | null;
  /** Last time the learner did anything (lesson, simulator). */
  last_activity_at?: string | null;
  /** Started, then quiet for 7 days or more. */
  at_risk?: boolean;
}

export type OutcomeStatus = 'shown' | 'practised' | 'not_yet';
/** Job-ready checklist: QCTO outcomes by duty area. */
export interface ChecklistArea {
  number: number;
  title: string;
  outcomes: { code: string; statement: string; plain_words: string | null; status: OutcomeStatus }[];
}

/** A read-only checklist link the learner shared. */
export interface ChecklistShareItem { id: string; label: string | null; created_at: string; expires_at: string; views: number; live: boolean; url?: string }

/** What a mentor or employer sees through a shared link. */
export interface SharedChecklist { learner: string; academy: string; programme: string; as_of: string; expires_at: string; areas: ChecklistArea[] }

/** Facilitator board: every learner × every published activity. */
export interface CohortBoard {
  activities: { id: string; kind: 'lesson' | 'scenario'; code: string; title: string; required: boolean }[];
  learners: {
    id: string; name: string; status: EnrolmentStatus; progress: number; last_activity_at: string | null; at_risk: boolean;
    job_ready: { shown: number; of: number };
    cells: Record<string, { status: 'started' | 'done'; best: number | null; tries: number; job_passed: boolean } | null>;
  }[];
  summary: { learners: number; average: number; at_risk: number; job_ready: number };
}

export interface CohortDetail extends CohortItem {
  join_code: string;
  free_seats: number;
  enrolments: EnrolmentItem[];
}

export interface CohortOptions {
  centres: { id: string; name: string }[];
  programmes: { id: string; code: string; title: string; free_seats: number }[];
  staff: { id: string; name: string }[];
}

/** Learner home (GET /learn/home). */
export interface LearnEnrolment {
  id: string;
  status: EnrolmentStatus;
  progress: number;
  started: boolean;
  programme: { code: string; title: string; prefix: string };
  version: number;
  cohort: { name: string; centre: string; facilitators: string[] };
  starts_on: string;
  ends_on: string | null;
  days_left: number | null;
  seat_returns_on: string | null;
  /** The "Continue" card: the first required activity not done yet; null when everything is done. */
  next: { id: string; kind: 'lesson' | 'scenario'; title: string; minutes: number; started: boolean } | null;
  /** The class is running this simulator together right now (class mode). */
  class?: ClassSessionItem | null;
}

export interface LearnHomeData {
  learner: { name: string; qcto_missing: number; reminders?: boolean } | null;
  enrolments: LearnEnrolment[];
}

/** Class mode: a simulator the facilitator runs with the whole class. */
export interface ClassSessionItem { id: string; cohort_id: string; activity_id: string; title: string; code: string; mode: 'practice' | 'job'; started_at: string; live: boolean }

export interface ClassBoard {
  session: ClassSessionItem;
  learners: ({ id: string; name: string; joined: false } | {
    id: string; name: string; joined: true; step: number; steps: number; finished: boolean; score: number | null; passed: boolean | null;
    mistakes: number; help: number; last_move_at: string; stuck: boolean;
  })[];
  summary: { learners: number; joined: number; finished: number; stuck: number };
}
