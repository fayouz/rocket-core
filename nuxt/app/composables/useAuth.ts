import type { Me } from '#rocket/types/api'

const TOKEN_TTL_SECONDS = 3600

export function useAuth() {
  const config = useRuntimeConfig()
  // One cookie per application: applications of the suite may share a host (cookies ignore ports).
  const TOKEN_COOKIE = `rocket_${useAppConfig().rocket.id}_token`
  const token = useState<string | null>('rocket_token', () => useCookie<string | null>(TOKEN_COOKIE).value ?? null)

  // Written synchronously: a useCookie ref only persists through a watcher, which is lost
  // when the component that set it unmounts right away (e.g. navigating after login).
  function setToken(value: string | null) {
    token.value = value
    const secure = window.location.protocol === 'https:' ? '; Secure' : ''
    document.cookie = value
      ? `${TOKEN_COOKIE}=${encodeURIComponent(value)}; Path=/; Max-Age=${TOKEN_TTL_SECONDS}; SameSite=Strict${secure}`
      : `${TOKEN_COOKIE}=; Path=/; Max-Age=0; SameSite=Strict${secure}`
  }
  const me = useState<Me | null>('rocket_me', () => null)
  // Session of an embedded page (/embed/…), handed over by the host application: kept in memory only,
  // third-party iframes cannot rely on cookies.
  const embedToken = useState<string | null>('rocket_embed_token', () => null)

  const isAuthenticated = computed(() => !!(embedToken.value || token.value))
  const isAdmin = computed(() => me.value?.roles.includes('ROLE_ADMIN') ?? false)

  function authorizationHeader(): string | undefined {
    if (embedToken.value) return `Embed ${embedToken.value}`
    if (token.value) return `Bearer ${token.value}`
    return undefined
  }

  async function login(email: string, password: string) {
    const response = await $fetch<{ token: string }>('/api/auth/login', {
      baseURL: config.public.apiBase,
      method: 'POST',
      body: { email, password },
    })
    setToken(response.token)
    await fetchMe()
  }

  /** Opens a session with a token obtained elsewhere (e.g. the first-run setup). */
  async function startSession(value: string) {
    setToken(value)
    await fetchMe()
  }

  async function fetchMe() {
    me.value = await useApi()<Me>('/api/me')
    return me.value
  }

  /**
   * Ends the session; in suite mode, also the Rocket Auth session (RP-initiated logout), back on /login.
   * redirect = false: only forgets the session (e.g. an expired token on a public page).
   */
  async function logout(redirect = true) {
    setToken(null)
    me.value = null
    if (!redirect) return
    const logoutUrl = useSuite().info.value?.auth?.logoutUrl
    if (logoutUrl) {
      const back = `${window.location.origin}/login?logged_out=1`
      window.location.assign(`${logoutUrl}&post_logout_redirect_uri=${encodeURIComponent(back)}`)
      return
    }
    await navigateTo('/login')
  }

  return { token: readonly(token), embedToken, me, isAuthenticated, isAdmin, authorizationHeader, login, startSession, fetchMe, logout }
}
