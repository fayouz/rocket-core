import type { SuiteInfo } from '#rocket/types/api'

const STANDALONE: SuiteInfo = { mode: 'standalone', app: { id: '', name: '' }, localLogin: true, auth: null, apps: [] }

/**
 * Standalone or suite mode of this application (GET /api/suite, loaded once). In suite mode, people sign in through
 * Rocket Auth, which also manages the accounts, and the menu lists the other applications of the suite.
 */
export function useSuite() {
  const info = useState<SuiteInfo | null>('rocket_suite', () => null)
  const isSuite = computed(() => info.value?.mode === 'suite')

  async function load(): Promise<SuiteInfo> {
    if (!info.value) {
      info.value = await $fetch<SuiteInfo>('/api/suite', { baseURL: useRuntimeConfig().public.apiBase }).catch(() => STANDALONE)
    }
    return info.value
  }

  return { info, isSuite, load }
}
