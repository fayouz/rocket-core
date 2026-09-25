import type { H3Event } from 'h3'

/** Forwards the request to the API (NUXT_API_INTERNAL_BASE, else the public API URL). */
export function proxyToApi(event: H3Event) {
  const config = useRuntimeConfig()
  const target = config.apiInternalBase || config.public.apiBase
  if (!target) {
    throw createError({ statusCode: 503, statusMessage: 'API proxy not configured (NUXT_API_INTERNAL_BASE).' })
  }

  // h3 drops "Accept" when proxying; API Platform would then answer JSON-LD instead of JSON.
  // Redirects go back to the browser (OpenID Connect authorization, sign-out…), they are not followed here.
  return proxyRequest(event, `${target.replace(/\/$/, '')}${event.path}`, {
    headers: { accept: getRequestHeader(event, 'accept') ?? 'application/json' },
    fetchOptions: { redirect: 'manual' },
  })
}
