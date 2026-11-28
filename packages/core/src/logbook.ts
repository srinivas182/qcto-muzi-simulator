/** Sprint 5.3: the workplace logbook. */
export interface WorkplaceInfo {
  id: string; employer: string; site: string | null; mentor_name: string; confirm_by: 'link' | 'account'; starts_on: string; ends_on: string | null;
  /** staff only */ mentor_phone?: string; mentor_user_id?: string | null;
}
export type LogbookStatus = 'submitted' | 'confirmed' | 'queried';
export interface LogbookEntryOut {
  id: string; client_id: string; day: string; hours: number; outcomes: string[]; note: string | null; photo: boolean; status: LogbookStatus;
  mentor_note: string | null; confirmed_at: string | null; confirmed_name: string | null;
}
export interface LogbookSummary { days: number; confirmed_days: number; hours: number; confirmed_hours: number; min_days: number | null; outcomes: Record<string, number> }
export interface LogbookView {
  workplace: WorkplaceInfo | null; outcomes: { code: string; statement: string }[]; summary: LogbookSummary; entries: LogbookEntryOut[]; late_days: number;
}
export interface CheckIn { client_id: string; day: string; hours: number; outcomes: string[]; note: string | null }
