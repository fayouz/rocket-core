<script setup lang="ts">
import type { Application } from '#rocket/types/api'

// Action of each application row (rocket.extensions.applications.rowActions): a button and its own dialog.
const props = defineProps<{ application: Application }>()
const open = ref(false)
</script>

<template>
  <UButton icon="i-lucide-code-xml" color="neutral" variant="ghost" aria-label="Code d'intégration" @click="open = true" />
  <UModal v-model:open="open" :title="`Code d'intégration de ${props.application.name}`">
    <template #body>
      <pre class="overflow-auto rounded bg-elevated p-3 text-xs">applicationId: '{{ props.application.id }}'</pre>
    </template>
  </UModal>
</template>
