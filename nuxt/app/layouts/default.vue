<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

const auth = useAuth()
const app = useAppConfig().rocket
const { t, locale, locales, setLocale } = useRocketI18n()
const { version, updateAvailable, load: loadVersion } = useAppVersion()

watch(() => auth.me.value, (me) => {
  if (me) loadVersion()
}, { immediate: true })

const { info: suite, isSuite } = useSuite()

// Back from an embedded page (application's palette): the project's one.
const theme = useTheme()
if (theme.application.value) theme.load(null)

// Suite mode: the other applications of the suite, and the accounts managed in Rocket Auth.
const switcher = computed(() => [
  (suite.value?.apps ?? []).map(other => ({
    label: other.name,
    icon: other.icon ?? 'i-lucide-app-window',
    to: other.url,
    target: '_self',
    disabled: other.id === suite.value?.app.id,
  })),
  suite.value?.auth ? [{ label: t('layout.myAccount', { name: suite.value.auth.name }), icon: 'i-lucide-user-cog', to: suite.value.auth.accountUrl, target: '_blank' }] : [],
].filter(group => group.length))

const items = computed<NavigationMenuItem[][]>(() => [
  [
    { label: t('layout.dashboard'), icon: 'i-lucide-layout-dashboard', to: '/' },
  ],
  // Labels of the brick may be keys of its texts (rocket.messages): t() returns any other label unchanged.
  app.navigation.filter(item => !item.admin || auth.isAdmin.value).map(item => ({ ...item, label: t(item.label) })) as NavigationMenuItem[],
  auth.isAdmin.value
    ? [
        { label: t('layout.administration'), type: 'label' },
        ...app.adminNavigation.map(item => ({ ...item, label: t(item.label) })),
        { label: t('layout.users'), icon: 'i-lucide-users', to: '/users' },
        // Suite mode: sign-in is Rocket Auth's (managed from the configuration).
        ...(isSuite.value ? [] : [{ label: t('layout.authServers'), icon: 'i-lucide-shield-check', to: '/authentication-servers' }]),
        { label: t('layout.applications'), icon: 'i-lucide-key-round', to: '/applications' },
        { label: t('layout.palettes'), icon: 'i-lucide-palette', to: '/palettes' },
        { label: t('layout.updates'), icon: updateAvailable.value ? 'i-lucide-circle-arrow-up' : 'i-lucide-refresh-cw', to: '/updates' },
      ]
    : [],
])

// Language menu, when the application offers several.
const LANGUAGE_NAMES: Record<string, string> = { fr: 'layout.french', en: 'layout.english' }
const languageItems = computed(() => [locales.value.map(code => ({
  label: t(LANGUAGE_NAMES[code] ?? code),
  type: 'checkbox' as const,
  checked: locale.value === code,
  onSelect: () => setLocale(code),
}))])
</script>

<template>
  <UDashboardGroup>
    <UDashboardSidebar collapsible resizable>
      <template #header="{ collapsed }">
        <UDropdownMenu v-if="isSuite && switcher.length" :items="switcher" :content="{ align: 'start' }" :ui="{ content: 'min-w-56' }">
          <UButton color="neutral" variant="ghost" class="w-full font-semibold" :square="collapsed" data-testid="app-switcher">
            <UIcon :name="app.icon" class="size-5 text-primary" />
            <span v-if="!collapsed" class="truncate">{{ app.name }}</span>
            <UIcon v-if="!collapsed" name="i-lucide-chevrons-up-down" class="ms-auto size-4 text-dimmed" />
          </UButton>
        </UDropdownMenu>
        <div v-else class="flex items-center gap-2 font-semibold">
          <UIcon :name="app.icon" class="size-5 text-primary" />
          <span v-if="!collapsed">{{ app.name }}</span>
        </div>
      </template>

      <template #default="{ collapsed }">
        <template v-for="(group, index) in items" :key="index">
          <UNavigationMenu v-if="group.length" :items="group" :collapsed="collapsed" orientation="vertical" />
        </template>
      </template>

      <template #footer="{ collapsed }">
        <div class="flex w-full flex-col gap-2">
          <template v-if="!collapsed">
            <NuxtLink v-if="auth.isAdmin.value" to="/updates" class="flex items-center gap-2 px-2 text-xs text-muted hover:text-default" data-testid="app-version">
              <span class="truncate">{{ t('layout.version', { version }) }}</span>
              <UBadge v-if="updateAvailable" :label="t('layout.new')" size="sm" variant="subtle" icon="i-lucide-circle-arrow-up" class="shrink-0" />
            </NuxtLink>
            <p v-else class="px-2 text-xs text-muted" data-testid="app-version">
              {{ t('layout.version', { version }) }}
            </p>
          </template>
          <div class="flex w-full items-center gap-2">
            <UUser
              v-if="auth.me.value?.user && !collapsed"
              :name="auth.me.value.user.displayName"
              :description="auth.me.value.user.email"
              size="sm"
              class="min-w-0 flex-1"
            />
            <UDropdownMenu v-if="!collapsed && locales.length > 1" :items="languageItems" :content="{ align: 'end', side: 'top' }">
              <UButton color="neutral" variant="ghost" :label="locale.toUpperCase()" :aria-label="t('common.language')" data-testid="language" />
            </UDropdownMenu>
            <ColorModeSwitch v-if="!collapsed" />
            <UButton
              icon="i-lucide-log-out"
              color="neutral"
              variant="ghost"
              :aria-label="t('layout.logout')"
              @click="auth.logout()"
            />
          </div>
        </div>
      </template>
    </UDashboardSidebar>

    <slot />
  </UDashboardGroup>
</template>
