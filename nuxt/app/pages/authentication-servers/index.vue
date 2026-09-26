<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AuthenticationServer, AuthenticationServerDiscoveryCandidate, Collection, LdapTestResult, OidcTestResult } from '#rocket/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
const { t } = useRocketI18n()
useHead({ title: () => `${t('authServers.title')} · ${appName}` })

const api = useApi()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const USwitch = resolveComponent('USwitch')

const PAGE_SIZE = 10
const page = ref(Number(route.query.page) || 1)

watch(page, value => router.replace({ query: value > 1 ? { page: String(value) } : {} }))

const { data, status, refresh } = await useAsyncData(
  'authentication-servers',
  () => api<Collection<AuthenticationServer>>('/api/authentication_servers', {
    query: { page: page.value, itemsPerPage: PAGE_SIZE },
    headers: { Accept: 'application/ld+json' },
  }),
  { watch: [page], default: () => ({ member: [], totalItems: 0 }) },
)

const formOpen = ref(false)
const editing = ref<AuthenticationServer | null>(null)
const toDelete = ref<AuthenticationServer | null>(null)
const disableLinkedUsers = ref(true)
const OIDC_DEFAULTS = { internalUrl: '', clientId: '', clientSecret: '', scopes: 'openid email profile groups', adminGroupDn: '', linkExistingAccounts: false }
const form = reactive({ name: '', type: 'ldap' as AuthenticationServer['type'], enabled: false, url: 'ldap://', ...OIDC_DEFAULTS })
// Where the provider must send users back: to be declared on the client registered at the provider.
const redirectUri = import.meta.client ? oidcRedirectUri() : ''
const wizardOpen = ref(false)
const wizardStep = ref(1)
const wizardType = ref<AuthenticationServer['type']>('ldap')
const discoveryStatus = ref<'idle' | 'loading' | 'done'>('idle')
const discovery = ref<AuthenticationServerDiscoveryCandidate[]>([])
const wizardError = ref('')
const wizardTesting = ref(false)
const wizardSaving = ref(false)
const wizardResult = ref<LdapTestResult | OidcTestResult | null>(null)
const oidcForm = reactive({ name: 'Rocket Auth', url: 'https://', ...OIDC_DEFAULTS, linkExistingAccounts: false })
const wizardForm = reactive({
  name: 'LDAP',
  url: '',
  baseDn: '',
  bindDn: '',
  bindPassword: '',
  userFilter: '(objectClass=inetOrgPerson)',
  adminGroupDn: '',
})

async function discoverServers() {
  discoveryStatus.value = 'loading'
  wizardError.value = ''
  try {
    discovery.value = (await api<{ servers: AuthenticationServerDiscoveryCandidate[] }>('/api/authentication_servers/discover')).servers
    discoveryStatus.value = 'done'
  }
  catch (error) {
    discoveryStatus.value = 'idle'
    wizardError.value = apiErrorMessage(error)
  }
}

async function openWizard() {
  wizardOpen.value = true
  wizardStep.value = 1
  wizardResult.value = null
  wizardForm.bindPassword = ''
}

async function startDiscovery() {
  if (wizardType.value === 'oidc') {
    // Nothing to detect: the issuer's discovery document is read when testing.
    wizardStep.value = 3
    return
  }
  wizardStep.value = 2
  await discoverServers()
}

function previousStep() {
  wizardStep.value = wizardType.value === 'oidc' && wizardStep.value === 3 ? 1 : wizardStep.value - 1
}

function continueManually() {
  wizardForm.url = 'ldap://'
  wizardStep.value = 3
}

function selectDiscoveredServer(candidate: AuthenticationServerDiscoveryCandidate) {
  wizardForm.url = candidate.url
  wizardStep.value = 3
}

async function testWizard() {
  wizardTesting.value = true
  wizardError.value = ''
  try {
    wizardResult.value = wizardType.value === 'oidc'
      ? await api<OidcTestResult>('/api/authentication_servers/oidc/test', { method: 'POST', body: { url: oidcForm.url, internalUrl: oidcForm.internalUrl } })
      : await api<LdapTestResult>('/api/ldap/test', { method: 'POST', body: { ...wizardForm, enabled: true } })
    wizardStep.value = 4
  }
  catch (error) {
    wizardError.value = apiErrorMessage(error)
  }
  finally {
    wizardTesting.value = false
  }
}

