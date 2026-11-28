import { defineConfig } from 'vite';

// The app's offline 3D view: one IIFE script, inlined into an HTML file by scripts/build-embed.mjs.
export default defineConfig({
  build: {
    outDir: 'dist-embed',
    emptyOutDir: true,
    target: 'es2020',
    lib: { entry: 'src/embed/sim3d.ts', name: 'SimuWorkSim3D', formats: ['iife'], fileName: () => 'sim3d.js' },
  },
});
