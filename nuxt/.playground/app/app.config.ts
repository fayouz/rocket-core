/**
 * Extension points of the layer's pages, as a brick declares them (see the README, "Extension points"):
 * the components are global (app/components/global/), resolved by name.
 */
export default defineAppConfig({
  rocket: {
    extensions: {
      applications: {
        columns: [{ id: 'note', header: 'Note', component: 'PlaygroundApplicationNoteCell' }],
        rowActions: ['PlaygroundApplicationCodeAction'],
        formSections: ['PlaygroundApplicationNoteSection'],
      },
      users: {
        rowActions: ['PlaygroundUserCopyAction'],
      },
      dashboard: {
        sections: ['PlaygroundDashboardSection'],
      },
    },
  },
})
