/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { localeParts } from './plugins/localeParts.ts';

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss(), localeParts()],
  server: {
    port: 5173,
    // Keep the Host header so the API can tell which academy is asking (ubuntu.localhost, admin.localhost).
    proxy: { '/api': { target: 'http://127.0.0.1:8000', changeOrigin: false } },
    allowedHosts: ['.localhost'],
  },
  build: {
    target: 'es2022',
    sourcemap: false,
    // Keep the first load small: vendor code in its own long-cached chunk.
    rollupOptions: {
      output: {
        manualChunks(id: string) {
          if (/node_modules\/(react|react-dom|react-router|scheduler)\//.test(id)) return 'react';
          if (id.includes('node_modules/@tanstack/')) return 'query';
          if (id.startsWith('\0virtual:locale/')) return `lang-${id.split('/').slice(1).join('-')}`;
          return undefined;
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
  },
});
