<script setup lang="ts">
import type { Dashboard } from '#rocket/types/api'

// Section of the dashboard, after the KPI cards (rocket.extensions.dashboard.sections).
const props = defineProps<{ dashboard: Dashboard }>()
</script>

<template>
  <UAlert icon="i-lucide-puzzle" color="neutral" variant="subtle" :title="`Section du playground : ${props.dashboard.kpis.length} indicateur(s)`" />
</template>
