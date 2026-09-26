<script setup lang="ts">
const route = useRoute()
const theme = useTheme()
// Embedded pages follow their host page: no theme switch there, and the application's palette (?app=).
const embedded = computed(() => route.path.startsWith('/embed/'))
watch(() => [embedded.value, route.query.app] as const, ([isEmbedded, app]) => {
  const wanted = isEmbedded && typeof app === 'string' ? app : null
  if (wanted !== theme.application.value) theme.load(wanted)
})
</script>

<template>
  <div class="relative min-h-dvh bg-default">
    <div v-if="!embedded" class="absolute end-3 top-3 z-10">
      <ColorModeSwitch />
    </div>
    <slot />
  </div>
</template>
