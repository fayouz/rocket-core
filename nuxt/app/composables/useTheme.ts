import type { Neutral, ThemePalette } from '#rocket/utils/palette'
import type { ThemeResponse } from '#rocket/types/api'

const STYLE_ID = 'rocket-palette'

/**
 * Colors of the interface: the project's palette (Administration → Palettes), or on the embedded pages (/embed/…?app=)
 * the application's one. Light / dark / system is the user's choice (useColorMode), kept in the browser.
 */
export function useTheme() {
  const config = useRuntimeConfig()
  const appConfig = useAppConfig()
  const current = useState<ThemeResponse | null>('rocket_theme', () => null)
  // Application whose palette is applied (embedded pages), null: the project's one.
  const application = useState<string | null>('rocket_theme_app', () => null)
  // The brick's own gray (app.config.ts), restored when the palette has none.
  const defaultNeutral = useState<string>('rocket_theme_default_neutral', () => appConfig.ui.colors.neutral as string)

  function apply(palette: ThemePalette | null) {
    appConfig.ui.colors.neutral = (palette?.neutral ?? defaultNeutral.value) as Neutral
    if (!import.meta.client) return
    document.getElementById(STYLE_ID)?.remove()
    const declarations = palette ? paletteDeclarations(palette) : ''
    if (!declarations) return
    const style = document.createElement('style')
    style.id = STYLE_ID
    // Unlayered: wins over Nuxt UI's @layer theme.
    style.textContent = `:root, :host {\n  ${declarations}\n}`
    document.head.appendChild(style)
  }

  /** Loads and applies the palette; public endpoint (GET /api/theme), so it works on the login and embedded pages. */
  async function load(app: string | null = application.value) {
    application.value = app || null
    try {
      current.value = await $fetch<ThemeResponse>('/api/theme', {
        baseURL: config.public.apiBase,
        headers: { Accept: 'application/json' },
        query: application.value ? { app: application.value } : {},
      })
      apply(current.value.palette)
    }
    catch {
      // Default colors (older API without /api/theme, network error).
    }
  }

  return { current, application, apply, load }
}
