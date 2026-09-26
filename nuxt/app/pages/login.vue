<script setup lang="ts">
definePageMeta({ layout: 'bare' })
const app = useAppConfig().rocket
useHead({ title: `Connexion · ${app.name}` })

const auth = useAuth()
const route = useRoute()
const { info: suite, isSuite } = useSuite()
const state = reactive({ email: '', password: '' })
const error = ref<string | null>(null)
const loading = ref(false)

// Single sign-on providers (OpenID Connect servers enabled by an administrator).
const { data: providers } = await useAsyncData('auth-providers', () => $fetch<{ providers: AuthProvider[] }>('/api/auth/providers', {
  baseURL: useRuntimeConfig().public.apiBase,
}).then(r => r.providers).catch(() => [] as AuthProvider[]), { default: () => [] as AuthProvider[] })
const redirecting = ref<string | null>(null)

function redirectTarget(): string {
  return typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') && !route.query.redirect.startsWith('//')
    ? route.query.redirect
    : '/'
}

async function signInWith(provider: AuthProvider) {
  redirecting.value = provider.id
  await startOidcSignIn(provider, redirectTarget())
}

// Suite mode: Rocket Auth signs everyone in. The page goes there directly, unless the person just logged out
// or asked for the emergency local sign-in (?local=1, when ROCKET_LOCAL_LOGIN=1).
const suiteProvider = computed(() => providers.value.find(p => p.id === suite.value?.auth?.providerId) ?? null)
const showLocalForm = computed(() => !isSuite.value || (!!suite.value?.localLogin && route.query.local === '1'))
onMounted(() => {
  if (isSuite.value && suiteProvider.value && !route.query.logged_out && route.query.local !== '1') {
    signInWith(suiteProvider.value)
  }
})

async function submit() {
  loading.value = true
  error.value = null
  try {
    await auth.login(state.email, state.password)
    await navigateTo(redirectTarget())
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
    <UCard class="w-full max-w-sm">
      <template #header>
        <div class="flex items-center gap-2 text-lg font-semibold">
          <UIcon :name="app.icon" class="size-6 text-primary" />
          {{ app.name }}
        </div>
        <p class="mt-1 text-sm text-muted">
          {{ app.tagline }}
        </p>
      </template>

      <template v-if="isSuite">
        <UAlert v-if="route.query.logged_out" color="success" variant="subtle" icon="i-lucide-log-out" description="Vous êtes déconnecté." class="mb-4" />
        <UButton
          v-if="suiteProvider"
          :label="`Se connecter avec ${suite?.auth?.name}`"
          icon="i-lucide-shield-check"
          size="lg"
          block
          :loading="redirecting === suiteProvider.id"
          @click="signInWith(suiteProvider)"
        />
        <UAlert v-else color="warning" variant="subtle" icon="i-lucide-cloud-off" :description="`${suite?.auth?.name ?? 'Rocket Auth'} est injoignable pour le moment. Réessayez dans un instant.`" />
        <p v-if="suite?.localLogin && !showLocalForm" class="mt-4 text-center text-xs text-muted">
          <ULink :to="{ path: '/login', query: { ...route.query, local: '1' } }" class="underline">
            Accès de secours (mot de passe local)
          </ULink>
        </p>
      </template>

      <div v-else-if="providers.length" class="mb-4 flex flex-col gap-2">
        <UButton
          v-for="provider in providers"
          :key="provider.id"
          :label="`Se connecter avec ${provider.name}`"
          icon="i-lucide-shield-check"
          color="neutral"
          variant="outline"
          block
          :loading="redirecting === provider.id"
          @click="signInWith(provider)"
        />
        <USeparator label="ou" class="my-2" />
      </div>

      <form v-if="showLocalForm" class="flex flex-col gap-4" :class="{ 'mt-4': isSuite }" @submit.prevent="submit">
        <UFormField label="Email" required>
          <UInput v-model="state.email" type="email" autocomplete="username" class="w-full" autofocus />
        </UFormField>
        <UFormField label="Mot de passe" required>
          <UInput v-model="state.password" type="password" autocomplete="current-password" class="w-full" />
        </UFormField>
        <UAlert v-if="error" color="error" variant="subtle" :description="error" icon="i-lucide-circle-alert" />
        <UButton type="submit" label="Se connecter" block :loading="loading" />
      </form>
    </UCard>
  </div>
</template>
