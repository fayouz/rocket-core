<script setup lang="ts">
import type { LdapConfig, LdapTestResult } from '#rocket/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
const { isSuite } = useSuite()
const { t } = useRocketI18n()
useHead({ title: () => `${t('ldap.title')} · ${appName}` })

const api = useApi()
const toast = useToast()

const { data: config } = await useAsyncData('ldap-config', () => api<LdapConfig>('/api/ldap/config'))

function toForm(c: LdapConfig | null | undefined) {
  return {
    enabled: c?.enabled ?? false,
    url: c?.url ?? 'ldap://',
    startTls: c?.startTls ?? false,
    baseDn: c?.baseDn ?? '',
    bindDn: c?.bindDn ?? '',
    bindPassword: '',
    userFilter: c?.userFilter ?? '(objectClass=inetOrgPerson)',
    adminGroupDn: c?.adminGroupDn ?? '',
    attributes: { ...(c?.attributes ?? { email: 'mail', firstName: 'givenName', lastName: 'sn', groups: 'memberOf' }) },
  }
}

const form = reactive(toForm(config.value))
watch(config, value => Object.assign(form, toForm(value)))

const saving = ref(false)
const testing = ref(false)
const syncing = ref(false)
const testResult = ref<LdapTestResult | null>(null)
const resetOpen = ref(false)

const body = () => ({ ...form, bindPassword: form.bindPassword || null })

