<script setup lang="ts">
import type { ColorPalette, ProjectTheme } from '#rocket/types/api'
import type { Neutral, PaletteColor } from '#rocket/utils/palette'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
// Pages the applications embed (/embed/…): they may have their own palette.
const embed = useAppConfig().rocket.embed
const { t } = useRocketI18n()
useHead({ title: () => `${t('palettes.title')} · ${appName}` })

const api = useApi()
const toast = useToast()
const theme = useTheme()

const { data: palettes, refresh } = await useAsyncData('palettes', () => api<ColorPalette[]>('/api/color_palettes'), { default: () => [] })
const { data: project, refresh: refreshProject } = await useAsyncData('palettes-project', () => api<ProjectTheme>('/api/theme/project'))

const COLOR_LABELS = computed<Record<PaletteColor, { label: string, hint: string }>>(() => ({
  primary: { label: t('palettes.colorPrimary'), hint: t('palettes.colorPrimaryHint') },
  secondary: { label: t('palettes.colorSecondary'), hint: t('palettes.colorSecondaryHint') },
  success: { label: t('palettes.colorSuccess'), hint: t('palettes.colorSuccessHint') },
  info: { label: t('palettes.colorInfo'), hint: t('palettes.colorInfoHint') },
  warning: { label: t('palettes.colorWarning'), hint: t('palettes.colorWarningHint') },
  error: { label: t('palettes.colorError'), hint: t('palettes.colorErrorHint') },
}))
const NEUTRAL_LABELS = computed<Record<Neutral, string>>(() => ({
  slate: t('palettes.neutralSlate'),
  gray: t('palettes.neutralGray'),
  zinc: t('palettes.neutralZinc'),
  neutral: t('palettes.neutralNeutral'),
  stone: t('palettes.neutralStone'),
}))
// Examples for a new palette.
const STARTERS = computed(() => [
  { name: t('palettes.starterIndigo'), primary: '#4f46e5', neutral: 'slate' as const },
  { name: t('palettes.starterEmerald'), primary: '#059669', neutral: 'zinc' as const },
  { name: t('palettes.starterRaspberry'), primary: '#db2777', neutral: 'stone' as const },
])

type Form = { name: string, neutral: Neutral } & Record<PaletteColor, string>
const selectedId = ref<string | null>(null)
const form = reactive<Form>({ name: '', neutral: 'zinc', primary: '#f97316', secondary: '', success: '', info: '', warning: '', error: '' })
const saving = ref(false)
const toDelete = ref<ColorPalette | null>(null)

const isNew = computed(() => selectedId.value === 'new')
const projectPaletteId = computed(() => project.value?.palette?.id ?? null)
const validColors = computed(() => PALETTE_COLORS.every(color => form[color] === '' || isHex(form[color])))
const canSave = computed(() => form.name.trim() !== '' && isHex(form.primary) && validColors.value)

// Live preview, limited to the preview box.
const previewCss = computed(() => scopedPaletteCss('[data-palette-preview]', {
  colors: Object.fromEntries(PALETTE_COLORS.filter(color => isHex(form[color])).map(color => [color, form[color]])),
}))
useHead({ style: [{ id: 'palette-preview', innerHTML: previewCss }] })

function open(palette: ColorPalette) {
  selectedId.value = palette.id
  Object.assign(form, {
    name: palette.name,
    neutral: palette.neutral,
    ...Object.fromEntries(PALETTE_COLORS.map(color => [color, palette[color] ?? ''])),
  })
}

function create(starter: (typeof STARTERS.value)[number] = STARTERS.value[0]!) {
  selectedId.value = 'new'
  Object.assign(form, { name: starter.name, neutral: starter.neutral, primary: starter.primary, secondary: '', success: '', info: '', warning: '', error: '' })
}

