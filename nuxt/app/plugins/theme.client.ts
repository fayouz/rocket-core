/**
 * Applies the project's palette (or, on the embedded pages /embed/…?app=<id>, the application's one) before the
 * first page, the login page included. Read from the URL: the router is not resolved yet.
 */
export default defineNuxtPlugin(async () => {
  const url = new URL(window.location.href)
  const app = url.pathname.startsWith('/embed/') ? url.searchParams.get('app') : null
  await useTheme().load(app)
})
