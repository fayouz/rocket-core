import type { LocaleMessages } from '../types'

export default {
  fr: { missingApp: 'Paramètre "app" manquant.', notEmbedded: 'Cette page doit être intégrée dans une application autorisée.', wrongApp: 'Le jeton ne correspond pas à cette application.' },
  en: { missingApp: 'Missing "app" parameter.', notEmbedded: 'This page must be embedded in an authorized application.', wrongApp: 'The token does not match this application.' },
} satisfies LocaleMessages
