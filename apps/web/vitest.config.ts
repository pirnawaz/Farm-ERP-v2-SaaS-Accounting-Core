import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    // Playwright E2E specs live under apps/web/playwright; do not collect them as Vitest tests.
    exclude: ['**/node_modules/**', '**/dist/**', '**/playwright/**'],
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@farm-erp/shared': path.resolve(__dirname, '../../packages/shared/src'),
    },
  },
});
