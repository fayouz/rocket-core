<script setup lang="ts">
import type { AppVersionInfo, UpdateMethod, UpdateRun } from '#rocket/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
const { t } = useRocketI18n()
useHead({ title: () => `${t('updates.title')} · ${appName}` })

const config = useRuntimeConfig()
const api = useApi()
const toast = useToast()
const { update, frontVersion, fetchUpdate } = useAppVersion()

const checking = ref(false)
const savingMethod = ref(false)
const confirmOpen = ref(false)
/** Docker: waiting for the new version to answer. */
const waitingVersion = ref(false)
const stalled = ref(false)
let poll: ReturnType<typeof setInterval> | undefined

// Checks against GitHub once per page load (cached an hour by the API); "Vérifier maintenant" forces it.
await useAsyncData('update-status', () => fetchUpdate())

const current = computed(() => update.value?.current)
const latest = computed(() => update.value?.latest ?? null)
const run = computed(() => update.value?.run ?? null)
const method = computed(() => update.value?.method ?? 'manual')
const versionsDiffer = computed(() => !!current.value && current.value.version !== frontVersion.value && frontVersion.value !== 'dev')
const running = computed(() => waitingVersion.value || (!!run.value && ['requested', 'running', 'started'].includes(run.value.status)))
const canStart = computed(() => {
  const methods = update.value?.methods
  if (!methods || running.value) return false
  if (method.value === 'docker') return methods.docker.configured
  if (method.value === 'script') return methods.script.installed
  return false
})

const METHOD_LABELS = computed<Record<UpdateMethod, string>>(() => ({
  docker: t('updates.methodDocker'),
  script: t('updates.methodScript'),
  manual: t('updates.methodManual'),
}))
const methodItems = computed(() => (['docker', 'script', 'manual'] as const).map(value => ({
  value,
  label: METHOD_LABELS.value[value] + (update.value?.defaultMethod === value ? t('updates.methodDefaultSuffix') : ''),
  description: {
    docker: t('updates.methodDockerDescription'),
    script: t('updates.methodScriptDescription'),
    manual: t('updates.methodManualDescription'),
  }[value],
})))

const RUN_STATUS = computed<Record<UpdateRun['status'], { label: string, color: 'neutral' | 'info' | 'success' | 'error' | 'warning' }>>(() => ({
  requested: { label: t('updates.statusRequested'), color: 'info' },
  running: { label: t('updates.statusRunning'), color: 'info' },
  started: { label: t('updates.statusStarted'), color: 'info' },
  succeeded: { label: t('updates.statusSucceeded'), color: 'success' },
  failed: { label: t('updates.statusFailed'), color: 'error' },
  cancelled: { label: t('updates.statusCancelled'), color: 'neutral' },
}))

const cronLine = computed(() => `* * * * * cd ${(update.value?.methods.script.script ?? '/srv/rocket-print/deploy/update.sh').replace(/\/deploy\/[^/]+$/, '')}/backend && php bin/console app:update:run`)

