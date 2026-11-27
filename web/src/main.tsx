import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, RouterProvider } from 'react-router';
import './index.css';
import { createQueryClient, Providers } from './app/Providers';
import { routes } from './app/routes';
import { applyTheme, storedTheme } from './lib/theme';

applyTheme(storedTheme());
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => storedTheme() === 'system' && applyTheme('system'));

const router = createBrowserRouter(routes);

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Providers client={createQueryClient()}>
      <RouterProvider router={router} />
    </Providers>
  </StrictMode>,
);