async function save() {
  saving.value = true
  try {
    config.value = await api<LdapConfig>('/api/ldap/config', { method: 'PUT', body: body() })
    toast.add({ title: t('ldap.saveSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  catch (error) {
    toast.add({ title: t('common.saveFailed'), description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function test() {
  testing.value = true
  testResult.value = null
  try {
    testResult.value = await api<LdapTestResult>('/api/ldap/test', { method: 'POST', body: body() })
  }
  catch (error) {
    toast.add({ title: t('ldap.testFailed'), description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    testing.value = false
  }
}

async function reset() {
  try {
    config.value = await api<LdapConfig>('/api/ldap/config', { method: 'DELETE' })
    resetOpen.value = false
    testResult.value = null
    toast.add({ title: t('ldap.resetSuccess'), color: 'success' })
  }
  catch (error) {
    toast.add({ title: t('ldap.resetFailed'), description: apiErrorMessage(error), color: 'error' })
  }
}

async function sync(dryRun: boolean) {
  syncing.value = true
  try {
    const report = await api<{ created: number, updated: number, disabled: number, conflicts: string[] }>('/api/ldap/sync', { method: 'POST', query: { dryRun } })
    toast.add({
      title: dryRun ? t('ldap.syncDryRunTitle') : t('ldap.syncDoneTitle'),
      description: t('ldap.syncReport', {
        created: report.created,
        updated: report.updated,
        disabled: report.disabled,
        conflicts: report.conflicts.length ? t('ldap.syncConflicts', { n: report.conflicts.length }) : '',
      }),
      color: 'success',
    })
  }
  catch (error) {
    toast.add({ title: t('ldap.syncFailed'), description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    syncing.value = false
  }
}

const dirty = computed(() => JSON.stringify({ ...form, bindPassword: '' }) !== JSON.stringify({ ...toForm(config.value), bindPassword: '' }) || form.bindPassword !== '')
</script>

<template>
  <UDashboardPanel id="ldap">
    <template #header>
      <UDashboardNavbar :title="t('ldap.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-flask-conical" :label="t('ldap.simulateSync')" color="neutral" variant="ghost" :loading="syncing" :disabled="!config?.enabled || dirty" @click="sync(true)" />
          <UButton icon="i-lucide-refresh-cw" :label="t('ldap.sync')" color="neutral" variant="outline" :loading="syncing" :disabled="!config?.enabled || dirty" @click="sync(false)" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="isSuite"
        icon="i-lucide-shield-check"
        color="info"
        variant="subtle"
        :title="t('ldap.suiteManagedTitle')"
        :description="t('ldap.suiteManagedDescription')"
        class="mx-auto w-full max-w-4xl"
      />
      <form class="mx-auto flex w-full max-w-4xl flex-col gap-6" data-testid="ldap-form" @submit.prevent="save">
        <UAlert
          v-if="config?.source === 'environment'"
          color="info"
          variant="subtle"
          icon="i-lucide-file-cog"
          :title="t('ldap.envDefaultsTitle')"
          :description="t('ldap.envDefaultsDescription')"
          data-testid="ldap-source"
        />

        <UPageCard :title="t('ldap.connectionTitle')" :description="t('ldap.connectionDescription')">
          <USwitch v-model="form.enabled" :label="t('ldap.enableSwitch')" />
          <div class="grid gap-3 sm:grid-cols-3">
            <UFormField :label="t('ldap.urlLabel')" required :hint="t('ldap.urlHint')" class="sm:col-span-2">
              <UInput v-model="form.url" :placeholder="t('ldap.urlPlaceholder')" class="w-full font-mono" />
            </UFormField>
            <UFormField :label="t('ldap.encryptionLabel')" :help="t('ldap.encryptionHelp')">
              <USwitch v-model="form.startTls" :label="t('ldap.startTls')" :disabled="form.url.startsWith('ldaps://')" />
            </UFormField>
            <UFormField :label="t('ldap.baseDnLabel')" required class="sm:col-span-3">
              <UInput v-model="form.baseDn" :placeholder="t('ldap.baseDnPlaceholder')" class="w-full font-mono" />
            </UFormField>
            <UFormField :label="t('ldap.serviceAccountLabel')" class="sm:col-span-2">
              <UInput v-model="form.bindDn" :placeholder="t('ldap.serviceAccountPlaceholder')" class="w-full font-mono" autocomplete="off" />
            </UFormField>
            <UFormField :label="t('common.password')" :hint="config?.hasBindPassword ? t('ldap.passwordUnchangedHint') : undefined">
              <UInput v-model="form.bindPassword" type="password" autocomplete="new-password" class="w-full" />
            </UFormField>
          </div>
        </UPageCard>

        <UPageCard :title="t('ldap.usersTitle')" :description="t('ldap.usersDescription')">
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField :label="t('ldap.filterLabel')" class="sm:col-span-2" :help="t('ldap.filterHelp')">
              <UInput v-model="form.userFilter" class="w-full font-mono" />
            </UFormField>
            <UFormField :label="t('ldap.adminGroupLabel')" class="sm:col-span-2" :help="t('ldap.adminGroupHelp')">
              <UInput v-model="form.adminGroupDn" :placeholder="t('ldap.adminGroupPlaceholder')" class="w-full font-mono" />
            </UFormField>
            <UFormField :label="t('ldap.emailAttrLabel')">
              <UInput v-model="form.attributes.email" :placeholder="config?.defaults.attributes.email" class="w-full font-mono" />
            </UFormField>
            <UFormField :label="t('ldap.groupsAttrLabel')">
              <UInput v-model="form.attributes.groups" :placeholder="config?.defaults.attributes.groups" class="w-full font-mono" />
            </UFormField>
            <UFormField :label="t('ldap.firstNameAttrLabel')">
              <UInput v-model="form.attributes.firstName" :placeholder="config?.defaults.attributes.firstName" class="w-full font-mono" />
            </UFormField>
            <UFormField :label="t('ldap.lastNameAttrLabel')">
              <UInput v-model="form.attributes.lastName" :placeholder="config?.defaults.attributes.lastName" class="w-full font-mono" />
            </UFormField>
          </div>
        </UPageCard>

        <div class="flex flex-wrap items-center gap-2">
          <UButton type="submit" :label="t('common.save')" icon="i-lucide-save" :loading="saving" />
          <UButton :label="t('common.test')" icon="i-lucide-plug-zap" color="neutral" variant="outline" :loading="testing" data-testid="ldap-test" @click="test" />
          <UButton
            v-if="config?.source === 'database'"
            :label="t('ldap.resetButton')"
            icon="i-lucide-rotate-ccw"
            color="neutral"
            variant="ghost"
            class="ms-auto"
            @click="resetOpen = true"
          />
        </div>

        <UAlert
          v-if="testResult"
          :color="testResult.ok ? 'success' : 'error'"
          variant="subtle"
          :icon="testResult.ok ? 'i-lucide-circle-check' : 'i-lucide-circle-x'"
          :title="testResult.ok ? t('ldap.testSuccess') : t('ldap.testFailedTitle')"
          :description="testResult.message"
          data-testid="ldap-test-result"
        />
        <UCard v-if="testResult?.sample.length" :ui="{ body: 'p-0 sm:p-0' }">
          <template #header>
            <p class="text-sm font-medium">
              {{ t('ldap.previewTitle') }}
            </p>
          </template>
          <ul class="divide-y divide-default text-sm">
            <li v-for="user in testResult.sample" :key="user.dn" class="flex items-center gap-3 px-4 py-2">
              <div class="min-w-0 flex-1">
                <p class="font-medium">
                  {{ [user.firstName, user.lastName].filter(Boolean).join(' ') || user.email }}
                  <UBadge v-if="user.admin" :label="t('ldap.adminBadge')" color="warning" variant="subtle" size="sm" class="ms-1" />
                </p>
                <p class="truncate text-xs text-muted">
                  {{ user.email }} · <span class="font-mono">{{ user.dn }}</span>
                </p>
              </div>
            </li>
          </ul>
        </UCard>
      </form>

      <UModal v-model:open="resetOpen" :title="t('ldap.resetModalTitle')" :description="t('ldap.resetModalDescription')">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton :label="t('common.cancel')" color="neutral" variant="ghost" @click="resetOpen = false" />
            <UButton :label="t('ldap.resetConfirm')" color="warning" @click="reset" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
