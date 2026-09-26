<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { User } from '#rocket/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
const { t } = useRocketI18n()
useHead({ title: () => t('users.pageTitle', { name: appName }) })

const api = useApi()
const auth = useAuth()
const { info: suite, isSuite } = useSuite()
const toast = useToast()
const UBadge = resolveComponent('UBadge')
const USwitch = resolveComponent('USwitch')
const UButton = resolveComponent('UButton')

// Extension points of the brick (app.config.ts, rocket.extensions.users): see the README.
const extensions = useRocketExtensions('users')
const rowActions = resolveRocketComponents(extensions.rowActions)

const { data: users, status, refresh } = await useAsyncData('users', () => api<User[]>('/api/users', { query: { itemsPerPage: 500 } }), { default: () => [] })

const search = ref('')
const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  return term ? users.value.filter(u => `${u.displayName} ${u.email}`.toLowerCase().includes(term)) : users.value
})

async function patch(user: User, body: Partial<User> & { plainPassword?: string }) {
  try {
    const updated = await api<User>(`/api/users/${user.id}`, { method: 'PATCH', body })
    Object.assign(user, updated)
    return true
  }
  catch (error) {
    toast.add({ title: t('users.updateFailed'), description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

function toggleAdmin(user: User, admin: boolean) {
  const roles = user.roles.filter(r => r !== 'ROLE_ADMIN' && r !== 'ROLE_USER')
  return patch(user, { roles: admin ? [...roles, 'ROLE_ADMIN'] : roles })
}

const columns = computed<TableColumn<User>[]>(() => [
  {
    accessorKey: 'displayName',
    header: t('users.columnUser'),
    cell: ({ row }) => h('div', [h('p', { class: 'font-medium' }, row.original.displayName), h('p', { class: 'text-sm text-muted' }, row.original.email)]),
  },
  {
    accessorKey: 'source',
    header: t('users.columnSource'),
    cell: ({ row }) => h(UBadge, {
      variant: 'subtle',
      color: row.original.source === 'ldap' ? 'info' : row.original.source === 'oidc' ? 'primary' : 'neutral',
      label: row.original.source === 'ldap'
        ? (row.original.authenticationServerName ?? t('users.sourceLdapFallback'))
        : row.original.source === 'oidc' ? (row.original.authenticationServerName ?? t('users.sourceOidcFallback')) : t('users.sourceLocal'),
    }),
  },
  {
    id: 'admin',
    header: t('users.columnAdmin'),
    cell: ({ row }) => h(USwitch, {
      'modelValue': row.original.roles.includes('ROLE_ADMIN'),
      'disabled': row.original.id === auth.me.value?.user?.id,
      'onUpdate:modelValue': (value: boolean) => toggleAdmin(row.original, value),
    }),
  },
  {
    accessorKey: 'enabled',
    header: t('users.columnActive'),
    cell: ({ row }) => h('div', { class: 'flex items-center gap-2' }, [
      h(UBadge, {
        variant: 'subtle',
        color: row.original.enabled ? 'success' : 'neutral',
        label: row.original.enabled ? t('common.active') : t('common.disabled'),
      }),
      h(USwitch, {
        'modelValue': row.original.enabled,
        'disabled': row.original.id === auth.me.value?.user?.id,
        'aria-label': row.original.enabled ? t('users.disable') : t('users.enable'),
        'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }),
      }),
    ]),
  },
  { accessorKey: 'ldapSyncedAt', header: t('users.columnLdapSync'), cell: ({ row }) => formatDate(row.original.ldapSyncedAt) },
  {
    accessorKey: 'groups',
    header: t('users.columnGroups'),
    cell: ({ row }) => h('div', { class: 'flex flex-wrap gap-1' }, row.original.groups.length
      ? row.original.groups.map(group => h(UBadge, { label: group, variant: 'outline', color: 'neutral', size: 'sm' }))
      : [h('span', { class: 'text-muted' }, '—')]),
  },
  ...resolveRocketColumns<User>(extensions.columns, 'user'),
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      ...rowActions.map(action => h(action, { user: row.original, onRefresh: () => refresh() })),
      // Directory accounts get their groups from LDAP, and in the suite everyone gets them from Rocket Auth.
      row.original.source !== 'ldap' && !isSuite.value
        ? h(UButton, { 'icon': 'i-lucide-users-round', 'color': 'neutral', 'variant': 'ghost', 'aria-label': t('users.editGroups'), 'data-testid': 'edit-groups', 'onClick': () => editGroups(row.original) })
        : null,
      row.original.source === 'local'
        ? h(UButton, { 'icon': 'i-lucide-key', 'color': 'neutral', 'variant': 'ghost', 'aria-label': t('users.changePassword'), 'onClick': () => (passwordFor.value = row.original) })
        : null,
    ]),
  },
])

// Create a local user
// Opened directly by "Nouvel utilisateur" on the dashboard.
// Groups of the account (directory, OpenID Connect provider, or set here)
const groupsFor = ref<User | null>(null)
const groups = ref<string[]>([])
function editGroups(user: User) {
  groupsFor.value = user
  groups.value = [...user.groups]
}
async function saveGroups() {
  if (groupsFor.value && await patch(groupsFor.value, { groups: groups.value })) groupsFor.value = null
}

const createOpen = ref(Boolean(useRoute().query.new))
const newUser = reactive({ email: '', firstName: '', lastName: '', plainPassword: '', admin: false })
async function createUser() {
  try {
    await api('/api/users', {
      method: 'POST',
      body: {
        email: newUser.email,
        firstName: newUser.firstName || null,
        lastName: newUser.lastName || null,
        plainPassword: newUser.plainPassword,
        roles: newUser.admin ? ['ROLE_ADMIN'] : [],
      },
    })
    Object.assign(newUser, { email: '', firstName: '', lastName: '', plainPassword: '', admin: false })
    createOpen.value = false
    toast.add({ title: t('users.userCreated'), color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: t('users.createFailed'), description: apiErrorMessage(error), color: 'error' })
  }
}

// Password reset for local users
const passwordFor = ref<User | null>(null)
const newPassword = ref('')
async function changePassword() {
  if (passwordFor.value && await patch(passwordFor.value, { plainPassword: newPassword.value })) {
    toast.add({ title: t('users.passwordChanged'), color: 'success' })
    passwordFor.value = null
    newPassword.value = ''
  }
}

// LDAP synchronization
const syncing = ref(false)
async function syncLdap(dryRun: boolean) {
  syncing.value = true
  try {
    const report = await api<{ created: number, updated: number, disabled: number, conflicts: string[] }>('/api/ldap/sync', { method: 'POST', query: { dryRun } })
    toast.add({
      title: dryRun ? t('users.syncingDryRun') : t('users.syncingDone'),
      description: t('users.syncReport', { created: report.created, updated: report.updated, disabled: report.disabled })
        + (report.conflicts.length ? t('users.syncReportConflicts', { names: report.conflicts.join(', ') }) : ''),
      color: 'success',
      duration: 8000,
    })
    if (!dryRun) await refresh()
  }
  catch (error) {
    toast.add({ title: t('users.syncFailed'), description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    syncing.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="users">
    <template #header>
      <UDashboardNavbar :title="t('users.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton v-if="isSuite && suite?.auth" icon="i-lucide-external-link" :label="t('users.manageIn', { name: suite.auth.name })" :to="suite.auth.accountUrl" target="_blank" />
          <template v-else>
            <UButton icon="i-lucide-flask-conical" :label="t('users.simulateSync')" color="neutral" variant="ghost" :loading="syncing" @click="syncLdap(true)" />
            <UButton icon="i-lucide-refresh-cw" :label="t('users.syncLdap')" color="neutral" variant="outline" :loading="syncing" @click="syncLdap(false)" />
            <UButton icon="i-lucide-user-plus" :label="t('users.localUser')" @click="createOpen = true" />
          </template>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="isSuite"
        icon="i-lucide-shield-check"
        color="info"
        variant="subtle"
        :title="t('users.suiteAccountsTitle', { name: suite?.auth?.name })"
        :description="t('users.suiteAccountsDescription')"
      />
      <UInput v-model="search" icon="i-lucide-search" :placeholder="`${t('common.search')}…`" class="max-w-sm" />
      <UTable :data="filtered" :columns="columns" :loading="status === 'pending'" :empty="t('users.empty')" />

      <UModal v-model:open="createOpen" :title="t('users.newUserTitle')">
        <template #body>
          <form id="create-user" class="flex flex-col gap-3" @submit.prevent="createUser">
            <UFormField :label="t('common.email')" required>
              <UInput v-model="newUser.email" type="email" class="w-full" />
            </UFormField>
            <div class="grid grid-cols-2 gap-3">
              <UFormField :label="t('common.firstName')">
                <UInput v-model="newUser.firstName" class="w-full" />
              </UFormField>
              <UFormField :label="t('common.lastName')">
                <UInput v-model="newUser.lastName" class="w-full" />
              </UFormField>
            </div>
            <UFormField :label="t('common.password')" :hint="t('users.passwordHint')" required>
              <UInput v-model="newUser.plainPassword" type="password" autocomplete="new-password" class="w-full" />
            </UFormField>
            <USwitch v-model="newUser.admin" :label="t('users.administrator')" />
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton :label="t('common.cancel')" color="neutral" variant="ghost" @click="createOpen = false" />
            <UButton type="submit" form="create-user" :label="t('common.create')" />
          </div>
        </template>
      </UModal>

      <UModal
        :open="passwordFor !== null"
        :title="t('users.newPasswordTitle', { email: passwordFor?.email })"
        @update:open="(value: boolean) => { if (!value) passwordFor = null }"
      >
        <template #body>
          <UFormField :label="t('common.password')" :hint="t('users.passwordHint')">
            <UInput v-model="newPassword" type="password" autocomplete="new-password" class="w-full" />
          </UFormField>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton :label="t('common.cancel')" color="neutral" variant="ghost" @click="passwordFor = null" />
            <UButton :label="t('common.save')" :disabled="newPassword.length < 12" @click="changePassword" />
          </div>
        </template>
      </UModal>

      <UModal
        :open="groupsFor !== null"
        :title="t('users.groupsTitle', { name: groupsFor?.displayName })"
        :description="t('users.groupsDescription')"
        @update:open="(value: boolean) => { if (!value) groupsFor = null }"
      >
        <template #body>
          <UInputTags v-model="groups" add-on-blur add-on-paste :placeholder="t('users.groupsPlaceholder')" class="w-full" data-testid="groups-input" />
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton :label="t('common.cancel')" color="neutral" variant="ghost" @click="groupsFor = null" />
            <UButton :label="t('common.save')" @click="saveGroups" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
