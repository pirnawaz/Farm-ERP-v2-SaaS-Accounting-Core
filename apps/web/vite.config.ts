import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

/** Vite dev + preview forward /api to Laravel. Default matches `php artisan serve`. */
function apiProxyConfig(target: string) {
  return {
    '/api': {
      target,
      changeOrigin: true,
    },
  } as const
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, __dirname, '')
  const apiProxyTarget = env.VITE_DEV_API_PROXY_TARGET || 'http://localhost:8000'

  return {
    plugins: [react()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
        '@farm-erp/shared': path.resolve(__dirname, '../../packages/shared/src'),
      },
    },
    server: {
      port: 3000,
      host: '0.0.0.0',
      proxy: apiProxyConfig(apiProxyTarget),
    },
    // `vite preview` does not inherit `server.proxy`; without this, /api/* returns 404 from the static server.
    preview: {
      port: 3000,
      host: '0.0.0.0',
      proxy: apiProxyConfig(apiProxyTarget),
    },
  }
})
