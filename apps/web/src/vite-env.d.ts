/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL?: string
  readonly VITE_FORCE_ALL_MODULES_ENABLED?: string
  readonly VITE_VERIFY_COOKIE_AUTH_IN_DEV?: string
  readonly VITE_DEBUG_NAV?: string
  readonly VITE_DEBUG_MODULES?: string
  readonly VITE_ENABLE_ORCHARDS?: string
  readonly VITE_ENABLE_LIVESTOCK?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
