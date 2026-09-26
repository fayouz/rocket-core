/** Formatting in the interface's language (useRocketI18n): call from setup, templates or event handlers. */
function tag(): string {
  return useRocketI18n().languageTag.value
}

export function formatSize(bytes: number): string {
  const french = tag().startsWith('fr')
  const [b, kb, mb, gb] = french ? ['o', 'Ko', 'Mo', 'Go'] : ['B', 'KB', 'MB', 'GB']
  const decimal = (value: number) => new Intl.NumberFormat(tag(), { maximumFractionDigits: 1, minimumFractionDigits: 1 }).format(value)
  if (bytes < 1024) return `${bytes} ${b}`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} ${kb}`
  if (bytes < 1024 ** 3) return `${decimal(bytes / 1024 / 1024)} ${mb}`
  return `${decimal(bytes / 1024 ** 3)} ${gb}`
}

const RELATIVE_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['year', 365 * 24 * 3600],
  ['month', 30 * 24 * 3600],
  ['week', 7 * 24 * 3600],
  ['day', 24 * 3600],
  ['hour', 3600],
  ['minute', 60],
]

/** "il y a 5 minutes", "hier" / "5 minutes ago", "yesterday"… */
export function timeAgo(value: string | Date | null | undefined, now: Date = new Date()): string {
  const { t } = useRocketI18n()
  if (!value) return t('common.never')
  const relative = new Intl.RelativeTimeFormat(tag(), { numeric: 'auto' })
  const seconds = Math.round((new Date(value).getTime() - now.getTime()) / 1000)
  for (const [unit, size] of RELATIVE_UNITS) {
    if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit)
  }
  return t('common.justNow')
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat(tag()).format(value)
}

export function formatPercent(value: number | null | undefined, digits = 1): string {
  if (value === null || value === undefined) return '—'
  const number = new Intl.NumberFormat(tag(), { maximumFractionDigits: digits }).format(value)
  return tag().startsWith('fr') ? `${number} %` : `${number}%`
}

export function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1)
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function isEmail(value: string): boolean {
  return EMAIL_PATTERN.test(value.trim())
}

export function formatDate(value: string | null | undefined): string {
  return value
    ? new Intl.DateTimeFormat(tag(), { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—'
}
