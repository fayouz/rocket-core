import type { LocaleMessages } from '../types'

export default {
  fr: {
    title: 'Configuration initiale', welcome: 'Bienvenue dans {name}',
    intro: 'Première installation : créez le compte administrateur. Il gérera ensuite les utilisateurs, les applications et les réglages.',
    minLength: '{n} caractères minimum', remaining: 'Encore {n} caractère(s)', hidePassword: 'Masquer le mot de passe', showPassword: 'Afficher le mot de passe',
    confirmation: 'Confirmation', mismatch: 'Les mots de passe ne correspondent pas', token: 'Jeton d’installation',
    tokenHelp: 'Défini dans la configuration du serveur (.env) par la personne qui a installé {name}.', submit: 'Créer le compte administrateur',
    footer: 'Cette page n’est disponible que tant qu’aucun compte n’existe. Vous pourrez ensuite ajouter des administrateurs dans « Utilisateurs », ou via le groupe LDAP des administrateurs.',
  },
  en: {
    title: 'Initial setup', welcome: 'Welcome to {name}',
    intro: 'First installation: create the administrator account. It will then manage users, applications and settings.',
    minLength: 'At least {n} characters', remaining: '{n} more character(s)', hidePassword: 'Hide password', showPassword: 'Show password',
    confirmation: 'Confirmation', mismatch: 'The passwords do not match', token: 'Setup token',
    tokenHelp: 'Set in the server configuration (.env) by whoever installed {name}.', submit: 'Create the administrator account',
    footer: 'This page is only available while no account exists. You can then add administrators in “Users”, or through the LDAP administrators group.',
  },
} satisfies LocaleMessages
