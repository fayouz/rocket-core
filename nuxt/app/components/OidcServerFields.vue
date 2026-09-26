<script setup lang="ts">
/** OpenID Connect settings of an authentication server (client registered at the provider). */
interface OidcFields {
  internalUrl: string
  clientId: string
  clientSecret: string
  scopes: string
  adminGroupDn: string
  linkExistingAccounts: boolean
}

const model = defineModel<OidcFields>({ required: true })
defineProps<{ redirectUri: string, hasSecret: boolean }>()
const toast = useToast()
const { t } = useRocketI18n()

async function copyRedirectUri(value: string) {
  await navigator.clipboard.writeText(value)
  toast.add({ title: t('oidc.redirectUriCopied'), color: 'success' })
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <UFormField :label="t('oidc.redirectUriLabel')" :help="t('oidc.redirectUriHelp')">
      <UInput :model-value="redirectUri" readonly class="w-full font-mono" data-testid="oidc-redirect-uri">
        <template #trailing>
          <UButton icon="i-lucide-copy" color="neutral" variant="link" size="sm" :aria-label="t('common.copy')" @click="copyRedirectUri(redirectUri)" />
        </template>
      </UInput>
    </UFormField>
    <div class="grid gap-3 sm:grid-cols-2">
      <UFormField :label="t('oidc.clientIdLabel')" required>
        <UInput v-model="model.clientId" class="w-full font-mono" autocomplete="off" />
      </UFormField>
      <UFormField :label="t('oidc.clientSecretLabel')" :help="hasSecret ? t('oidc.clientSecretHelp') : undefined">
        <UInput v-model="model.clientSecret" type="password" class="w-full" autocomplete="new-password" :placeholder="hasSecret ? '••••••••' : ''" />
      </UFormField>
    </div>
    <UFormField :label="t('oidc.scopesLabel')" :help="t('oidc.scopesHelp')">
      <UInput v-model="model.scopes" class="w-full font-mono" />
    </UFormField>
    <UFormField :label="t('oidc.adminGroupLabel')" :help="t('oidc.adminGroupHelp')">
      <UInput v-model="model.adminGroupDn" placeholder="rocket-admins" class="w-full font-mono" />
    </UFormField>
    <UFormField :label="t('oidc.internalUrlLabel')" :help="t('oidc.internalUrlHelp')">
      <UInput v-model="model.internalUrl" placeholder="http://auth-api" class="w-full font-mono" />
    </UFormField>
    <USwitch v-model="model.linkExistingAccounts" :label="t('oidc.linkExistingAccounts')" :description="t('oidc.linkExistingAccountsDescription')" />
  </div>
</template>
