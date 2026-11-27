import { readdirSync } from 'fs';
import { join } from 'path';
import { renderRouter, screen } from 'expo-router/testing-library';
import { MCS_FOOTER } from '@simuwork/core';
import { mockApi, seed, session } from './helpers';

/**
 * PLATFORM RULE: "Developed and Maintained by Mayura Consultancy Services" (linked) on every
 * screen of the app. This test finds every route file and opens each one.
 */
function routeFiles(dir = join(__dirname, '../app'), base = ''): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    if (e.isDirectory()) return routeFiles(join(dir, e.name), e.name.startsWith('(') ? base : `${base}/${e.name}`);
    if (!e.name.endsWith('.tsx') || e.name.startsWith('_') || e.name === 'index.tsx') return [];
    return [`${base}/${e.name.replace(/\.tsx$/, '')}`];
  });
}

const ROUTES = routeFiles();

describe('locked MCS footer on every screen', () => {
  it('finds the screens', () => {
    expect(ROUTES).toEqual(expect.arrayContaining(['/welcome', '/academy', '/sign-in', '/scan', '/home', '/simulate', '/courses', '/progress', '/me']));
  });

  it.each(ROUTES)('%s shows the footer with the exact credit', async (route) => {
    await seed({ signedIn: true });
    mockApi({ '/session': { data: session() } });
    await renderRouter('./app', { initialUrl: route });
    const footer = await screen.findByTestId('platform-footer');
    expect(footer).toHaveTextContent(`${MCS_FOOTER.prefix} ${MCS_FOOTER.company}`);
    expect(screen.getByRole('link', { name: MCS_FOOTER.company })).toBeOnTheScreen();
  });

  it('uses the same wording as the web footer', () => {
    expect(MCS_FOOTER).toEqual({ prefix: 'Developed and Maintained by', company: 'Mayura Consultancy Services', url: 'https://www.mayuraconsultancy.com' });
  });
});
