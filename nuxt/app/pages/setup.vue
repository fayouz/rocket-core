<script setup lang="ts">
definePageMeta({ layout: 'bare' })
const appName = useAppConfig().rocket.name
const { t } = useRocketI18n()
useHead({ title: () => `${t('setup.title')} · ${appName}` })

const auth = useAuth()
const config = useRuntimeConfig()
const setup = await useSetupStatus()

const state = reactive({ firstName: '', lastName: '', email: '', password: '', confirmation: '', setupToken: '' })
const error = ref<string | null>(null)
const loading = ref(false)
const showPassword = ref(false)

const MIN_LENGTH = 12
const passwordTooShort = computed(() => state.password.length > 0 && state.password.length < MIN_LENGTH)
const mismatch = computed(() => state.confirmation.length > 0 && state.confirmation !== state.password)
const canSubmit = computed(() => isEmail(state.email) && state.password.length >= MIN_LENGTH && state.password === state.confirmation
  && (!setup.tokenRequired || state.setupToken.trim() !== '') && !loading.value)

async function submit() {
  if (!canSubmit.value) return
  loading.value = true
  error.value = null
  try {
    const { token } = await $fetch<{ token: string }>('/api/setup', {
      baseURL: config.public.apiBase,
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: {
        email: state.email.trim(),
        password: state.password,
        firstName: state.firstName.trim() || null,
        lastName: state.lastName.trim() || null,
        setupToken: setup.tokenRequired ? state.setupToken.trim() : null,
      },
    })
    markSetupDone()
    await auth.startSession(token)
    await navigateTo('/settings?welcome=1')
  }
  catch (e) {
    error.value = apiErrorMessage(e)
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center p-4">
    <UCard class="w-full max-w-md" data-testid="setup">
      <template #header>
        <div class="flex items-center gap-2 text-lg font-semibold">
          <UIcon name="i-lucide-rocket" class="size-6 text-primary" />
          {{ t('setup.welcome', { name: appName }) }}
        </div>
        <p class="mt-1 text-sm text-muted">
          {{ t('setup.intro') }}
        </p>
      </template>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-3">
          <UFormField :label="t('common.firstName')">
            <UInput v-model="state.firstName" autocomplete="given-name" class="w-full" />
          </UFormField>
          <UFormField :label="t('common.lastName')">
            <UInput v-model="state.lastName" autocomplete="family-name" class="w-full" />
          </UFormField>
        </div>
        <UFormField :label="t('common.email')" required>
          <UInput v-model="state.email" type="email" autocomplete="username" class="w-full" autofocus />
        </UFormField>
        <UFormField
          :label="t('common.password')"
          required
          :hint="t('setup.minLength', { n: MIN_LENGTH })"
          :error="passwordTooShort ? t('setup.remaining', { n: MIN_LENGTH - state.password.length }) : undefined"
        >
          <UInput
            v-model="state.password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="new-password"
            class="w-full"
            :ui="{ trailing: 'pe-1' }"
          >
            <template #trailing>
              <UButton
                :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                :aria-label="showPassword ? t('setup.hidePassword') : t('setup.showPassword')"
                color="neutral"
                variant="link"
                size="sm"
                @click="showPassword = !showPassword"
              />
            </template>
          </UInput>
        </UFormField>
        <UFormField :label="t('setup.confirmation')" required :error="mismatch ? t('setup.mismatch') : undefined">
          <UInput v-model="state.confirmation" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" class="w-full" />
        </UFormField>
        <UFormField
          v-if="setup.tokenRequired"
          :label="t('setup.token')"
          required
          hint="SETUP_TOKEN"
          :help="t('setup.tokenHelp', { name: appName })"
        >
          <UInput v-model="state.setupToken" type="password" autocomplete="off" class="w-full" />
        </UFormField>

        <UAlert v-if="error" color="error" variant="subtle" :description="error" icon="i-lucide-circle-alert" />
        <UButton type="submit" :label="t('setup.submit')" icon="i-lucide-shield-check" block :loading="loading" :disabled="!canSubmit" />
      </form>

      <template #footer>
        <p class="text-xs text-muted">
          {{ t('setup.footer') }}
        </p>
      </template>
    </UCard>
  </div>
</template>
