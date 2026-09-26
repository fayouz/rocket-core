<script setup lang="ts">
import type { Application } from '#rocket/types/api'
import type { RocketApplicationFormExtension } from '#rocket/types/extensions'

// Section of the application form (rocket.extensions.applications.formSections): application is null when creating.
const props = defineProps<{ application: Application | null }>()
const note = ref(props.application ? (useApplicationNotes().value[props.application.id] ?? '') : '')

// Saved by the page once the application itself is saved (it then has an id).
defineExpose<RocketApplicationFormExtension>({
  save: (application: Application) => saveApplicationNote(application.id, note.value),
})
</script>

<template>
  <UFormField label="Note interne (playground)" hint="Enregistrée dans le navigateur">
    <UInput v-model="note" class="w-full" data-testid="playground-note" />
  </UFormField>
</template>
