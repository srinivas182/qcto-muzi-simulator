import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { StudioActivityDetail } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const hq = (permissions: string[]) => session({ area: 'platform', tenant: null, permissions, navigation: nav('hq', 'hq.programmes') });
const lesson = (over: Partial<StudioActivityDetail> = {}): StudioActivityDetail => ({
  id: 'a1', kind: 'lesson', code: 'TS-L01', title: 'Working safely inside a PC', summary: null, minutes: 4, weight: 1, required: true, status: 'draft', content_version: 1,
  has_draft: true, blocks: 1, outcomes: ['TS-2.2'], review: {}, published_at: null,
  draft: { blocks: [{ id: 'b1', type: 'check', prompt: 'What do you do first?', options: ['Open the case', 'Switch off and unplug'], answer: 1, explain: 'Power can hurt you.', outcome: 'TS-2.2' }], i18n: {}, review: {} },
  problems: [], outcome_details: [{ code: 'TS-2.2', statement: 'Diagnose faults in memory.' }], scenarios: [{ code: 'TS-S01', title: 'A PC won’t boot' }], assets: {}, ai_ready: true,
  ...over,
});
const sent = (calls: ReturnType<typeof mockApi>, end: string, method: string) => {
  const c = calls.mock.calls.find(([u, i]) => String(u).endsWith(end) && (i as RequestInit | undefined)?.method === method);
  return c ? JSON.parse(String((c[1] as RequestInit).body ?? 'null')) : undefined;
};

describe('Course Studio', () => {
  it('edits blocks with a live phone preview and saves the draft', async () => {
    const calls = mockApi({ '/platform/activities/a1': { data: lesson() } }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support/lessons/a1');
    const preview = await screen.findByText('Learner’s phone');
    const phone = preview.nextElementSibling as HTMLElement;
    expect(within(phone).getByText('What do you do first?')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'What must you do first?' } });
    expect(within(phone).getByText('What must you do first?')).toBeInTheDocument();
    fireEvent.click(within(phone).getByRole('button', { name: /Switch off and unplug/ }));
    expect(within(phone).getByRole('status')).toHaveTextContent('Right! Power can hurt you.');

    fireEvent.click(screen.getByRole('button', { name: 'Add a block' }));
    fireEvent.click(screen.getByRole('button', { name: 'Key points' }));
    fireEvent.change(screen.getByLabelText('Point 1'), { target: { value: 'Wear an antistatic strap' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(sent(calls, '/platform/activities/a1', 'PATCH')).toBeDefined());
    const body = sent(calls, '/platform/activities/a1', 'PATCH');
    expect(body.blocks).toHaveLength(2);
    expect(body.blocks[0]).toMatchObject({ prompt: 'What must you do first?', answer: 1 });
    expect(body.blocks[1]).toMatchObject({ type: 'keypoints', items: ['Wear an antistatic strap'] });
  });

  it('translates beside the English text and only subject experts can mark a language reviewed', async () => {
    const calls = mockApi({ '/platform/activities/a1': { data: lesson() } }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support/lessons/a1');
    fireEvent.click(await screen.findByRole('tab', { name: /isiZulu/ }));
    expect(screen.getByText(/Until then they get English/)).toBeInTheDocument();
    expect(screen.getAllByText('What do you do first?')).toHaveLength(2); // English source above the box, and the preview
    fireEvent.change(screen.getByLabelText('prompt'), { target: { value: 'Wenzani kuqala?' } });
    expect(screen.queryByText('Mark this language as reviewed')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(sent(calls, '/platform/activities/a1', 'PATCH')?.i18n).toEqual({ zu: { b1: { prompt: 'Wenzani kuqala?' } } }));
  });

  it('asks before an AI draft replaces blocks, and lists what must be fixed before publishing', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const calls = mockApi({ '/platform/activities/a1': { data: lesson({ problems: ['Block 1: write the text.'], draft: { blocks: [{ id: 'b1', type: 'text', body: '' }], i18n: {}, review: {} } }) } },
      hq(['programmes.view', 'content.author', 'content.review', 'content.publish']));
    renderAt('/hq/programmes/technical-support/lessons/a1');
    expect(await screen.findByRole('alert')).toHaveTextContent('Block 1: write the text.');
    fireEvent.click(screen.getByRole('button', { name: 'Draft with AI' }));
    expect(confirm).toHaveBeenCalled();
    expect(sent(calls, '/ai-draft', 'POST')).toBeUndefined();
    expect(screen.getByText('Mark this language as reviewed')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Publish' })).toBeInTheDocument();
  });

  it('hides AI buttons when AI drafting is not set up', async () => {
    mockApi({ '/platform/activities/a1': { data: lesson({ ai_ready: false }) } }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support/lessons/a1');
    expect(await screen.findByRole('heading', { level: 1, name: 'Working safely inside a PC' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Draft with AI' })).not.toBeInTheDocument();
  });
});
