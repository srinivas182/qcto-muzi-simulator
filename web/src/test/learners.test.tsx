import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { checkSaId, type LearnerItem } from '@simuwork/core';
import { parseCsv, toRows } from '../components/learners/importFile';
import { LEARNERS_XLSX_BASE64 } from './fixtures-learners';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const sipho: LearnerItem = {
  id: 'l1', first_names: 'Sipho', surname: 'Ndlovu', name: 'Sipho Ndlovu', phone: '+27825550101', email: null, id_type: 'sa_id', id_masked: '•••••••••9087',
  date_of_birth: '1980-01-01', gender: 'male', citizenship: 'sa', home_language: 'zu', qcto_learner_number: null, status: 'invited', joined_at: null, created_at: '2027-01-01T00:00:00Z',
};
const admin = (perms: string[] = ['learners.view', 'learners.manage', 'learners.reveal_id']) => session({ permissions: perms, navigation: nav('admin', 'admin.learners') });
const bodyOf = (calls: ReturnType<typeof mockApi>, end: string) => {
  const c = calls.mock.calls.find(([u, i]) => String(u).endsWith(end) && (i as RequestInit | undefined)?.method !== 'GET');
  return c ? JSON.parse(String((c[1] as RequestInit).body ?? 'null')) : undefined;
};

describe('SA ID numbers', () => {
  it('reads date of birth, gender and citizenship and rejects a bad check digit', () => {
    expect(checkSaId('8001015009087')).toEqual({ date_of_birth: '1980-01-01', gender: 'male', citizenship: 'sa' });
    expect(checkSaId('8001015009088')).toBeNull();
    expect(checkSaId('8013015009087')).toBeNull(); // month 13
    expect(checkSaId('12345')).toBeNull();
  });
});

describe('Import file', () => {
  it('matches loose headers, handles quotes and semicolons, and restores leading zeros from Excel', () => {
    const csv = parseCsv('﻿First name;Last name;ID;Cell\r\n"Thandi ""TJ""";Mokoena;0501015000081;0821234567\r\n');
    const { rows, missing } = toRows(csv);
    expect(missing).toEqual([]);
    expect(rows[0]).toMatchObject({ first_names: 'Thandi "TJ"', surname: 'Mokoena', id_number: '0501015000081', phone: '0821234567' });

    const xlsx = toRows([['First names', 'Surname', 'ID number', 'Mobile'], ['Lebo', 'Dube', 501015000081, 821234567]]);
    expect(xlsx.rows[0]).toMatchObject({ id_number: '0501015000081', phone: '0821234567' });
    expect(toRows([['Name', 'Phone']]).missing).toEqual(['surname', 'id_number']);
  });
});

describe('Learners page', () => {
  it('lists learners with masked IDs and filters by status', async () => {
    mockApi({ '/learners': { data: [sipho, { ...sipho, id: 'l2', name: 'Ayanda Zulu', status: 'active' }], counts: { invited: 1, active: 1 } } }, admin());
    renderAt('/admin/learners');
    expect(await screen.findByText('Sipho Ndlovu')).toBeInTheDocument();
    expect(screen.getAllByText('•••••••••9087')).toHaveLength(2);
    fireEvent.click(screen.getByRole('tab', { name: /Active/ }));
    expect(screen.queryByText('Sipho Ndlovu')).not.toBeInTheDocument();
    expect(screen.getByText('Ayanda Zulu')).toBeInTheDocument();
  });

  it('checks the SA ID as it is typed and sends the new learner', async () => {
    const calls = mockApi({ '/learners': { data: [sipho], counts: { invited: 1 } } }, admin());
    renderAt('/admin/learners');
    fireEvent.click(await screen.findByRole('button', { name: 'Add learner' }));
    fireEvent.change(screen.getByLabelText('First names'), { target: { value: 'Thandi' } });
    fireEvent.change(screen.getByLabelText('Surname'), { target: { value: 'Mokoena' } });
    const id = screen.getByLabelText('SA ID number');
    fireEvent.change(id, { target: { value: '8001015009088' } });
    expect(screen.getByRole('alert')).toHaveTextContent('not valid');
    fireEvent.change(id, { target: { value: '8001 0150 0908 7' } });
    expect(screen.getByText(/Valid · born 1980-01-01/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Mobile number'), { target: { value: '082 555 0199' } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Add learner' }).at(-1)!);
    await waitFor(() => expect(bodyOf(calls, '/learners')).toMatchObject({ first_names: 'Thandi', surname: 'Mokoena', id_type: 'sa_id', id_number: '8001015009087', phone: '082 555 0199', invite: true }));
  });

  it('reveals the full ID only for staff with the right, and offers the invite link by WhatsApp', async () => {
    mockApi({
      '/learners': { data: [sipho], counts: { invited: 1 } },
      '/learners/l1/reveal-id': { data: { id_number: '8001015009087' } },
      '/learners/l1/invite': { data: { url: 'https://ubuntu.simuwork.co.za/join/abc', channel: 'link' } },
    }, admin());
    renderAt('/admin/learners');
    fireEvent.click(await screen.findByRole('button', { name: 'Sipho Ndlovu' }));
    fireEvent.click(screen.getByRole('button', { name: 'Show' }));
    expect(await screen.findByText('8001015009087')).toBeInTheDocument();
    expect(screen.getByText(/recorded in the audit log/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation again' }));
    const wa = await screen.findByRole('link', { name: 'WhatsApp' });
    expect(wa.getAttribute('href')).toContain('https://wa.me/27825550101?text=');
  });

  it('hides add, import and reveal from staff who may only look', async () => {
    mockApi({ '/learners': { data: [sipho], counts: { invited: 1 } } }, admin(['learners.view']));
    renderAt('/admin/learners');
    fireEvent.click(await screen.findByRole('button', { name: 'Sipho Ndlovu' }));
    expect(screen.queryByRole('button', { name: 'Show' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Import list' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Deactivate learner' })).not.toBeInTheDocument();
  });
});

describe('Excel import', () => {
  it('reads the first sheet of a real .xlsx, including numbers Excel stored without leading zeros', async () => {
    const { readXlsx } = await import('../components/learners/xlsx');
    const bytes = Uint8Array.from(atob(LEARNERS_XLSX_BASE64), (c) => c.charCodeAt(0));
    const { rows } = toRows(await readXlsx(new Blob([bytes])));
    expect(rows).toEqual([
      { first_names: 'Lebo', surname: 'Dube', id_number: '0501015000081', phone: '0821234567', email: '', home_language: '' },
      { first_names: 'Thandi', surname: 'Mokoena', id_number: '8001015009087', phone: '082 555 0101', email: 't@example.test', home_language: '' },
    ]);
  });
});
