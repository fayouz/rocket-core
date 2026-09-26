import type { UserSummary } from '#rocket/types/api'

/**
 * postMessage protocol between an embedded page (iframe on /embed/…?app=<application id>) and its host page.
 * Every message carries `source: 'rocket-<app id>'` (e.g. 'rocket-mailer'). Messages from the host are only trusted
 * when they come from window.parent AND from an origin declared on the application (allowedOrigins).
 *
 * iframe -> host: ready, token-request, then the page's own events (notify)
 * host -> iframe: token { token }, then the page's own messages (onHostMessage)
 */
const TOKEN_TIMEOUT_MS = 15_000

type HostMessage = { source: string, type: string, [key: string]: unknown }

export interface EmbedContext {
  user: UserSummary
  application: { id: string, name: string, allowedOrigins: string[] }
}

let pendingRenewal: Promise<void> | null = null

export function useEmbedBridge() {
  const { t } = useRocketI18n()
  const source = `rocket-${useAppConfig().rocket.id}`
  const allowedOrigins = useState<string[]>('rocket_embed_origins', () => [])
  const hostOrigin = useState<string | null>('rocket_embed_host', () => null)
  const auth = useAuth()

  function isTrusted(event: MessageEvent): event is MessageEvent<HostMessage> {
    return event.source === window.parent
      && allowedOrigins.value.includes(event.origin)
      && event.data?.source === source
  }

  /** Non-sensitive signal, sent before the host origin is known. */
  function signal(type: 'ready' | 'token-request') {
    window.parent.postMessage({ source, type }, '*')
  }

  /** Sensitive message: only ever delivered to the verified host origin. */
  function notify(type: string, payload: Record<string, unknown> = {}) {
    if (hostOrigin.value) {
      window.parent.postMessage({ source, type, ...payload }, hostOrigin.value)
    }
  }

  /** Origin of the embedding page when the browser exposes it and it is allowed. */
  function detectHostOrigin(): string | null {
    const candidates = [
      window.location.ancestorOrigins?.[0],
      document.referrer ? new URL(document.referrer).origin : undefined,
    ]
    return candidates.find(o => o && allowedOrigins.value.includes(o)) ?? null
  }

  function waitForToken(): Promise<string> {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        window.removeEventListener('message', listener)
        reject(new Error('The host page did not provide an embed token.'))
      }, TOKEN_TIMEOUT_MS)

      function listener(event: MessageEvent) {
        if (!isTrusted(event) || event.data.type !== 'token' || typeof event.data.token !== 'string') return
        clearTimeout(timer)
        window.removeEventListener('message', listener)
        hostOrigin.value = event.origin
        resolve(event.data.token)
      }

      window.addEventListener('message', listener)
    })
  }

  async function requestToken(type: 'ready' | 'token-request') {
    const token = waitForToken()
    signal(type)
    auth.embedToken.value = await token
  }

  function renewToken(): Promise<void> {
    pendingRenewal ??= requestToken('token-request').finally(() => {
      pendingRenewal = null
    })
    return pendingRenewal
  }

  /** Messages of the host page for this embedded page (e.g. 'draft'); returns the unsubscribe function. */
  function onHostMessage(type: string, handler: (message: HostMessage) => void) {
    const listener = (event: MessageEvent) => {
      if (isTrusted(event) && event.data.type === type) handler(event.data)
    }
    window.addEventListener('message', listener)
    return () => window.removeEventListener('message', listener)
  }

  /**
   * Opens the session of the embedded page: origins allowed for the application (?app=), then the token, received
   * either through postMessage from a verified parent origin or in the URL fragment (never sent to servers).
   * Keeps the host informed of the page's height (resize) and says when it is loaded.
   */
  async function connect(applicationId: string): Promise<EmbedContext> {
    if (!applicationId) throw new Error(t('embed.missingApp'))

    const policy = await $fetch<{ frameAncestors: string[] }>('/api/embed/frame-policy', {
      baseURL: useRuntimeConfig().public.apiBase,
      query: { app: applicationId },
    })
    allowedOrigins.value = policy.frameAncestors
    if (window.parent === window) throw new Error(t('embed.notEmbedded'))

    const fragmentToken = new URLSearchParams(window.location.hash.slice(1)).get('token')
    if (fragmentToken) {
      history.replaceState(null, '', window.location.pathname + window.location.search)
      auth.embedToken.value = fragmentToken
      hostOrigin.value = detectHostOrigin()
    }
    else {
      await requestToken('ready')
    }

    const context = await useApi()<EmbedContext>('/api/embed/context')
    if (context.application.id !== applicationId) throw new Error(t('embed.wrongApp'))

    new ResizeObserver(() => notify('resize', { height: document.documentElement.scrollHeight })).observe(document.body)
    notify('loaded')

    return context
  }

  return { allowedOrigins, hostOrigin, detectHostOrigin, requestToken, renewToken, notify, onHostMessage, connect }
}
