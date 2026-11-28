import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { StudioScenarioDetail } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

const hq = (permissions: string[]) => session({ area: 'platform', tenant: null, permissions, navigation: nav('hq', 'hq.programmes') });
const base = { hints: [], why: '', outcome: null, lesson: null, critical: false, only: [] as string[], sets: {}, wrong: [] };
const detail = (): StudioScenarioDetail => ({
  id: 's1', kind: 'scenario', code: 'TS-S01', title: 'A PC won’t boot', summary: null, minutes: 12, weight: 2, required: true, status: 'draft', content_version: 1,
  has_draft: true, blocks: 0, steps: 1, outcomes: ['TS-3.2'], review: {}, published_at: null,
  draft: { review: {}, i18n: {}, scenario: { scene: 'pc-bench', pass_mark: 70, job: { minutes: 12, customer: 'Thandi waits.' }, variants: [],
    steps: [{ ...base, id: 'unplug', type: 'tap', target: 'wall-switch', prompt: 'Make it safe.', critical: true, wrong: [{ on: 'case-panel', effect: 'spark', message: 'Live PC!' }] }] } },
  problems: [], outcome_details: [{ code: 'TS-3.2', statement: 'Replace memory safely.' }], lessons: [{ code: 'TS-L02', title: 'Working safely' }],
  scenes: { 'pc-bench': ['pc-power', 'wall-switch', 'strap', 'case-panel', 'ram-old', 'ram-new', 'slot-a1', 'monitor', 'screwdriver', 'psu-cable'] }, ai_ready: true,
});
const sent = (calls: ReturnType<typeof mockApi>, method: string) => {
  const c = calls.mock.calls.findLast(([u, i]) => String(u).endsWith('/platform/activities/s1') && (i as RequestInit | undefined)?.method === method);
  return c ? JSON.parse(String((c[1] as RequestInit).body)) : undefined;
};

describe('Scenario Studio', () => {
  it('builds steps with forms, previews them live, adds a second cause and saves', async () => {
    const calls = mockApi({ '/platform/activities/s1': { data: detail() } }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support/scenarios/s1');
    const preview = (await screen.findByText('Live preview')).closest('div')!.parentElement as HTMLElement;
    expect(within(preview).getByText('Make it safe.')).toBeInTheDocument();
    fireEvent.click(within(preview).getByRole('button', { name: /Side panel of the case/ }));
    expect(within(preview).getByRole('alert')).toHaveTextContent('Live PC!');

    fireEvent.change(screen.getByLabelText('Instruction'), { target: { value: 'Switch it off at the wall.' } });
    expect(within(preview).getByText('Switch it off at the wall.')).toBeInTheDocument(); // the preview follows the editor

    fireEvent.click(screen.getByRole('button', { name: 'Decide' }));
    const prompts = screen.getAllByLabelText('Instruction');
    fireEvent.change(prompts[1]!, { target: { value: 'What is the cause?' } });

    fireEvent.click(screen.getByRole('button', { name: 'Add a cause' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add a cause' }));
    const labels = screen.getAllByLabelText('What this cause is (only HQ sees this)');
    fireEvent.change(labels[0]!, { target: { value: 'Faulty RAM' } });
    fireEvent.change(labels[1]!, { target: { value: 'Loose cable' } });
    // The second step belongs only to the first cause.
    const onlyBoxes = screen.getAllByRole('checkbox', { name: 'Loose cable' });
    fireEvent.click(onlyBoxes[1]!);

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(sent(calls, 'PATCH')).toBeDefined());
    const body = sent(calls, 'PATCH').scenario;
    expect(body.steps).toHaveLength(2);
    expect(body.steps[0].prompt).toBe('Switch it off at the wall.');
    expect(body.steps[1]).toMatchObject({ type: 'choose', prompt: 'What is the cause?', only: [body.variants[0].id] });
    expect(body.variants.map((v: { label: string }) => v.label)).toEqual(['Faulty RAM', 'Loose cable']);
  });

  it('translates side by side and previews the translation', async () => {
    mockApi({ '/platform/activities/s1': { data: detail() } }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support/scenarios/s1');
    fireEvent.click(await screen.findByRole('tab', { name: /isiZulu/ }));
    fireEvent.change(screen.getByLabelText('Instruction'), { target: { value: 'Yenza kuphephe.' } });
    expect(screen.getAllByText('Yenza kuphephe.').length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled();
  });

  it('the Scenario Studio menu opens a list of scenarios by programme', async () => {
    const d = detail();
    mockApi({ '/platform/activities': { data: [{ code: 'technical-support', title: 'Technical Support', activities: [d] }, { code: 'call-centre', title: 'Call Centre Agent', activities: [] }] } }, session({ area: 'platform', tenant: null, permissions: ['programmes.view', 'content.author'], navigation: nav('hq', 'hq.studio.scenarios') }));
    renderAt('/hq/studio/scenarios');
    expect(await screen.findByRole('link', { name: /A PC won’t boot/ })).toHaveAttribute('href', '/hq/programmes/technical-support/scenarios/s1');
    expect(screen.getByText('Nothing here yet.')).toBeInTheDocument();
  });
});
