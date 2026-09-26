import type { LocaleMessages, Messages } from './types'

/**
 * The layer's texts: one file per part of the interface in ./parts (common.ts, login.ts, users.ts…), each exporting
 * { fr: {...}, en: {...} }; the file name is the first part of the key: t('users.title').
 */
const parts = import.meta.glob<{ default: LocaleMessages }>('./parts/*.ts', { eager: true })

export const LAYER_MESSAGES: Record<string, Messages> = {}
for (const [path, module] of Object.entries(parts)) {
  const namespace = path.replace(/^.*\/(.+)\.ts$/, '$1')
  for (const [locale, messages] of Object.entries(module.default)) {
    (LAYER_MESSAGES[locale] ??= {})[namespace] = messages
  }
}
