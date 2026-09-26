import type { LocaleMessages } from '../types'

/** Words shared by the layer's pages (buttons, fields, states). */
export default {
  fr: {
    cancel: 'Annuler', save: 'Enregistrer', create: 'Créer', add: 'Ajouter', delete: 'Supprimer', edit: 'Modifier', close: 'Fermer',
    copy: 'Copier', copied: 'Copié', back: 'Retour', search: 'Rechercher', refresh: 'Actualiser', loading: 'Chargement…', yes: 'Oui', no: 'Non',
    email: 'Email', password: 'Mot de passe', firstName: 'Prénom', lastName: 'Nom', name: 'Nom', status: 'Statut', actions: 'Actions',
    enabled: 'Activé', disabled: 'Désactivé', active: 'Actif', inactive: 'Inactif', never: 'jamais', justNow: 'à l’instant', none: '—',
    error: 'Erreur', saved: 'Enregistré', saveFailed: 'Enregistrement impossible', actionFailed: 'Action impossible', test: 'Tester',
    language: 'Langue',
  },
  en: {
    cancel: 'Cancel', save: 'Save', create: 'Create', add: 'Add', delete: 'Delete', edit: 'Edit', close: 'Close',
    copy: 'Copy', copied: 'Copied', back: 'Back', search: 'Search', refresh: 'Refresh', loading: 'Loading…', yes: 'Yes', no: 'No',
    email: 'Email', password: 'Password', firstName: 'First name', lastName: 'Last name', name: 'Name', status: 'Status', actions: 'Actions',
    enabled: 'Enabled', disabled: 'Disabled', active: 'Active', inactive: 'Inactive', never: 'never', justNow: 'just now', none: '—',
    error: 'Error', saved: 'Saved', saveFailed: 'Could not save', actionFailed: 'Action failed', test: 'Test',
    language: 'Language',
  },
} satisfies LocaleMessages
