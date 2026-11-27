import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ProgrammeCoverage, ProgrammeVersionContent, ProviderLayer } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const content: ProgrammeVersionContent = {
  programme: { code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'Information technology' },
  version: 1, status: 'published', summary: 'First-line and second-line support.', purpose: 'To turn classroom knowledge into practice.',
  occupations: ['IT support technician'], workplace_requirements: ['A ticketing system'], tools: ['Remote support software'], to_confirm: ['Logbook format'],
  source: null, change_notes: null, published_at: '2026-11-27T10:00:00Z', qualifications: [],
  duty_areas: [{
    number: 1, title: 'Service desk and ticket management',
    outcomes: [
      { code: 'TS-1.1', statement: 'Log each request as a ticket with accurate details.', plain_words: null },
      { code: 'TS-1.2', statement: 'Prioritise tickets by impact and urgency.', plain_words: null },
    ],
    evidence: [{ id: 'e1', description: 'Ticket extract', type: 'record', min_count: 1 }],
  }],
};

const layer = (over: Partial<ProviderLayer> = {}): ProviderLayer => ({
  checks: [{ key: 'a'.repeat(40), text: 'Logbook format required by your SDP', status: null, note: null, answered_by: null, answered_at: null }],
  checks_done: 0,
  additions: { enabled: false, outcomes: [], evidence: [] },
  ...over,
});

const provider = (permissions: string[]) => session({ permissions, navigation: nav('admin', 'admin.programmes', 'admin.guide') });

