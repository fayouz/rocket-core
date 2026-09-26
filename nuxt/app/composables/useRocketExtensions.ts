import type { Component } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { RocketExtensionColumn, RocketExtensions } from '#rocket/types/extensions'

/**
 * The extension components a brick declares for a page of the layer (app.config.ts, `rocket.extensions`), resolved
 * by name. Components that cannot be resolved (not global) are left out, with a warning in development.
 */
export function useRocketExtensions<P extends keyof RocketExtensions>(page: P): RocketExtensions[P] {
  return useAppConfig().rocket.extensions[page] as RocketExtensions[P]
}

/** Resolves global components by name (call it in setup). */
export function resolveRocketComponents(names: readonly string[]): Component[] {
  return names.map(resolveRocketComponent).filter((component): component is Component => component !== null)
}

/** Resolves one global component by name (call it in setup); null when empty or not found. */
export function resolveRocketComponent(name: string): Component | null {
  if (!name) return null
  const component = resolveComponent(name)
  if (typeof component === 'string') {
    if (import.meta.dev) console.warn(`[rocket] Extension component "${name}" not found: make it global (app/components/global/ or ${name}.global.vue).`)
    return null
  }
  return component
}

/** Table columns of extension components, which get the row's entity as the `prop` prop. */
export function resolveRocketColumns<T>(columns: readonly RocketExtensionColumn[], prop: string): TableColumn<T>[] {
  return columns.flatMap((column) => {
    const component = resolveRocketComponent(column.component)
    return component ? [{ id: column.id, header: column.header, cell: ({ row }) => h(component, { [prop]: row.original }) } satisfies TableColumn<T>] : []
  })
}
