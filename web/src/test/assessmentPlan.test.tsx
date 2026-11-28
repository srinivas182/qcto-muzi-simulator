import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { AssessmentPlan, AssessmentTaskDetail } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

const hq = (permissions: string[]) => session({ area: 'platform', tenant: null, permissions, navigation: nav('hq', 'hq.programmes') });
const task = (): AssessmentTaskDetail => ({
  id: 't1', code: 'TS-A01', title: 'Knowledge test: PC repair', component: 'knowledge', kind: 'test', required: true, activity_id: null, status: 'draft', content_version: 1,
  has_draft: true, questions: 1, outcomes: ['TS-2.2'], review: {}, settings: { pick: 10, minutes: 30, pass_mark: 70, attempts: 2, integrity: 'light' },
  draft: { questions: [{ id: 'q1abcdef', type: 'single', prompt: 'What do you do first?', options: ['Open the case', 'Switch off'], answer: 1, explain: 'Power first.', outcome: 'TS-2.2', marks: 1 }],
    settings: { pick: 10, minutes: 30, pass_mark: 70, attempts: 2, integrity: 'light' }, i18n: {}, review: {} },
  problems: ['The bank has 1 questions; each test draws 10. Add questions or draw fewer.'], outcome_details: [{ code: 'TS-2.2', statement: 'Diagnose memory faults' }], simulators: [], ai_ready: true,
});
const sent = (calls: ReturnType<typeof mockApi>, method: string) => {
  const c = calls.mock.calls.findLast(([u, i]) => String(u).endsWith('/platform/assessment-tasks/t1') && (i as RequestInit | undefined)?.method === method);
  return c ? JSON.parse(String((c[1] as RequestInit).body)) : undefined;
};

describe('Assessment plan', () => {
  it('edits a question bank, switches on the camera check and saves', async () => {
    const calls = mockApi({ '/platform/assessment-tasks/t1': { data: task() } }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support/assessment/t1');
    expect(await screen.findByRole('heading', { name: 'Knowledge test: PC repair' })).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('each test draws 10');
    fireEvent.change(screen.getByLabelText('Questions per test'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Integrity'), { target: { value: 'camera' } });
    expect(screen.getByText(/seen only by the assessor and moderator/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'More than one right answer' }));
    const prompts = screen.getAllByLabelText('Question');
    fireEvent.change(prompts[1]!, { target: { value: 'Which protect parts from static?' } });
    fireEvent.change(screen.getAllByLabelText('Choice 1')[1]!, { target: { value: 'Wrist strap' } });
    fireEvent.click(screen.getAllByLabelText('Right answer 1')[1]!);
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(sent(calls, 'PATCH')).toBeDefined());
    const body = sent(calls, 'PATCH');
    expect(body.settings).toMatchObject({ pick: 2, integrity: 'camera' });
    expect(body.questions[1]).toMatchObject({ type: 'multi', prompt: 'Which protect parts from static?', answer: [0] });
  });

  it('the Assessment tab shows tasks per component and outcomes nobody covers', async () => {
    const plan: AssessmentPlan = { version: 1, uncovered: 1, tasks: [task()],
      coverage: [{ code: 'TS-2.2', statement: 'Diagnose memory faults', components: ['knowledge'] }, { code: 'TS-3.2', statement: 'Replace memory', components: [] }] };
    const content = { programme: { code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'IT' }, version: 1, status: 'published', summary: '', purpose: '', occupations: [], workplace_requirements: [], tools: [], to_confirm: [], source: null, change_notes: null, published_at: '2026-11-27T10:00:00Z', qualifications: [], duty_areas: [] };
    mockApi({ '/platform/programmes/technical-support': { data: { programme: { code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'IT', status: 'active' }, versions: [{ version: 1, status: 'published', change_notes: null, published_at: null, created_at: null }], current: content } },
      '/platform/programmes/technical-support/assessment': { data: plan } }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support');
    fireEvent.click(await screen.findByRole('tab', { name: 'Assessment' }));
    expect(await screen.findByText('1 outcomes are not covered by any summative task yet.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Knowledge test: PC repair/ })).toHaveAttribute('href', '/hq/programmes/technical-support/assessment/t1');
    const row = screen.getByText('TS-3.2').closest('tr')!;
    expect(within(row).getByTitle('Knowledge')).not.toHaveClass('bg-[#E6F4EC]');
  });
});
