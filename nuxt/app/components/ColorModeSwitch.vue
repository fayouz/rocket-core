<script setup lang="ts">
/** Light / dark / system, kept in the browser (@nuxtjs/color-mode). */
const colorMode = useColorMode()
const { t } = useRocketI18n()

const MODES = [
  { value: 'light', label: 'layout.light', icon: 'i-lucide-sun' },
  { value: 'dark', label: 'layout.dark', icon: 'i-lucide-moon' },
  { value: 'system', label: 'layout.system', icon: 'i-lucide-monitor' },
] as const

const active = computed(() => MODES.find(mode => mode.value === colorMode.preference) ?? MODES[2])
const items = computed(() => [MODES.map(mode => ({
  label: t(mode.label),
  icon: mode.icon,
  type: 'checkbox' as const,
  checked: colorMode.preference === mode.value,
  onSelect: () => {
    colorMode.preference = mode.value
  },
}))])
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end', side: 'top' }">
    <UButton
      :icon="active.icon"
      color="neutral"
      variant="ghost"
      :aria-label="t('layout.theme', { mode: t(active.label) })"
      data-testid="color-mode"
    />
  </UDropdownMenu>
</template>
