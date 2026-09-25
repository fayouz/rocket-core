import type { RouteLocationNormalized } from 'vue-router'

declare module '#app' {
  interface PageMeta {
    /** Administrators only. */
    admin?: boolean
    /** Opened without an account: always, or depending on the route (e.g. only for some queries). */
    public?: boolean | ((to: RouteLocationNormalized) => boolean)
  }
}

export {}
