<script setup lang="ts">
import type { VaultSecret, VaultSecrets } from '#rocket/types/api'

/**
 * For the connector forms of the bricks: the name of a secret of the vault (v-model), chosen among the existing ones
 * or created on the spot. The brick stores the name and reads the value server side (SecretVault::get()).
 *
 *     <UFormField label="Clé d'API Lodgify"><SecretField v-model="form.apiKeySecret" default-name="lodgify.api_key" /></UFormField>
 */
const props = defineProps<{ scope?: string | null, defaultName?: string, placeholder?: string }>()
const model = defineModel<string | null>({ default: null })

const api = useApi()
const { data, refresh, status } = useAsyncData(`secrets-field-${props.scope ?? ''}`, () => api<VaultSecrets>('/api/secrets', { query: props.scope ? { scope: props.scope } : {} }))
const items = computed(() => (data.value?.secrets ?? []).map(secret => ({ label: secret.name, value: secret.name, suffix: secret.masked })))
const creating = ref(false)
const selected = computed({ get: () => model.value ?? undefined, set: (value?: string) => (model.value = value ?? null) })

async function created(secret: VaultSecret) {
  creating.value = false
  await refresh()
  model.value = secret.name
}
</script>

<template>
  <div class="flex w-full flex-col gap-2" data-testid="secret-field">
    <UAlert v-if="data && !data.configured" color="error" variant="subtle" icon="i-lucide-key-round" title="Coffre des secrets indisponible" :description="data.error ?? ''" />
    <div class="flex gap-2">
      <USelectMenu
        v-model="selected"
        :items="items"
        value-key="value"
        :loading="status === 'pending'"
        :placeholder="placeholder ?? 'Choisir un secret du coffre'"
        class="min-w-0 flex-1 font-mono"
      >
        <template #item-trailing="{ item }">
          <span class="font-mono text-xs text-muted">{{ item.suffix }}</span>
        </template>
      </USelectMenu>
      <UButton icon="i-lucide-plus" label="Nouveau" color="neutral" variant="outline" :disabled="data ? !data.configured : false" @click="creating = true" />
    </div>
    <UModal v-model:open="creating" title="Nouveau secret">
      <template #body>
        <SecretForm :scope="scope" :default-name="defaultName" @saved="created" @cancel="creating = false" />
      </template>
    </UModal>
  </div>
</template>