async function check() {
  checking.value = true
  try {
    await fetchUpdate(true)
  }
  catch (error) {
    toast.add({ title: t('updates.checkFailed'), description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    checking.value = false
  }
}

async function setMethod(value: UpdateMethod | null) {
  savingMethod.value = true
  try {
    await api('/api/system/update/method', { method: 'PUT', body: { method: value } })
    await fetchUpdate()
  }
  catch (error) {
    toast.add({ title: t('updates.saveFailed'), description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    savingMethod.value = false
  }
}

function reloadOn(version: string) {
  clearInterval(poll)
  toast.add({ title: t('updates.installedToast', { name: appName, version }), color: 'success', icon: 'i-lucide-party-popper' })
  setTimeout(() => window.location.reload(), 1500)
}

/** Docker: the API and the interface restart; wait for the new version to answer, then reload the page. */
function waitForNewVersion(before: string | undefined) {
  waitingVersion.value = true
  stalled.value = false
  const since = Date.now()
  clearInterval(poll)
  poll = setInterval(async () => {
    try {
      const { version } = await api<AppVersionInfo>('/api/system/version')
      if (version !== before) return reloadOn(version)
    }
    catch {
      // Restarting.
    }
    if (Date.now() - since > 5 * 60_000) stalled.value = true
  }, 5000)
}

/** Script: follow the run (status and log) until it ends. */
function followRun() {
  clearInterval(poll)
  poll = setInterval(async () => {
    try {
      const status = await fetchUpdate()
      if (!status.run || ['requested', 'running'].includes(status.run.status)) return
      clearInterval(poll)
      if (status.run.status === 'succeeded') reloadOn(status.current.version)
    }
    catch {
      // The API may restart at the end of the script.
    }
  }, 3000)
}

async function start() {
  confirmOpen.value = false
  const before = current.value?.version
  try {
    await api('/api/system/update', { method: 'POST' })
  }
  catch (error) {
    toast.add({ title: t('updates.updateFailed'), description: apiErrorMessage(error), color: 'error' })
    return
  }
  if (method.value === 'docker') {
    waitForNewVersion(before)
  }
  else {
    await fetchUpdate()
    followRun()
  }
}

async function cancel() {
  try {
    await api('/api/system/update/cancel', { method: 'POST' })
    clearInterval(poll)
    await fetchUpdate()
  }
  catch (error) {
    toast.add({ title: t('updates.cancelFailed'), description: apiErrorMessage(error), color: 'error' })
  }
}

// Coming back to the page while an update runs.
onMounted(() => {
  if (run.value?.status === 'requested' || run.value?.status === 'running') followRun()
  else if (run.value?.status === 'started') waitForNewVersion(run.value.fromVersion)
})
onBeforeUnmount(() => clearInterval(poll))

const logBox = ref<HTMLElement | null>(null)
watch(() => run.value?.log, () => nextTick(() => {
  if (logBox.value) logBox.value.scrollTop = logBox.value.scrollHeight
}))
</script>

<template>
  <UDashboardPanel id="updates">
    <template #header>
      <UDashboardNavbar :title="t('updates.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            :label="t('updates.checkNow')"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="outline"
            :loading="checking"
            :disabled="!update?.checkEnabled"
            data-testid="check-updates"
            @click="check"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="update" class="flex max-w-3xl flex-col gap-6">
        <UPageCard :title="t('updates.installedTitle')" variant="subtle">
          <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p class="text-3xl font-semibold" data-testid="installed-version">
                {{ current?.version }}
              </p>
              <p v-if="versionsDiffer" class="text-sm text-muted">
                {{ t('updates.interface', { version: frontVersion }) }}
              </p>
            </div>
            <UButton :label="t('updates.changelog')" icon="i-lucide-scroll-text" color="neutral" variant="link" :to="config.public.changelogUrl" target="_blank" />
          </div>
          <p v-if="current?.release === null" class="text-sm text-muted">
            {{ t('updates.devBuild') }}
          </p>
        </UPageCard>

        <UAlert v-if="update.error" color="warning" variant="subtle" icon="i-lucide-cloud-off" :title="t('updates.checkFailed')" :description="update.error" />
        <UAlert
          v-else-if="!update.checkEnabled"
          color="neutral"
          variant="subtle"
          icon="i-lucide-bell-off"
          :title="t('updates.checkDisabledTitle')"
          :description="t('updates.checkDisabledDescription', { name: appName })"
        />
        <UAlert
          v-else-if="!latest"
          color="neutral"
          variant="subtle"
          icon="i-lucide-package-search"
          :title="t('updates.noReleaseTitle')"
          :description="t('updates.noReleaseDescription', { url: update.repositoryUrl })"
        />

        <UPageCard
          v-if="latest"
          :title="update.updateAvailable ? t('updates.releaseAvailable', { name: appName, version: latest.version }) : update.updateAvailable === false ? t('updates.upToDate', { name: appName }) : t('updates.latestPublished', { version: latest.version })"
          :description="latest.name !== latest.version && latest.name !== `v${latest.version}` ? latest.name : undefined"
          :icon="update.updateAvailable ? 'i-lucide-sparkles' : 'i-lucide-circle-check'"
          :variant="update.updateAvailable ? 'soft' : 'subtle'"
          data-testid="latest-release"
        >
          <p class="text-sm text-muted">
            {{ latest.publishedAt ? t('updates.releasedOn', { version: latest.version, date: formatDate(latest.publishedAt) }) : t('updates.releaseNoDate', { version: latest.version }) }}
            <ULink :to="latest.url" target="_blank" class="text-primary">
              {{ t('updates.viewRelease') }}
            </ULink>
          </p>
          <div v-if="latest.notes && update.updateAvailable" class="max-h-80 overflow-auto whitespace-pre-wrap rounded-md border border-default bg-default p-3 text-sm">
            {{ latest.notes }}
          </div>
        </UPageCard>

        <UPageCard :title="t('updates.methodTitle')" :description="t('updates.methodDescription', { name: appName })" icon="i-lucide-settings-2" variant="subtle">
          <URadioGroup
            :model-value="method"
            :items="methodItems"
            :disabled="savingMethod || running"
            variant="table"
            data-testid="update-method"
            @update:model-value="(value) => setMethod(value as UpdateMethod)"
          />
          <div v-if="update.methodSource === 'database' && update.defaultMethod !== method">
            <UButton
              :label="t('updates.backToDefaultMethod', { method: METHOD_LABELS[update.defaultMethod] })"
              color="neutral"
              variant="link"
              size="sm"
              class="px-0"
              @click="setMethod(null)"
            />
          </div>
        </UPageCard>

        <!-- Docker -->
        <UPageCard v-if="method === 'docker'" :title="t('updates.dockerTitle')" icon="i-lucide-container" variant="subtle" data-testid="update-docker">
          <template v-if="update.methods.docker.configured">
            <p class="text-sm text-muted">
              {{ t('updates.dockerConfigured') }}
            </p>
          </template>
          <template v-else>
            <UAlert
              color="warning"
              variant="subtle"
              icon="i-lucide-plug-zap"
              :title="t('updates.dockerNotConfiguredTitle')"
              :description="t('updates.dockerNotConfiguredDescription')"
            />
          </template>
          <UAlert
            v-if="waitingVersion || run?.status === 'started'"
            :color="stalled ? 'warning' : 'info'"
            variant="subtle"
            :icon="stalled ? 'i-lucide-triangle-alert' : 'i-lucide-loader-circle'"
            :ui="{ icon: stalled ? '' : 'animate-spin' }"
            :title="stalled ? t('updates.dockerStalledTitle') : t('updates.dockerInProgressTitle')"
            :description="stalled
              ? t('updates.dockerStalledDescription')
              : t('updates.dockerInProgressDescription', { name: appName })"
            data-testid="update-progress"
          />
        </UPageCard>

        <!-- Server without Docker -->
        <UPageCard v-else-if="method === 'script'" :title="t('updates.scriptTitle')" icon="i-lucide-server" variant="subtle" data-testid="update-script">
          <p class="text-sm text-muted">
            {{ t('updates.scriptInstallDescription', { target: latest ? t('updates.scriptInstallTargetVersion', { version: latest.version }) : t('updates.scriptInstallTargetLatest'), script: update.methods.script.script }) }}
          </p>
          <UAlert
            v-if="!update.methods.script.installed"
            color="error"
            variant="subtle"
            icon="i-lucide-file-x"
            :title="t('updates.scriptMissingTitle')"
            :description="t('updates.scriptMissingDescription', { script: update.methods.script.script })"
          />
          <UAlert
            v-if="!update.methods.script.schedulerAlive"
            color="warning"
            variant="subtle"
            icon="i-lucide-clock-alert"
            :title="update.methods.script.heartbeatAt ? t('updates.schedulerDeadTitle', { time: timeAgo(update.methods.script.heartbeatAt) }) : t('updates.schedulerNeverRanTitle')"
            data-testid="scheduler-warning"
          >
            <template #description>
              <p>{{ t('updates.schedulerCrontabIntro') }}</p>
              <pre class="mt-2 overflow-x-auto rounded-md bg-elevated p-2 text-xs"><code>{{ cronLine }}</code></pre>
            </template>
          </UAlert>
          <p v-else class="text-xs text-muted">
            {{ t('updates.schedulerActive', { time: timeAgo(update.methods.script.heartbeatAt) }) }}
          </p>

          <div v-if="run && run.method === 'script' && run.log !== undefined && run.status !== 'cancelled'" class="flex flex-col gap-2" data-testid="update-run">
            <div class="flex items-center gap-2 text-sm">
              <UBadge :label="RUN_STATUS[run.status].label" :color="RUN_STATUS[run.status].color" variant="subtle" />
              <span class="text-muted">{{ t('updates.runTarget', { from: run.fromVersion, target: run.target ?? t('updates.latestVersionFallback') }) }}</span>
              <UButton v-if="run.status === 'requested'" :label="t('updates.cancel')" size="xs" color="neutral" variant="link" data-testid="cancel-update" @click="cancel" />
            </div>
            <pre v-if="run.log" ref="logBox" class="max-h-80 overflow-auto rounded-md bg-elevated p-3 text-xs" data-testid="update-log">{{ run.log }}</pre>
            <p v-else-if="run.status === 'requested'" class="text-sm text-muted">
              {{ t('updates.runQueued') }}
            </p>
          </div>
        </UPageCard>

        <!-- Manual -->
        <UPageCard v-else :title="t('updates.manualTitle')" :description="t('updates.manualDescription', { name: appName })" icon="i-lucide-terminal" variant="subtle" data-testid="manual-update">
          <p class="text-sm font-medium">
            {{ t('updates.manualWithDocker') }}
          </p>
          <pre class="overflow-x-auto rounded-md bg-elevated p-3 text-sm"><code>docker compose pull
docker compose up -d</code></pre>
          <p class="text-sm font-medium">
            {{ t('updates.manualWithoutDocker') }}
          </p>
          <pre class="overflow-x-auto rounded-md bg-elevated p-3 text-sm"><code>TARGET_VERSION={{ latest?.tag ?? 'v0.7.0' }} deploy/update.sh</code></pre>
        </UPageCard>

        <div v-if="method !== 'manual'">
          <UButton
            :label="t('updates.startUpdate')"
            icon="i-lucide-download"
            size="lg"
            :color="update.updateAvailable ? 'primary' : 'neutral'"
            :variant="update.updateAvailable ? 'solid' : 'outline'"
            :loading="running"
            :disabled="!canStart"
            data-testid="start-update"
            @click="confirmOpen = true"
          />
        </div>

        <UPageCard v-if="update.history.length" :title="t('updates.historyTitle')" variant="subtle">
          <ul class="divide-y divide-default text-sm" data-testid="update-history">
            <li v-for="item in update.history" :key="item.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
              <UBadge :label="RUN_STATUS[item.status].label" :color="RUN_STATUS[item.status].color" variant="subtle" size="sm" />
              <span>{{ t('updates.runTarget', { from: item.fromVersion, target: item.target ?? t('updates.latestVersionFallback') }) }}</span>
              <span class="text-muted">{{ METHOD_LABELS[item.method] }}</span>
              <span class="ms-auto text-xs text-muted">{{ formatDate(item.requestedAt) }}{{ item.requestedBy ? ` · ${item.requestedBy}` : '' }}</span>
            </li>
          </ul>
        </UPageCard>
      </div>

      <UModal
        v-model:open="confirmOpen"
        :title="t('updates.confirmTitle', { name: appName })"
        :description="latest && update?.updateAvailable ? t('updates.confirmDescriptionAvailable', { version: latest.version }) : t('updates.confirmDescriptionLatest')"
      >
        <template #body>
          <ul class="list-disc space-y-1 pl-5 text-sm text-muted">
            <li>{{ t('updates.confirmRestart', { name: appName }) }}</li>
            <li>{{ t('updates.confirmEmails') }}</li>
            <li>{{ t('updates.confirmMigrations') }}</li>
            <li v-if="method === 'script'">
              {{ t('updates.confirmScriptRollback') }}
            </li>
          </ul>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton :label="t('common.cancel')" color="neutral" variant="ghost" @click="confirmOpen = false" />
            <UButton :label="t('updates.startUpdate')" icon="i-lucide-download" data-testid="confirm-update" @click="start" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