async function saveWizard() {
  wizardSaving.value = true
  wizardError.value = ''
  if (wizardType.value === 'oidc') {
    try {
      await api<AuthenticationServer>('/api/authentication_servers', { method: 'POST', body: { ...oidcForm, type: 'oidc', enabled: true } })
      wizardOpen.value = false
      await refresh()
    }
    catch (error) {
      wizardError.value = apiErrorMessage(error)
    }
    finally {
      wizardSaving.value = false
    }
    return
  }
  try {
    await api('/api/ldap/config', { method: 'PUT', body: { ...wizardForm, enabled: true } })
    const servers = await api<Collection<AuthenticationServer>>('/api/authentication_servers', { query: { itemsPerPage: 100 }, headers: { Accept: 'application/ld+json' } })
    const server = servers.member.find(item => item.type === 'ldap')
    if (server) {
      await api(`/api/authentication_servers/${server.id}`, { method: 'PATCH', body: { name: wizardForm.name, enabled: true, url: wizardForm.url } })
    }
    wizardOpen.value = false
    await refresh()
  }
  catch (error) {
    wizardError.value = apiErrorMessage(error)
  }
  finally {
    wizardSaving.value = false
  }
}

function edit(server: AuthenticationServer) {
  editing.value = server
  Object.assign(form, {
    name: server.name,
    type: server.type,
    enabled: server.enabled,
    url: server.url,
    internalUrl: server.internalUrl ?? '',
    clientId: server.clientId ?? '',
    clientSecret: '',
    scopes: server.scopes ?? OIDC_DEFAULTS.scopes,
    adminGroupDn: server.adminGroupDn ?? '',
    linkExistingAccounts: server.linkExistingAccounts ?? false,
  })
  formOpen.value = true
}

function askDelete(server: AuthenticationServer) {
  toDelete.value = server
  disableLinkedUsers.value = true
}

