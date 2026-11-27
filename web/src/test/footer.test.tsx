import { screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MCS_FOOTER, PlatformFooter } from '@simuwork/ui';
import { RootLayout } from '../app/RootLayout';
import { routes } from '../app/routes';
import { mockApi, renderAt, session } from './harness';

/**
 * PLATFORM RULE: "Developed and Maintained by Mayura Consultancy Services", linked to
 * https://www.mayuraconsultancy.com, on every page. No tenant setting may change or hide it.
 */
async function expectFooter() {
  const footer = await screen.findByRole('contentinfo');
  expect(footer).toHaveTextContent('Developed and Maintained by Mayura Consultancy Services');
  const link = within(footer).getByRole('link', { name: 'Mayura Consultancy Services' });
  expect(link).toHaveAttribute('href', 'https://www.mayuraconsultancy.com');
  expect(footer).toBeVisible();
  return footer;
}

afterEach(() => {
  vi.unstubAllGlobals();
  document.documentElement.removeAttribute('style');
});

describe('locked MCS footer', () => {
  it('has the exact wording and link', () => {
    expect(MCS_FOOTER).toEqual({ prefix: 'Developed and Maintained by', company: 'Mayura Consultancy Services', url: 'https://www.mayuraconsultancy.com' });
  });

  it('accepts no props, so tenant data can never reach it', () => {
    expect(PlatformFooter.length).toBe(0);
  });

  it('wraps every route: the router has a single root, the layout that renders the footer', () => {
    expect(routes).toHaveLength(1);
    expect(routes[0]!.element).toMatchObject({ type: RootLayout });
  });

  it('shows on the sign-in page', async () => {
    mockApi({});
    renderAt('/sign-in');
    await screen.findByRole('heading', { name: 'Welcome back' });
    await expectFooter();
  });

  it('shows inside the signed-in app shell', async () => {
    mockApi({ '/roles': { data: [] } }, session());
    renderAt('/admin/roles');
    await screen.findByRole('heading', { name: 'Roles & permissions' });
    await expectFooter();
  });

  it('shows on pages that are not found and on pages without access', async () => {
    mockApi({}, session({ navigation: [] }));
    renderAt('/admin/billing');
    await screen.findByText("You don't have access to this page");
    await expectFooter();
  });

  it('stays in platform colours when a provider changes its brand', async () => {
    mockApi({}, session());
    renderAt('/admin');
    const footer = await expectFooter();
    document.documentElement.style.setProperty('--brand-primary', '#FF0000');
    expect(footer.getAttribute('style')).not.toContain('brand');
    expect(within(footer).getByRole('link').getAttribute('style')).toContain('--sw-footer-link');
  });
});
