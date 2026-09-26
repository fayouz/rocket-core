import type { Application } from './api'

/**
 * Extension points of the layer's pages (app.config.ts, `rocket.extensions`): a brick adds columns, row actions,
 * form sections… by naming its own components instead of copying the page. The components must be global
 * (in app/components/global/ or named *.global.vue) so the page can resolve them by name. See the README.
 */

/** An extra table column: `component` renders a cell, with the row's entity as prop (`application`, `user`). */
export interface RocketExtensionColumn {
  /** Unique column id. */
  id: string
  header: string
  /** Name of a global component. */
  component: string
}

/** Extension points of the applications page (/applications). */
export interface RocketApplicationsExtensions {
  /** Columns inserted before the actions; cell props: `{ application: Application }`. */
  columns: RocketExtensionColumn[]
  /**
   * Components rendered first in the actions cell of each row, props `{ application: Application }`; they may emit
   * `refresh` to reload the list. Typically an icon button opening the brick's own dialog.
   */
  rowActions: string[]
  /**
   * Sections added at the end of the create/edit form, props `{ application: Application | null }` (null when creating).
   * A section may expose (defineExpose) `save(application)`: awaited after the application itself is saved (created
   * or updated), e.g. to save a resource of the brick bound to the application. See RocketApplicationFormExtension.
   */
  formSections: string[]
  /** Replaces the "Comment ça marche" help text of the page; empty for the layer's. */
  help: string
  /**
   * Replaces the call example of the dialog showing a new token, props `{ application: Application, token: string }`;
   * empty for the layer's curl example.
   */
  tokenExample: string
}

/** Extension points of the users page (/users). */
export interface RocketUsersExtensions {
  /** Columns inserted before the actions; cell props: `{ user: User }`. */
  columns: RocketExtensionColumn[]
  /** Components rendered first in the actions cell of each row, props `{ user: User }`; they may emit `refresh`. */
  rowActions: string[]
}

/** Extension points of the dashboard (/): the figures of the domain come from the API's dashboard sections. */
export interface RocketDashboardExtensions {
  /** Components rendered after the KPI cards, props `{ dashboard: Dashboard }`. */
  sections: string[]
}

export interface RocketExtensions {
  applications: RocketApplicationsExtensions
  users: RocketUsersExtensions
  dashboard: RocketDashboardExtensions
}

/** What a form section of the applications page may expose (defineExpose) to the page. */
export interface RocketApplicationFormExtension {
  /** Called once the application is saved, before the dialog closes; a thrown error keeps the edit dialog open. */
  save?: (application: Application) => Promise<unknown> | unknown
}
