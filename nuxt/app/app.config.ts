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
    // Login page subtitle.
    tagline: 'Connectez-vous avec votre compte local ou votre compte d’annuaire (LDAP).',
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, exactQuery?: boolean, admin?: boolean }[],
    // Extra entries of the Administration menu.
    adminNavigation: [] as { label: string, icon: string, to: string, exactQuery?: boolean }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['La simplicité est la sophistication suprême.', 'Léonard de Vinci'],
      ['Ce qui se conçoit bien s’énonce clairement.', 'Nicolas Boileau'],
    ] as [string, string][],
  },
})