async function save() {
  saving.value = true
  try {
    const body = { name: form.name, neutral: form.neutral, ...Object.fromEntries(PALETTE_COLORS.map(color => [color, form[color] || null])) }
    const saved = isNew.value
      ? await api<ColorPalette>('/api/color_palettes', { method: 'POST', body })
      : await api<ColorPalette>(`/api/color_palettes/${selectedId.value}`, { method: 'PATCH', body })
    await refresh()
    selectedId.value = saved.id
    // The project's palette changed: apply it now.
    if (saved.id === projectPaletteId.value) await theme.load()
    toast.add({ title: t('palettes.saveSuccess'), color: 'success', icon: 'i-lucide-check' })
  }
  catch (error) {
    toast.add({ title: t('palettes.saveFailed'), description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function useForProject(id: string | null) {
  try {
    await api('/api/theme/project', { method: 'PUT', body: { palette: id ? `/api/color_palettes/${id}` : '' } })
    await Promise.all([refreshProject(), theme.load()])
    toast.add({ title: id ? t('palettes.useForProjectSuccess') : t('palettes.resetProjectSuccess'), color: 'success' })
  }
  catch (error) {
    toast.add({ title: t('palettes.updateFailed'), description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove() {
  if (!toDelete.value) return
  const wasProject = toDelete.value.id === projectPaletteId.value
  try {
    await api(`/api/color_palettes/${toDelete.value.id}`, { method: 'DELETE' })
    if (selectedId.value === toDelete.value.id) selectedId.value = null
    toDelete.value = null
    await Promise.all([refresh(), refreshProject()])
    if (wasProject) await theme.load()
  }
  catch (error) {
    toast.add({ title: t('palettes.deleteFailed'), description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="palettes">
    <template #header>
      <UDashboardNavbar :title="t('palettes.title')">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UDropdownMenu :items="STARTERS.map(s => ({ label: s.name, onSelect: () => create(s) }))">
            <UButton icon="i-lucide-plus" :label="t('palettes.newPalette')" trailing-icon="i-lucide-chevron-down" data-testid="new-palette" />
          </UDropdownMenu>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="grid gap-6 xl:grid-cols-[340px_1fr]">
        <div class="flex flex-col gap-3">
          <p class="text-sm text-muted">
            {{ t('palettes.projectPaletteIntro', { name: appName }) }}
            <template v-if="embed">
              {{ t('palettes.embedIntro') }}
            </template>
          </p>
          <button
            type="button"
            class="flex items-center gap-3 rounded-lg border p-3 text-left transition hover:bg-elevated/50"
            :class="projectPaletteId === null ? 'border-primary' : 'border-default'"
            @click="useForProject(null)"
          >
            <UIcon name="i-lucide-rotate-ccw" class="size-5 shrink-0 text-muted" />
            <span class="min-w-0 flex-1 text-sm">{{ t('palettes.defaultColors', { name: appName }) }}</span>
            <UBadge v-if="projectPaletteId === null" :label="t('palettes.projectBadge')" size="sm" variant="subtle" />
          </button>
          <button
            v-for="palette in palettes"
            :key="palette.id"
            type="button"
            class="flex items-center gap-3 rounded-lg border p-3 text-left transition hover:bg-elevated/50"
            :class="selectedId === palette.id ? 'border-primary' : 'border-default'"
            :data-testid="`palette-${palette.name}`"
            @click="open(palette)"
          >
            <span class="flex shrink-0 -space-x-1">
              <span
                v-for="color in PALETTE_COLORS.filter(c => palette[c])"
                :key="color"
                class="size-5 rounded-full ring-2 ring-default"
                :style="{ background: palette[color]! }"
              />
            </span>
            <span class="min-w-0 flex-1 truncate font-medium">{{ palette.name }}</span>
            <UBadge v-if="palette.id === projectPaletteId" :label="t('palettes.projectBadge')" size="sm" variant="subtle" />
            <UButton icon="i-lucide-trash-2" color="error" variant="ghost" size="xs" :aria-label="t('palettes.deleteAria')" @click.stop="toDelete = palette" />
          </button>
        </div>

        <form v-if="selectedId" class="flex min-w-0 flex-col gap-4" data-testid="palette-form" @submit.prevent="save">
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField :label="t('palettes.nameLabel')" required>
              <UInput v-model="form.name" class="w-full" />
            </UFormField>
            <UFormField :label="t('palettes.neutralLabel')" :hint="t('palettes.neutralHint')">
              <USelect v-model="form.neutral" :items="NEUTRALS.map(n => ({ label: NEUTRAL_LABELS[n], value: n }))" class="w-full" />
            </UFormField>
          </div>
          <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
            <UFormField
              v-for="color in PALETTE_COLORS"
              :key="color"
              :label="COLOR_LABELS[color].label"
              :hint="color === 'primary' ? t('palettes.requiredHint') : t('palettes.optionalHint')"
              :help="COLOR_LABELS[color].hint"
              :error="form[color] !== '' && !isHex(form[color]) ? t('palettes.hexError') : undefined"
            >
              <div class="flex items-center gap-2">
                <input
                  type="color"
                  :value="isHex(form[color]) ? form[color] : '#ffffff'"
                  :aria-label="t('palettes.colorPickerAria', { color: COLOR_LABELS[color].label })"
                  class="size-9 shrink-0 cursor-pointer rounded border border-default bg-transparent p-0.5"
                  @input="(event) => { form[color] = (event.target as HTMLInputElement).value }"
                >
                <UInput v-model="form[color]" placeholder="#rrggbb" class="w-full font-mono" :data-testid="`palette-${color}`" />
              </div>
            </UFormField>
          </div>

          <UFormField :label="t('palettes.previewLabel')">
            <div data-palette-preview class="flex flex-col gap-3 rounded-lg border border-default p-4" data-testid="palette-preview">
              <div class="flex flex-wrap gap-2">
                <UButton :label="t('palettes.colorPrimary')" />
                <UButton v-for="color in (['secondary', 'success', 'info', 'warning', 'error'] as const)" :key="color" :color="color" :label="COLOR_LABELS[color].label" />
              </div>
              <div class="flex flex-wrap gap-2">
                <UButton :label="t('palettes.previewOutline')" variant="outline" />
                <UButton :label="t('palettes.previewSoft')" variant="soft" />
                <UBadge :label="t('palettes.previewSent')" color="success" variant="subtle" />
                <UBadge :label="t('palettes.previewFailed')" color="error" variant="subtle" />
                <UBadge :label="t('palettes.previewDegraded')" color="warning" variant="subtle" />
              </div>
              <UAlert color="primary" variant="subtle" icon="i-lucide-sparkles" :title="t('palettes.previewAlertTitle')" :description="t('palettes.previewAlertDescription')" />
              <div class="flex gap-1">
                <span v-for="shade in SHADES" :key="shade" class="h-6 flex-1 rounded-sm" :style="{ background: `var(--ui-color-primary-${shade})` }" :title="String(shade)" />
              </div>
            </div>
          </UFormField>

          <div class="flex flex-wrap gap-2">
            <UButton type="submit" :label="t('common.save')" icon="i-lucide-save" :loading="saving" :disabled="!canSave" />
            <UButton
              v-if="!isNew && selectedId !== projectPaletteId"
              :label="t('palettes.useForProject')"
              icon="i-lucide-paintbrush"
              color="neutral"
              variant="outline"
              data-testid="use-for-project"
              @click="useForProject(selectedId)"
            />
            <UButton :label="t('palettes.close')" color="neutral" variant="ghost" @click="selectedId = null" />
          </div>
        </form>
      </div>

      <UModal :open="toDelete !== null" :title="t('palettes.deleteTitle')" :description="toDelete ? t('palettes.deleteDescription', { name: toDelete.name }) : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton :label="t('common.cancel')" color="neutral" variant="ghost" @click="toDelete = null" />
            <UButton :label="t('common.delete')" color="error" @click="remove" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
