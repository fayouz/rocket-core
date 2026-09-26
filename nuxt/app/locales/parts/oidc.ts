import type { LocaleMessages } from '../types'

/** OpenID Connect settings shared by the authentication server form and its wizard. */
export default {
  fr: {
    redirectUriCopied: 'URL de retour copiée',
    redirectUriLabel: 'URL de retour à déclarer chez le fournisseur', redirectUriHelp: 'Ajoutez-la aux « redirect URIs » du client.',
    clientIdLabel: 'Client ID', clientSecretLabel: 'Secret du client', clientSecretHelp: 'Laisser vide pour conserver le secret actuel.',
    scopesLabel: 'Scopes', scopesHelp: 'Doit contenir « openid ». « groups » transmet les groupes de l’utilisateur.',
    adminGroupLabel: 'Groupe administrateurs (optionnel)',
    adminGroupHelp: 'Valeur de la revendication « groups » qui donne le rôle administrateur. Vide : les administrateurs sont gérés ici.',
    internalUrlLabel: 'URL interne (optionnel)',
    internalUrlHelp: 'Adresse du fournisseur vue depuis l’API, si elle diffère de l’émetteur (ex. http://auth-api dans Docker).',
    linkExistingAccounts: 'Relier les comptes existants de même email',
    linkExistingAccountsDescription: 'Uniquement pour un fournisseur de confiance, qui vérifie les adresses email.',
  },
  en: {
    redirectUriCopied: 'Redirect URI copied',
    redirectUriLabel: 'Redirect URI to register with the provider', redirectUriHelp: 'Add it to the client’s "redirect URIs".',
    clientIdLabel: 'Client ID', clientSecretLabel: 'Client secret', clientSecretHelp: 'Leave empty to keep the current secret.',
    scopesLabel: 'Scopes', scopesHelp: 'Must contain "openid". "groups" passes the user’s groups.',
    adminGroupLabel: 'Administrators group (optional)',
    adminGroupHelp: 'Value of the "groups" claim that grants the administrator role. Empty: administrators are managed here.',
    internalUrlLabel: 'Internal URL (optional)',
    internalUrlHelp: 'Address of the provider as seen from the API, if it differs from the issuer (e.g. http://auth-api in Docker).',
    linkExistingAccounts: 'Link existing accounts with the same email',
    linkExistingAccountsDescription: 'Only for a trusted provider that verifies email addresses.',
  },
} satisfies LocaleMessages
