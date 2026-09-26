<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Application } from '#rocket/types/api'
import type { RocketApplicationFormExtension } from '#rocket/types/extensions'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
// Pages the applications may embed (iframe on /embed/…): only for the bricks that have some.
const embed = useAppConfig().rocket.embed
// Suite mode: other bricks call with an access token of Rocket Auth, linked here by their OAuth client ID.
const { isSuite, load: loadSuite } = useSuite()
await loadSuite()
useHead({ title: `Applications · ${appName}` })

const api = useApi()
const toast = useToast()
const config = useRuntimeConfig()
const requestUrl = useRequestURL()
const UBadge = resolveComponent('UBadge')
const USwitch = resolveComponent('USwitch')
const UButton = resolveComponent('UButton')

// Extension points of the brick (app.config.ts, rocket.extensions.applications): see the README.
const extensions = useRocketExtensions('applications')
const rowActions = resolveRocketComponents(extensions.rowActions)
const formSections = resolveRocketComponents(extensions.formSections)
const tokenExample = resolveRocketComponent(extensions.tokenExample)
const help = extensions.help || "Une application s'authentifie avec son jeton (Authorization: Bearer <jeton>). Si l'impersonation est autorisée, l'en-tête X-Impersonate-User lui permet d'agir en tant qu'un utilisateur, jamais avec le rôle administrateur. Sans impersonation, elle peut seulement s'identifier (GET /api/me)."

const { data: applications, status, refresh } = await useAsyncData('applications', () => api<Application[]>('/api/applications'), { default: () => [] })

