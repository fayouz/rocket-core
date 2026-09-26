/** Texts of one part of the interface, per language: { fr: {...}, en: {...} }, nested objects of strings. */
export interface Messages { [key: string]: string | Messages }
export type LocaleMessages = Record<string, Messages>
