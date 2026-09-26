<script setup lang="ts">
import type { Application } from '#rocket/types/api'

// Column of the applications table (rocket.extensions.applications.columns).
const props = defineProps<{ application: Application }>()
const notes = useApplicationNotes()
</script>

<template>
  <span class="text-sm text-muted">{{ notes[props.application.id] || '—' }}</span>
</template>