async function patch(application: Application, body: Partial<Application>) {
  try {
    Object.assign(application, await api<Application>(`/api/applications/${application.id}`, { method: 'PATCH', body }))
    return true
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

const columns: TableColumn<Application>[] = [
  {
    accessorKey: 'name',
    header: 'Application',
    cell: ({ row }) => h('div', [
      h('p', { class: 'font-medium' }, row.original.name),
      h('p', { class: 'font-mono text-xs text-muted' }, `${row.original.tokenHint}…`),
    ]),
  },
  ...(isSuite.value ? [{ accessorKey: 'oauthClientId', header: 'Client Rocket Auth', cell: ({ row }) => row.original.oauthClientId ? h('span', { class: 'font-mono text-sm' }, row.original.oauthClientId) : '—' }] as TableColumn<Application>[] : []),
  {
    accessorKey: 'canImpersonate',
    header: 'Impersonation',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', color: row.original.canImpersonate ? 'warning' : 'neutral', label: row.original.canImpersonate ? 'Autorisée' : 'Non' }),
  },
  ...(embed ? [{ accessorKey: 'allowedOrigins', header: 'Origines (embed)', cell: ({ row }) => row.original.allowedOrigins.join(', ') || '—' }] as TableColumn<Application>[] : []),
  ...resolveRocketColumns<Application>(extensions.columns, 'application'),
  { accessorKey: 'lastUsedAt', header: 'Dernier appel', cell: ({ row }) => formatDate(row.original.lastUsedAt) },
  {
    accessorKey: 'enabled',
    header: 'Active',
    cell: ({ row }) => h(USwitch, { 'modelValue': row.original.enabled, 'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      ...rowActions.map(action => h(action, { application: row.original, onRefresh: () => refresh() })),
      h(UButton, { icon: 'i-lucide-pencil', color: 'neutral', variant: 'ghost', 'aria-label': 'Modifier', onClick: () => edit(row.original) }),
      h(UButton, { icon: 'i-lucide-rotate-cw', color: 'neutral', variant: 'ghost', 'aria-label': 'Régénérer le jeton', onClick: () => (toRotate.value = row.original) }),
      h(UButton, { icon: 'i-lucide-trash-2', color: 'error', variant: 'ghost', 'aria-label': 'Supprimer', onClick: () => (toDelete.value = row.original) }),
    ]),
  },
]

// Create / edit
const formOpen = ref(false)
const editing = ref<Application | null>(null)
const form = reactive({ name: '', description: '', canImpersonate: true, allowedOrigins: [] as string[], oauthClientId: '' })

function create() {
  editing.value = null
  Object.assign(form, { name: '', description: '', canImpersonate: true, allowedOrigins: [], oauthClientId: '' })
  formOpen.value = true
}

// "Nouvelle application" from the dashboard.
onMounted(() => {
  if (useRoute().query.new) create()
})

function edit(application: Application) {
  editing.value = application
  Object.assign(form, {
    name: application.name,
    description: application.description ?? '',
    canImpersonate: application.canImpersonate,
    allowedOrigins: [...application.allowedOrigins],
    oauthClientId: application.oauthClientId ?? '',
  })
  formOpen.value = true
}

// Form sections of the brick: saved after the application itself.
const formExtensions = useTemplateRef<(RocketApplicationFormExtension | null)[]>('formExtensions')
async function saveExtensions(application: Application): Promise<boolean> {
  try {
    for (const section of formExtensions.value ?? []) await section?.save?.(application)
    return true
  }
  catch (error) {
    toast.add({ title: 'Enregistrement incomplet', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

async function submit() {
  const body = { ...form, description: form.description || null, oauthClientId: form.oauthClientId.trim() || null }
  if (editing.value) {
    if (await patch(editing.value, body) && await saveExtensions(editing.value)) formOpen.value = false
    return
  }
  let created: Application
  try {
    created = await api<Application>('/api/applications', { method: 'POST', body })
  }
  catch (error) {
    toast.add({ title: 'Création impossible', description: apiErrorMessage(error), color: 'error' })
    return
  }
  // Created: its token is shown once, even if a section of the brick still has to be fixed (Modifier).
  await saveExtensions(created)
  formOpen.value = false
  revealed.value = { application: created, token: created.plainToken! }
  await refresh()
}

// Secret shown once
const revealed = ref<{ application: Application, token: string } | null>(null)
const toRotate = ref<Application | null>(null)
const toDelete = ref<Application | null>(null)

async function rotate() {
  const application = toRotate.value!
  toRotate.value = null
  try {
    const { token } = await api<{ token: string }>(`/api/applications/${application.id}/regenerate-token`, { method: 'POST' })
    revealed.value = { application, token }
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Régénération impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove() {
  const application = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/applications/${application.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function copy(text: string) {
  await navigator.clipboard.writeText(text)
  toast.add({ title: 'Copié', color: 'success', duration: 1500 })
}

const snippet = computed(() => revealed.value && `# Server side only: never expose the application token to a browser.
curl ${config.public.apiBase || requestUrl.origin}/api/me \
  -H "Authorization: Bearer ${revealed.value.token}" \
  -H "X-Impersonate-User: jean.dupont@example.org"`)
</script>

<template>
  <UDashboardPanel id="applications">
    <template #header>
      <UDashboardNavbar title="Applications externes">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouvelle application" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        icon="i-lucide-info"
        variant="subtle"
        color="neutral"
        title="Comment ça marche"
        :description="help"
      />
      <UAlert
        v-if="isSuite"
        icon="i-lucide-link"
        variant="subtle"
        color="neutral"
        title="Briques de la suite"
        description="Les autres briques de la suite Rocket appellent l'API avec un jeton d'accès de Rocket Auth plutôt qu'avec un jeton statique. Déclarez-les comme applications et renseignez leur client Rocket Auth (ex. rocket-cloud) : vous décidez ainsi qui peut appeler cette brique, et si elle peut agir en tant qu'utilisateur."
      />
      <UTable :data="applications" :columns="columns" :loading="status === 'pending'" empty="Aucune application." />

      <UModal v-model:open="formOpen" :title="editing ? `Modifier ${editing.name}` : 'Nouvelle application'">
        <template #body>
          <form id="application-form" class="flex flex-col gap-3" @submit.prevent="submit">
            <UFormField label="Nom" required>
              <UInput v-model="form.name" class="w-full" />
            </UFormField>
            <UFormField label="Description">
              <UTextarea v-model="form.description" class="w-full" :rows="2" />
            </UFormField>
            <USwitch v-model="form.canImpersonate" :label="embed ? 'Peut agir en tant qu\'utilisateur (impersonation + embed)' : 'Peut agir en tant qu\'utilisateur (impersonation)'" />
            <UFormField v-if="embed" label="Origines autorisées à embarquer les pages" hint="ex. https://crm.exemple.com">
              <UInputTags v-model="form.allowedOrigins" add-on-blur add-on-paste class="w-full" />
            </UFormField>
            <UFormField
              v-if="isSuite || form.oauthClientId"
              label="Client Rocket Auth"
              hint="ex. rocket-cloud"
              help="Une brique de la suite enregistrée sous ce client ID dans Rocket Auth appelle l'API avec un jeton d'accès de Rocket Auth (client credentials), sans jeton statique. Elle obtient les droits de cette application."
            >
              <UInput v-model="form.oauthClientId" class="w-full font-mono" placeholder="rocket-…" />
            </UFormField>
            <component :is="section" v-for="(section, index) in formSections" :key="index" ref="formExtensions" :application="editing" />
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="formOpen = false" />
            <UButton type="submit" form="application-form" :label="editing ? 'Enregistrer' : 'Créer'" />
          </div>
        </template>
      </UModal>

      <UModal :open="revealed !== null" title="Jeton de l'application" :dismissible="false" :ui="{ content: 'max-w-2xl' }" @update:open="(value: boolean) => { if (!value) revealed = null }">
        <template #body>
          <div v-if="revealed" class="flex flex-col gap-4">
            <UAlert color="warning" variant="subtle" icon="i-lucide-triangle-alert" description="Copiez ce jeton maintenant : il ne sera plus jamais affiché. Stockez-le côté serveur uniquement." />
            <div class="flex items-center gap-2">
              <code class="min-w-0 flex-1 break-all rounded bg-elevated p-2 text-sm">{{ revealed.token }}</code>
              <UButton icon="i-lucide-copy" color="neutral" variant="outline" aria-label="Copier" @click="copy(revealed.token)" />
            </div>
            <component :is="tokenExample" v-if="tokenExample" :application="revealed.application" :token="revealed.token" />
            <div v-else>
              <p class="mb-1 text-sm font-medium">
                Exemple d'appel
              </p>
              <pre class="max-h-72 overflow-auto rounded bg-elevated p-3 text-xs">{{ snippet }}</pre>
            </div>
          </div>
        </template>
        <template #footer>
          <div class="flex w-full justify-end">
            <UButton label="J'ai copié le jeton" @click="revealed = null" />
          </div>
        </template>
      </UModal>

      <UModal :open="toRotate !== null" title="Régénérer le jeton ?" description="L'ancien jeton cessera immédiatement de fonctionner." @update:open="(value: boolean) => { if (!value) toRotate = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toRotate = null" />
            <UButton label="Régénérer" color="warning" @click="rotate" />
          </div>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" title="Supprimer l'application ?" :description="toDelete ? `« ${toDelete.name} » ne pourra plus accéder à l'API.` : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toDelete = null" />
            <UButton label="Supprimer" color="error" @click="remove" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
