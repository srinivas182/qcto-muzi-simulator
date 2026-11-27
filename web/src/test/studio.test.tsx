import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ProgrammeVersionContent } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const content = (over: Partial<ProgrammeVersionContent> = {}): ProgrammeVersionContent => ({
  programme: { code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'Services SETA' },
  version: 1, status: 'published', summary: 'First-line and second-line support.', purpose: 'To turn classroom knowledge into practice.',
  occupations: ['IT support technician'], workplace_requirements: ['A ticketing system'], tools: ['Remote support software'], to_confirm: ['Logbook format'],
  source: { document: 'Workplace Practical Expectations', page: 7 }, change_notes: null, published_at: '2026-11-27T10:00:00Z',
  qualifications: [{ kind: 'occupational', saqa_id: '101408', title: 'Occupational Certificate: Computer Technician', nqf_level: 5, credits: 282, source_text: '' }],
  duty_areas: [{ number: 1, title: 'Service desk and ticket management', outcomes: [{ code: 'TS-1.1', statement: 'Log each request as a ticket with accurate details.', plain_words: null }], evidence: [{ id: 'e1', description: 'Ticket extract', type: 'record', min_count: 1 }] }],
  review: { submitted_at: null, submitted_by: null, reviewed_at: null, reviewed_by: null, comment: null, published_by: null },
  ...over,
});

const hq = (permissions: string[]) => session({ area: 'platform', tenant: null, permissions, navigation: nav('hq', 'hq.programmes') });

describe('Programme Studio', () => {
  it('lists programmes with their draft status', async () => {
    mockApi({ '/platform/programmes': { data: [{ code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'x', summary: 'Support', version: 1, published_at: '2026-11-27T10:00:00Z', counts: { duty_areas: 6, outcomes: 24, evidence: 15 }, draft: { version: 2, status: 'in_review' } }] } }, hq(['programmes.view']));
    renderAt('/hq/programmes');
    expect(await screen.findByText('Technical Support')).toBeInTheDocument();
    expect(screen.getByText('6 duty areas · 24 outcomes · 15 evidence items')).toBeInTheDocument();
    expect(screen.getByText('In review v2')).toBeInTheDocument();
  });

  it('shows published content read-only and lets an author start a draft', async () => {
    const calls = mockApi({
      '/platform/programmes/technical-support': (init?: RequestInit) => ({ data: { programme: { code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'x', status: 'active' }, versions: [{ version: 1, status: 'published', change_notes: null, published_at: '2026-11-27T10:00:00Z', created_at: null }], current: content() }, method: init?.method }),
      '/platform/programmes/technical-support/draft': { data: content({ version: 2, status: 'draft' }) },
    }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support');

    expect(await screen.findByText('Log each request as a ticket with accurate details.')).toBeInTheDocument();
    expect(screen.getByText(/Published versions never change/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /TS-1.1/ })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Start a new draft' }));
    await waitFor(() => expect(calls.mock.calls.some(([u, i]) => String(u).endsWith('/draft') && (i as RequestInit).method === 'POST')).toBe(true));
  });

  it('edits an outcome in a draft', async () => {
    const calls = mockApi({
      '/platform/programmes/technical-support': { data: { programme: { code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'x', status: 'active' }, versions: [], current: content({ version: 2, status: 'draft' }) } },
      '/platform/programmes/technical-support/draft/outcomes/TS-1.1': { data: content({ version: 2, status: 'draft' }) },
    }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support');

    fireEvent.click(await screen.findByRole('button', { name: /^TS-1.1:/ }));
    const box = screen.getByRole('textbox', { name: 'TS-1.1' });
    fireEvent.change(box, { target: { value: 'Log every request as a ticket with accurate details.' } });
    fireEvent.blur(box);

    await waitFor(() => {
      const call = calls.mock.calls.find(([u]) => String(u).endsWith('/draft/outcomes/TS-1.1'));
      expect(JSON.parse(String((call?.[1] as RequestInit | undefined)?.body))).toEqual({ statement: 'Log every request as a ticket with accurate details.' });
    });
  });

  it('gives reviewers approve and send back, and publishers publish once approved', async () => {
    const studio = (reviewed: boolean) => ({ data: { programme: { code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'x', status: 'active' }, versions: [], current: content({ version: 2, status: 'in_review', review: { submitted_at: '2026-11-27T10:00:00Z', submitted_by: 'Lwazi', reviewed_at: reviewed ? '2026-11-27T11:00:00Z' : null, reviewed_by: reviewed ? 'Priya' : null, comment: null, published_by: null } }) } });

    mockApi({ '/platform/programmes/technical-support': studio(false) }, hq(['programmes.view', 'content.review']));
    const first = renderAt('/hq/programmes/technical-support');
    expect(await screen.findByRole('button', { name: 'Approve' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Send back' })).toBeInTheDocument();
    expect(screen.getByText(/In review. Sent by Lwazi/)).toBeInTheDocument();
    first.unmount();

    mockApi({ '/platform/programmes/technical-support': studio(true) }, hq(['programmes.view', 'content.publish']));
    renderAt('/hq/programmes/technical-support');
    expect(await screen.findByRole('button', { name: 'Publish version 2' })).toBeInTheDocument();
    expect(screen.getByText('Approved by Priya. Ready to publish.')).toBeInTheDocument();
  });
});
