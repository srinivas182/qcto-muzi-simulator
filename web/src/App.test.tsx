import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './App';

function renderApp() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>,
  );
}

afterEach(() => vi.unstubAllGlobals());

describe('App', () => {
  it('shows the API status from the health endpoint', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ status: 'ok', app: 'SimuWork', version: '0.1.0', checks: {} }), { status: 200 }),
      ),
    );
    renderApp();
    expect(await screen.findByText('ok')).toBeInTheDocument();
  });

  it('always renders the locked platform footer', () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    renderApp();
    const footer = screen.getByRole('contentinfo');
    expect(footer).toHaveTextContent('Developed and Maintained by Mayura Consultancy Services');
    const link = screen.getByRole('link', { name: 'Mayura Consultancy Services' });
    expect(link).toHaveAttribute('href', 'https://www.mayuraconsultancy.com');
  });
});
