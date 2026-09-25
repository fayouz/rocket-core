import { fileURLToPath } from 'node:url'

/**
 * The Nuxt layer shared by the Rocket applications: layout, dashboard, login and first-run setup, single sign-on,
 * users, LDAP, authentication servers, applications, updates, documentation and changelog pages.
 * An application extends it and adds its own pages, menu entries (app.config.ts, `rocket`) and dashboard sections.
 */
export default defineNuxtConfig({
  $meta: { name: 'rocket-core' },
  compatibilityDate: '2026-09-01',
  modules: ['@nuxt/ui'],
  // Back-office app: auth lives client-side.
  ssr: false,
  // Types and helpers of the layer, also for the applications: import type { Me } from '#rocket/types/api'.
  alias: { '#rocket': fileURLToPath(new URL('./app', import.meta.url)) },
  css: [fileURLToPath(new URL('./app/assets/css/main.css', import.meta.url))],
  // No third-party font CDNs: system fonts only.
  ui: { fonts: false },
  devtools: { enabled: false },
  app: {
    head: {
      htmlAttrs: { lang: 'fr' },
    },
  },
  runtimeConfig: {
    // Server-to-server URL of the API (e.g. http://api:80 inside Docker); falls back to the public one.
    apiInternalBase: '',
    public: {
      apiBase: 'http://localhost:8000',
      // Dashboard shortcuts.
      docsUrl: '',
      changelogUrl: '',
      // Version of the interface: NUXT_PUBLIC_APP_VERSION, at build time (deploy/update.sh) or at runtime (Docker image).
      appVersion: process.env.NUXT_PUBLIC_APP_VERSION || 'dev',
    },
  },
  icon: {
    serverBundle: { collections: ['lucide'] },
    // /api/** is proxied to the Symfony API: the icon endpoint lives elsewhere, so icons missing from the client bundle
    // (named in app.config.ts or sent by the API) still load.
    localApiEndpoint: '/_nuxt_icon',
    clientBundle: { scan: true },
  },
})
