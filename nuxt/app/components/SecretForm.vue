<script setup lang="ts">
import type { VaultSecret } from '#rocket/types/api'

/** Creates a secret of the vault, or replaces the value of an existing one (the value is write-only). */
const props = defineProps<{ secret?: VaultSecret | null, scope?: string | null, defaultName?: string }>()
const emit = defineEmits<{ saved: [secret: VaultSecret], cancel: [] }>()

const api = useApi()
const toast = useToast()
const name = ref(props.secret?.name ?? props.defaultName ?? '')
const value = ref('')
const reveal = ref(false)
const saving = ref(false)
const validName = computed(() => /^[A-Za-z0-9][\w.-]{0,99}$/.test(name.value))

async function submit() {
  saving.value = true
  try {
    const saved = props.secret
      ? await api<VaultSecret>(`/api/secrets/${props.secret.id}`, { method: 'PUT', body: { value: value.value } })
      : await api<VaultSecret>('/api/secrets', { method: 'POST', body: { name: name.value.trim(), value: value.value, scope: props.scope ?? null } })
    value.value = ''
    toast.add({ title: props.secret ? 'Secret remplacé' : 'Secret enregistré', color: 'success', icon: 'i-lucide-check' })
    emit('saved', saved)
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}
</script>

<template>
  <form class="flex flex-col gap-3" data-testid="secret-form" @submit.prevent="submit">
    <UFormField label="Nom" required hint="ex. lodgify.api_key" help="Lettres, chiffres, « . », « _ » et « - ». Le code des briques lit le secret par ce nom.">
      <UInput v-model="name" class="w-full font-mono" :disabled="!!secret" autocomplete="off" />
    </UFormField>
    <UFormField :label="secret ? 'Nouvelle valeur' : 'Valeur'" required help="Chiffrée dès l’enregistrement ; elle ne sera plus jamais affichée.">
      <UInput v-model="value" :type="reveal ? 'text' : 'password'" class="w-full font-mono" autocomplete="new-password">
        <template #trailing>
          <UButton :icon="reveal ? 'i-lucide-eye-off' : 'i-lucide-eye'" color="neutral" variant="link" size="sm" :aria-label="reveal ? 'Masquer' : 'Afficher'" @click="reveal = !reveal" />
        </template>
      </UInput>
    </UFormField>
    <div class="flex justify-end gap-2">
      <UButton label="Annuler" color="neutral" variant="ghost" @click="emit('cancel')" />
      <UButton type="submit" :label="secret ? 'Remplacer' : 'Enregistrer'" :loading="saving" :disabled="!value || !validName" />
    </div>
  </form>
</template>
