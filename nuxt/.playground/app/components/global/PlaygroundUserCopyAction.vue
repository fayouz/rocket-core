<script setup lang="ts">
import type { User } from '#rocket/types/api'

// Action of each user row (rocket.extensions.users.rowActions).
const props = defineProps<{ user: User }>()
const toast = useToast()

async function copy() {
  await navigator.clipboard.writeText(props.user.email)
  toast.add({ title: 'Email copié', color: 'success', duration: 1500 })
}
</script>

<template>
  <UButton icon="i-lucide-at-sign" color="neutral" variant="ghost" aria-label="Copier l'email" @click="copy" />
</template>
