/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL?: string
  readonly VITE_FORCE_ALL_MODULES_ENABLED?: string
  readonly VITE_VERIFY_COOKIE_AUTH_IN_DEV?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
