<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { VaultSecret, VaultSecrets } from '#rocket/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
useHead({ title: `Secrets · ${appName}` })

const api = useApi()
const toast = useToast()
const UButton = resolveComponent('UButton')

const { data, status, refresh } = await useAsyncData('secrets', () => api<VaultSecrets>('/api/secrets'))
const secrets = computed(() => data.value?.secrets ?? [])

const formOpen = ref(false)
const editing = ref<VaultSecret | null>(null)
const toDelete = ref<VaultSecret | null>(null)

function create() {
  editing.value = null
  formOpen.value = true
}

function replace(secret: VaultSecret) {
  editing.value = secret
  formOpen.value = true
}

async function saved() {
  formOpen.value = false
  await refresh()
}

async function remove() {
  const secret = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/secrets/${secret.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const columns: TableColumn<VaultSecret>[] = [
  { accessorKey: 'name', header: 'Nom', cell: ({ row }) => h('span', { class: 'font-mono text-sm font-medium' }, row.original.name) },
  { accessorKey: 'masked', header: 'Valeur', cell: ({ row }) => h('span', { class: 'font-mono text-sm text-muted' }, row.original.masked) },
  { accessorKey: 'updatedAt', header: 'Modifié', cell: ({ row }) => h('div', [h('p', formatDate(row.original.updatedAt)), h('p', { class: 'text-xs text-muted' }, row.original.updatedBy ?? '')]) },
  { accessorKey: 'lastUsedAt', header: 'Dernière lecture', cell: ({ row }) => timeAgo(row.original.lastUsedAt) },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { 'icon': 'i-lucide-pencil', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Remplacer la valeur', 'disabled': !data.value?.configured, 'onClick': () => replace(row.original) }),
      h(UButton, { 'icon': 'i-lucide-trash-2', 'color': 'error', 'variant': 'ghost', 'aria-label': 'Supprimer', 'onClick': () => (toDelete.value = row.original) }),
    ]),
  },
]
</script>

<template>
  <UDashboardPanel id="secrets">
    <template #header>
      <UDashboardNavbar title="Secrets">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouveau secret" :disabled="!data?.configured" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="data && !data.configured"
        icon="i-lucide-key-round"
        color="error"
        variant="subtle"
        title="Clé maîtresse absente ou invalide"
        :description="data.error ?? ''"
      />
      <UAlert
        icon="i-lucide-info"
        variant="subtle"
        color="neutral"
        title="Coffre des secrets"
        description="Clés d'API, jetons et mots de passe des intégrations, chiffrés en base (XChaCha20-Poly1305) avec la clé ROCKET_SECRETS_KEY du serveur, au lieu du fichier .env. Une valeur enregistrée n'est plus jamais affichée : on peut seulement la remplacer ou la supprimer."
      />
      <UTable :data="secrets" :columns="columns" :loading="status === 'pending'" empty="Aucun secret." />

      <UModal v-model:open="formOpen" :title="editing ? `Remplacer ${editing.name}` : 'Nouveau secret'">
        <template #body>
          <SecretForm :key="editing?.id ?? 'new'" :secret="editing" @saved="saved" @cancel="formOpen = false" />
        </template>
      </UModal>

      <UModal :open="toDelete !== null" title="Supprimer le secret ?" :description="toDelete ? `Les intégrations qui lisent « ${toDelete.name} » ne fonctionneront plus.` : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
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
