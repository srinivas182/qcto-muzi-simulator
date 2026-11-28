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
