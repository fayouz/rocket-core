import type { LocaleMessages } from '../types'

export default {
  fr: {
    title: 'Connexion', tagline: 'Connectez-vous avec votre compte local ou votre compte d’annuaire (LDAP).', loggedOut: 'Vous êtes déconnecté.',
    signInWith: 'Se connecter avec {name}', unreachable: '{name} est injoignable pour le moment. Réessayez dans un instant.',
    emergency: 'Accès de secours (mot de passe local)', or: 'ou', submit: 'Se connecter',
    inProgress: 'Connexion en cours…', failed: 'Connexion impossible', refused: 'Connexion refusée ({error}).',
    invalidResponse: 'Cette réponse de connexion est invalide ou a expiré. Recommencez depuis la page de connexion.', backToLogin: 'Retour à la connexion',
  },
  en: {
    title: 'Sign in', tagline: 'Sign in with your local account or your directory (LDAP) account.', loggedOut: 'You are signed out.',
    signInWith: 'Sign in with {name}', unreachable: '{name} cannot be reached right now. Try again in a moment.',
    emergency: 'Emergency access (local password)', or: 'or', submit: 'Sign in',
    inProgress: 'Signing in…', failed: 'Could not sign in', refused: 'Sign-in refused ({error}).',
    invalidResponse: 'This sign-in response is invalid or has expired. Start again from the sign-in page.', backToLogin: 'Back to sign in',
  },
} satisfies LocaleMessages