describe('Provider catalogue', () => {
  it('shows the published programme read-only, with the checklist progress', async () => {
    mockApi({ '/programmes/technical-support': { data: content, provider: layer() } }, provider(['catalogue.view']));
    renderAt('/admin/programmes/technical-support');

    expect(await screen.findByText('Log each request as a ticket with accurate details.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Set-up checklist: 0 of 1 done/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add an outcome' })).not.toBeInTheDocument();
    expect(screen.queryByText(/Ask SimuWork HQ/)).not.toBeInTheDocument(); // only shown to people who manage the catalogue
  });

  it('records a "to confirm" answer and asks why when it is not applicable', async () => {
    const calls = mockApi({
      '/programmes/technical-support': { data: content, provider: layer() },
      [`/programmes/technical-support/checks/${'a'.repeat(40)}`]: { provider: layer({ checks_done: 1, checks: [{ key: 'a'.repeat(40), text: 'Logbook format required by your SDP', status: 'not_applicable', note: 'We use the SETA logbook', answered_by: 'Nomsa Dlamini', answered_at: '2026-11-28T09:00:00Z' }] }) },
    }, provider(['catalogue.view', 'catalogue.manage']));
    renderAt('/admin/programmes/technical-support');

    fireEvent.click(await screen.findByRole('tab', { name: 'Set-up checklist' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Not applicable' }));
    const save = screen.getByRole('button', { name: 'Save changes' });
    expect(save).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Why is it not applicable?'), { target: { value: 'We use the SETA logbook' } });
    fireEvent.click(save);

    await waitFor(() => {
      const call = calls.mock.calls.find(([u]) => String(u).includes('/checks/'));
      expect((call?.[1] as RequestInit | undefined)?.method).toBe('PUT');
      expect(JSON.parse(String((call?.[1] as RequestInit | undefined)?.body))).toEqual({ status: 'not_applicable', note: 'We use the SETA logbook' });
    });
    expect(await screen.findByText(/Nomsa Dlamini/)).toBeInTheDocument();
  });

  it('lets a provider add its own outcome only when HQ has switched additions on', async () => {
    const off = mockApi({ '/programmes/technical-support': { data: content, provider: layer() } }, provider(['catalogue.view', 'catalogue.manage']));
    const first = renderAt('/admin/programmes/technical-support');
    expect(await screen.findByText(/Ask SimuWork HQ to switch on provider additions/)).toBeInTheDocument();
    expect(screen.queryByRole('textbox', { name: 'Add an outcome to duty area 1' })).not.toBeInTheDocument();
    first.unmount();
    off.mockReset();

    const on = layer({ additions: { enabled: true, outcomes: [], evidence: [] } });
    const added = layer({ additions: { enabled: true, outcomes: [{ id: 'p1', duty_area: 1, code: 'TS-1.P1', statement: 'Follow the client change-freeze calendar.', plain_words: null }], evidence: [] } });
    const calls = mockApi({
      '/programmes/technical-support': { data: content, provider: on },
      '/programmes/technical-support/additions/outcomes': { provider: added },
    }, provider(['catalogue.view', 'catalogue.manage']));
    renderAt('/admin/programmes/technical-support');

    fireEvent.change(await screen.findByRole('textbox', { name: 'Add an outcome to duty area 1' }), { target: { value: 'Follow the client change-freeze calendar.' } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Add an outcome' })[0]!);
    expect(await screen.findByText('TS-1.P1')).toBeInTheDocument();
    expect(screen.getByText('Your addition')).toBeInTheDocument();
    const call = calls.mock.calls.find(([u]) => String(u).endsWith('/additions/outcomes'));
    expect(JSON.parse(String((call?.[1] as RequestInit | undefined)?.body))).toEqual({ duty_area: 1, statement: 'Follow the client change-freeze calendar.' });
  });

  it('renders a mentor guide page from markdown without injecting HTML', async () => {
    mockApi({ '/reference-pages/the-workplace-mentor-s-guide': { data: { slug: 'the-workplace-mentor-s-guide', title: "The workplace mentor's guide", source: null, markdown: '## What a mentor does\n\n- Give **regular** feedback\n- [ ] Induction done\n\n| Phase | Timing |\n|---|---|\n| Observe | First weeks |\n\n<script>alert(1)</script>' } } }, provider(['catalogue.view']));
    const { container } = renderAt('/admin/guide/the-workplace-mentor-s-guide');

    expect(await screen.findByRole('heading', { name: 'What a mentor does' })).toBeInTheDocument();
    expect(screen.getByText('regular').tagName).toBe('STRONG');
    expect(within(screen.getByRole('table')).getByText('First weeks')).toBeInTheDocument();
    expect(container.querySelector('script')).toBeNull();
    expect(screen.getByText('<script>alert(1)</script>')).toBeInTheDocument();
  });
});

describe('HQ coverage and providers', () => {
  const hq = (permissions: string[]) => session({ area: 'platform', tenant: null, permissions, navigation: nav('hq', 'hq.programmes', 'hq.providers') });
  const coverage: ProgrammeCoverage = { version: 1, summary: { outcomes: 2, ready: 1, planned: 0, readiness: 50 }, outcomes: { 'TS-1.1': { simulator: { status: 'linked', title: 'Help desk triage' } } } };

  it('shows readiness and lets an author set coverage per outcome', async () => {
    const calls = mockApi({
      '/platform/programmes/technical-support': { data: { programme: { code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'x', status: 'active' }, versions: [{ version: 1, status: 'published', change_notes: null, published_at: null, created_at: null }], current: content } },
      '/platform/programmes/technical-support/coverage': { data: coverage },
      '/platform/programmes/technical-support/coverage/TS-1.2': { data: { ...coverage, summary: { outcomes: 2, ready: 1, planned: 1, readiness: 50 } } },
    }, hq(['programmes.view', 'content.author']));
    renderAt('/hq/programmes/technical-support');

    fireEvent.click(await screen.findByRole('tab', { name: 'Coverage' }));
    expect(await screen.findByRole('meter', { name: 'Ready' })).toHaveAttribute('aria-valuenow', '50');
    fireEvent.change(screen.getByRole('combobox', { name: 'Course · TS-1.2' }), { target: { value: 'planned' } });

    await waitFor(() => {
      const call = calls.mock.calls.find(([u]) => String(u).endsWith('/coverage/TS-1.2'));
      expect(JSON.parse(String((call?.[1] as RequestInit | undefined)?.body))).toEqual({ channel: 'course', status: 'planned' });
    });
    fireEvent.click(screen.getByRole('radio', { name: 'Not covered' }));
    expect(screen.queryByText('Log each request as a ticket with accurate details.')).not.toBeInTheDocument();
  });

  it('lets provider managers switch additions on for one provider', async () => {
    const calls = mockApi({
      '/platform/providers': { data: [{ id: 'p-1', name: 'Ubuntu Skills Academy', slug: 'ubuntu', status: 'active', programme_additions: false }] },
      '/platform/providers/p-1/programme-additions': { data: { id: 'p-1', name: 'Ubuntu Skills Academy', slug: 'ubuntu', status: 'active', programme_additions: true } },
    }, hq(['providers.view', 'providers.manage']));
    renderAt('/hq/providers');

    const toggle = await screen.findByRole('switch', { name: 'Let Ubuntu Skills Academy add its own outcomes and evidence' });
    expect(toggle).toHaveAttribute('aria-checked', 'false');
    fireEvent.click(toggle);
    await waitFor(() => expect(toggle).toHaveAttribute('aria-checked', 'true'));
    expect(calls.mock.calls.some(([u, i]) => String(u).endsWith('/programme-additions') && (i as RequestInit).method === 'PATCH')).toBe(true);
  });

  it('shows the switch read-only to people who can only view providers', async () => {
    mockApi({ '/platform/providers': { data: [{ id: 'p-1', name: 'Ubuntu Skills Academy', slug: 'ubuntu', status: 'trial', programme_additions: false }] } }, hq(['providers.view']));
    renderAt('/hq/providers');
    expect(await screen.findByRole('switch')).toBeDisabled();
  });
});
