import type { RocketExtensions } from './types/extensions'

/**
 * Identity of the application and its own menu entries: the rest of the interface (layout, dashboard,
 * administration pages) comes from the rocket-core layer. Applications override these values in their app.config.ts.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'sky',
      neutral: 'zinc',
    },
  },
  rocket: {
    // Identifier of the application (cookie of its session…), e.g. 'print'.
    id: 'rocket',
    name: 'Rocket',
    icon: 'i-lucide-rocket',
    // Login page subtitle (empty: the layer's, in the viewer's language).
    tagline: '',
    // Languages of the interface: the viewer chooses among them in the user menu when there are several.
    locales: ['fr'] as string[],
    // Language when the viewer has not chosen (a brick may also set it at runtime: useRocketI18n().setDefaultLocale).
    defaultLocale: 'fr',
    // The brick's own texts by language ({ fr: {...}, en: {...} }), read by useRocketI18n().t before the layer's.
    messages: {} as Record<string, Record<string, unknown>>,
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, exactQuery?: boolean, admin?: boolean }[],
    // The application has pages other applications embed (/embed/…, see useEmbedBridge): origins field on the applications.
    embed: false,
    // Pages opened without an account, by path prefix (e.g. '/s/' for share links).
    publicPaths: [] as string[],
    // Extra entries of the Administration menu.
    adminNavigation: [] as { label: string, icon: string, to: string, exactQuery?: boolean }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day (empty: the layer's, in the viewer's language).
    quotes: [] as [string, string][],
    // Extension points of the layer's pages (columns, row actions, form sections…): global components of the
    // application named here, instead of a copy of the page. See types/extensions.ts and the README.
    extensions: {
      applications: { columns: [], rowActions: [], formSections: [], help: '', tokenExample: '' },
      users: { columns: [], rowActions: [] },
      dashboard: { sections: [] },
    } as RocketExtensions,
  },
})
