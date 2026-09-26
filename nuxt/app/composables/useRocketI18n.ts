import type { Messages } from '../locales/types'
import { LAYER_MESSAGES } from '../locales'

const LANGUAGE_TAGS: Record<string, string> = { fr: 'fr-FR', en: 'en-US' }

/**
 * Language of the interface and its texts. The language is the viewer's choice (cookie "rocket_locale", language
 * menu of the layout) among `rocket.locales`, else the application's default (`rocket.defaultLocale`, which a brick
 * may also set at runtime with setDefaultLocale). Texts: the brick's `rocket.messages` first, then the layer's.
 *
 *   const { t } = useRocketI18n()
 *   t('users.title')            // "Utilisateurs" / "Users"
 *   t('users.count', { n: 3 })  // {n} is replaced
 */
export function useRocketI18n() {
  const app = useAppConfig().rocket
  const locales = computed(() => (app.locales?.length ? app.locales : ['fr']) as string[])
  const cookie = useCookie<string | null>('rocket_locale', { maxAge: 60 * 60 * 24 * 365, sameSite: 'lax', default: () => null })
  const fallback = useState<string>('rocket-default-locale', () => app.defaultLocale || 'fr')

  const locale = computed(() => cookie.value && locales.value.includes(cookie.value) ? cookie.value : fallback.value)
  const languageTag = computed(() => LANGUAGE_TAGS[locale.value] ?? locale.value)

  function lookup(messages: Messages | undefined, key: string): string | undefined {
    let value: string | Messages | undefined = messages
    for (const part of key.split('.')) value = typeof value === 'object' ? value[part] : undefined
    return typeof value === 'string' ? value : undefined
  }

  function t(key: string, params: Record<string, string | number | null | undefined> = {}): string {
    const brick = app.messages as Record<string, Messages> | undefined
    const text = lookup(brick?.[locale.value], key) ?? lookup(LAYER_MESSAGES[locale.value], key)
      ?? lookup(brick?.fr, key) ?? lookup(LAYER_MESSAGES.fr, key) ?? key
    return text.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`))
  }

  return {
    t,
    locale,
    languageTag,
    locales,
    /** The viewer's choice, kept a year. */
    setLocale: (value: string) => {
      cookie.value = value
    },
    /** The application's default when the viewer has not chosen (e.g. from a setting of the brick). */
    setDefaultLocale: (value: string) => {
      fallback.value = value
    },
  }
}