async function submit() {
  // LDAP servers are configured on their own page (/ldap): only the common fields here.
  const body = form.type === 'oidc'
    ? { ...form }
    : { name: form.name, type: form.type, enabled: form.enabled, url: form.url }
  try {
    if (editing.value) {
      Object.assign(editing.value, await api<AuthenticationServer>(`/api/authentication_servers/${editing.value.id}`, { method: 'PATCH', body }))
    }
    else {
      await api<AuthenticationServer>('/api/authentication_servers', { method: 'POST', body })
    }
    formOpen.value = false
    await refresh()
  }
  catch (error) {
    toast.add({ title: editing.value ? t('authServers.editFailed') : t('authServers.createFailed'), description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove() {
  const server = toDelete.value
  if (!server) return
  toDelete.value = null
  try {
    await api(`/api/authentication_servers/${server.id}`, { method: 'DELETE', body: { disableUsers: disableLinkedUsers.value } })
    await refresh()
  }
  catch (error) {
    toast.add({ title: t('authServers.deleteFailed'), description: apiErrorMessage(error), color: 'error' })
  }
}

async function toggle(server: AuthenticationServer) {
  try {
    Object.assign(server, await api<AuthenticationServer>(`/api/authentication_servers/${server.id}`, { method: 'PATCH', body: { enabled: !server.enabled } }))
  }
  catch (error) {
    toast.add({ title: t('authServers.toggleFailed'), description: apiErrorMessage(error), color: 'error' })
  }
}

const columns = computed<TableColumn<AuthenticationServer>[]>(() => [
  {
    accessorKey: 'name',
    header: t('authServers.columnServer'),
    cell: ({ row }) => h('div', [
      h('p', { class: 'font-medium' }, row.original.name),
      h('p', { class: 'text-xs text-muted' }, row.original.url),
    ]),
  },
  {
    accessorKey: 'type',
    header: t('authServers.columnType'),
    cell: ({ row }) => h(UBadge, row.original.type === 'oidc'
      ? { label: t('authServers.oidcType'), color: 'primary', variant: 'subtle' }
      : { label: t('authServers.ldapType'), color: 'info', variant: 'subtle' }),
  },
  {
    accessorKey: 'enabled',
    header: t('common.status'),
    cell: ({ row }) => h(USwitch, { modelValue: row.original.enabled, 'onUpdate:modelValue': () => toggle(row.original) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end' }, [
      h(UButton, {
        icon: 'i-lucide-settings-2',
        label: t('authServers.configure'),
        color: 'neutral',
        variant: 'ghost',
        onClick: () => row.original.type === 'ldap' ? navigateTo('/ldap') : edit(row.original),
      }),
      h(UButton, { icon: 'i-lucide-pencil', color: 'neutral', variant: 'ghost', 'aria-label': t('authServers.editAria'), onClick: () => edit(row.original) }),
      h(UButton, { icon: 'i-lucide-trash-2', color: 'error', variant: 'ghost', 'aria-label': t('authServers.deleteAria'), onClick: () => askDelete(row.original) }),
    ]),
  },
])

const totalPages = computed(() => Math.max(1, Math.ceil(data.value.totalItems / PAGE_SIZE)))
watch(totalPages, value => {
  if (page.value > value) page.value = value
})
</script>

<template>
  <UDashboardPanel id="authentication-servers">
    <template #header>
      <UDashboardNavbar :title="t('authServers.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" :label="t('authServers.addServer')" @click="openWizard" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-4">
        <UPageCard :title="t('authServers.configuredTitle')" :description="t('authServers.configuredDescription')">
          <UTable :data="data.member" :columns="columns" :loading="status === 'pending'" />
          <div v-if="!data.member.length && status !== 'pending'" class="py-8 text-center text-sm text-muted">
            {{ t('authServers.empty') }}
          </div>
          <div v-if="data.totalItems > PAGE_SIZE" class="flex justify-center border-t border-default pt-4">
            <UPagination v-model:page="page" :page-count="PAGE_SIZE" :total="data.totalItems" />
          </div>
        </UPageCard>

        <UModal v-model:open="formOpen" :title="editing ? t('authServers.editTitle', { name: editing.name }) : t('authServers.addTitle')">
          <template #body>
            <form id="authentication-server-form" class="flex flex-col gap-3" @submit.prevent="submit">
              <UFormField :label="t('authServers.fieldName')" required>
                <UInput v-model="form.name" class="w-full" />
              </UFormField>
              <UFormField :label="t('authServers.fieldType')" required>
                <USelect v-model="form.type" :items="[{ label: t('authServers.ldapType'), value: 'ldap' }, { label: t('authServers.oidcType'), value: 'oidc' }]" class="w-full" :disabled="!!editing" />
              </UFormField>
              <UFormField :label="form.type === 'oidc' ? t('authServers.fieldIssuer') : t('authServers.fieldUrl')" required>
                <UInput v-model="form.url" :placeholder="form.type === 'oidc' ? t('authServers.issuerPlaceholder') : t('authServers.urlPlaceholder')" class="w-full font-mono" />
              </UFormField>
              <template v-if="form.type === 'oidc'">
                <OidcServerFields v-model="form" :redirect-uri="redirectUri" :has-secret="editing?.hasClientSecret ?? false" />
              </template>
              <USwitch v-model="form.enabled" :label="t('authServers.serverEnabled')" />
            </form>
          </template>
          <template #footer>
            <div class="flex w-full justify-end gap-2">
              <UButton :label="t('common.cancel')" color="neutral" variant="ghost" @click="formOpen = false" />
              <UButton type="submit" form="authentication-server-form" :label="editing ? t('common.save') : t('common.add')" />
            </div>
          </template>
        </UModal>

        <UModal v-model:open="wizardOpen" :title="wizardStep === 1 ? t('authServers.stepType') : wizardStep === 2 ? t('authServers.stepDetect') : wizardStep === 3 ? t('authServers.stepConnection') : t('authServers.stepValidate')" :ui="{ content: 'max-w-2xl' }">
          <template #body>
            <div class="flex flex-col gap-4">
              <div class="flex items-center gap-2 text-xs text-muted">
                <UBadge :color="wizardStep >= 1 ? 'primary' : 'neutral'" label="1" variant="subtle" />
                <span>{{ t('authServers.stepLabelType') }}</span>
                <span class="text-dimmed">→</span>
                <UBadge :color="wizardStep >= 2 ? 'primary' : 'neutral'" label="2" variant="subtle" />
                <span>{{ t('authServers.stepLabelDetection') }}</span>
                <span class="text-dimmed">→</span>
                <UBadge :color="wizardStep >= 3 ? 'primary' : 'neutral'" label="3" variant="subtle" />
                <span>{{ t('authServers.stepLabelConnection') }}</span>
                <span class="text-dimmed">→</span>
                <UBadge :color="wizardStep >= 4 ? 'primary' : 'neutral'" label="4" variant="subtle" />
                <span>{{ t('authServers.stepLabelValidation') }}</span>
              </div>

              <UAlert v-if="wizardError" color="error" variant="subtle" icon="i-lucide-circle-alert" :description="wizardError" />

              <template v-if="wizardStep === 1">
                <p class="text-sm text-muted">{{ t('authServers.chooseTypeDescription') }}</p>
                <button type="button" class="flex items-center gap-3 rounded border-2 p-4 text-start" :class="wizardType === 'ldap' ? 'border-primary bg-primary/5' : 'border-default'" @click="wizardType = 'ldap'">
                  <UIcon name="i-lucide-network" class="size-6 text-primary" />
                  <span>
                    <span class="block font-medium">{{ t('authServers.ldapOptionTitle') }}</span>
                    <span class="block text-sm text-muted">{{ t('authServers.ldapOptionDescription') }}</span>
                  </span>
                  <UIcon v-if="wizardType === 'ldap'" name="i-lucide-circle-check" class="ms-auto size-5 text-primary" />
                </button>
                <button type="button" class="flex items-center gap-3 rounded border-2 p-4 text-start" :class="wizardType === 'oidc' ? 'border-primary bg-primary/5' : 'border-default'" data-testid="wizard-type-oidc" @click="wizardType = 'oidc'">
                  <UIcon name="i-lucide-shield-check" class="size-6 text-primary" />
                  <span>
                    <span class="block font-medium">{{ t('authServers.oidcOptionTitle') }}</span>
                    <span class="block text-sm text-muted">{{ t('authServers.oidcOptionDescription') }}</span>
                  </span>
                  <UIcon v-if="wizardType === 'oidc'" name="i-lucide-circle-check" class="ms-auto size-5 text-primary" />
                </button>
              </template>

              <template v-else-if="wizardStep === 2">
                <p class="text-sm text-muted">{{ t('authServers.discoveryDescription') }}</p>
                <div v-if="discoveryStatus === 'loading'" class="py-6 text-center text-sm text-muted">{{ t('authServers.discoveryLoading') }}</div>
                <div v-else-if="!discovery.length" class="flex flex-col items-center gap-3 py-6 text-center">
                  <p class="text-sm text-muted">{{ t('authServers.discoveryNone') }}</p>
                  <UButton :label="t('authServers.continueManually')" icon="i-lucide-pencil-line" color="neutral" variant="outline" @click="continueManually" />
                </div>
                <div v-else class="flex flex-col gap-2">
                  <button v-for="candidate in discovery" :key="candidate.url" type="button" class="flex items-center justify-between rounded border border-default p-3 text-start transition-colors" :class="candidate.reachable ? 'cursor-pointer hover:border-primary hover:bg-elevated' : 'cursor-not-allowed opacity-60'" :disabled="!candidate.reachable" :aria-label="candidate.reachable ? t('authServers.selectCandidateAria', { url: candidate.url }) : t('authServers.candidateUnreachableAria', { url: candidate.url })" @click.stop="selectDiscoveredServer(candidate)" @keydown.enter.prevent="selectDiscoveredServer(candidate)">
                    <span class="font-mono text-sm">{{ candidate.url }}</span>
                    <span class="flex items-center gap-2">
                      <UBadge :color="candidate.reachable ? 'success' : 'neutral'" :label="candidate.reachable ? `${candidate.latencyMs} ms` : t('authServers.unreachable')" variant="subtle" />
                      <span v-if="candidate.reachable" class="text-xs font-medium text-primary">{{ t('authServers.select') }}</span>
                    </span>
                  </button>
                </div>
              </template>

              <template v-else-if="wizardStep === 3 && wizardType === 'oidc'">
                <form id="authentication-wizard-form" class="flex flex-col gap-3" @submit.prevent="testWizard">
                  <UFormField :label="t('authServers.oidcNameLabel')" required>
                    <UInput v-model="oidcForm.name" class="w-full" />
                  </UFormField>
                  <UFormField :label="t('authServers.fieldIssuer')" required :help="t('authServers.issuerHelp')">
                    <UInput v-model="oidcForm.url" :placeholder="t('authServers.issuerPlaceholder')" class="w-full font-mono" />
                  </UFormField>
                  <OidcServerFields v-model="oidcForm" :redirect-uri="redirectUri" :has-secret="false" />
                </form>
              </template>

              <template v-else-if="wizardStep === 3">
                <form id="authentication-wizard-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="testWizard">
                  <UFormField :label="t('authServers.fieldName')" required>
                    <UInput v-model="wizardForm.name" class="w-full" />
                  </UFormField>
                  <UFormField :label="t('authServers.selectedServerLabel')" required>
                    <UInput v-model="wizardForm.url" class="w-full font-mono" />
                  </UFormField>
                  <UFormField :label="t('authServers.baseDnLabel')" required class="sm:col-span-2">
                    <UInput v-model="wizardForm.baseDn" :placeholder="t('authServers.baseDnPlaceholder')" class="w-full font-mono" />
                  </UFormField>
                  <UFormField :label="t('authServers.serviceAccountLabel')">
                    <UInput v-model="wizardForm.bindDn" class="w-full font-mono" autocomplete="off" />
                  </UFormField>
                  <UFormField :label="t('common.password')">
                    <UInput v-model="wizardForm.bindPassword" type="password" class="w-full" autocomplete="new-password" />
                  </UFormField>
                  <UFormField :label="t('authServers.userFilterLabel')" class="sm:col-span-2">
                    <UInput v-model="wizardForm.userFilter" class="w-full font-mono" />
                  </UFormField>
                  <UFormField :label="t('authServers.adminGroupLabel')" class="sm:col-span-2">
                    <UInput v-model="wizardForm.adminGroupDn" class="w-full font-mono" />
                  </UFormField>
                </form>
              </template>

              <template v-else>
                <UAlert v-if="wizardResult" :color="wizardResult.ok ? 'success' : 'error'" variant="subtle" :icon="wizardResult.ok ? 'i-lucide-circle-check' : 'i-lucide-circle-x'" :title="wizardResult.ok ? t('authServers.connectionSuccess') : t('authServers.connectionFailed')" :description="wizardResult.message" />
                <p v-if="wizardResult?.ok" class="text-sm text-muted">{{ t('authServers.readyToSave') }}</p>
              </template>
            </div>
          </template>
          <template #footer>
            <div class="flex w-full justify-between gap-2">
              <UButton v-if="wizardStep > 1" :label="t('authServers.previous')" color="neutral" variant="ghost" @click="previousStep" />
              <UButton v-else :label="t('common.close')" color="neutral" variant="ghost" @click="wizardOpen = false" />
              <div class="flex gap-2">
                <UButton v-if="wizardStep === 1" :label="t('authServers.continueAction')" icon="i-lucide-arrow-right" @click="startDiscovery" />
                <UButton v-if="wizardStep === 2" :label="t('common.refresh')" icon="i-lucide-refresh-cw" color="neutral" variant="outline" :loading="discoveryStatus === 'loading'" @click="discoverServers" />
                <UButton v-if="wizardStep === 3" type="submit" form="authentication-wizard-form" :label="t('authServers.testConnection')" icon="i-lucide-plug-zap" :loading="wizardTesting" />
                <UButton v-if="wizardStep === 4 && wizardResult?.ok" :label="t('common.save')" icon="i-lucide-save" :loading="wizardSaving" @click="saveWizard" />
              </div>
            </div>
          </template>
        </UModal>

        <UModal :open="toDelete !== null" :title="t('authServers.deleteTitle')" :description="toDelete ? t('authServers.deleteDescription', { name: toDelete.name }) : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
          <template #body>
            <USwitch v-model="disableLinkedUsers" :label="t('authServers.disableLinkedUsers')" />
          </template>
          <template #footer>
            <div class="flex w-full justify-end gap-2">
              <UButton :label="t('common.cancel')" color="neutral" variant="ghost" @click="toDelete = null" />
              <UButton :label="t('common.delete')" color="error" @click="remove" />
            </div>
          </template>
        </UModal>
      </div>
    </template>
  </UDashboardPanel>
</template>
