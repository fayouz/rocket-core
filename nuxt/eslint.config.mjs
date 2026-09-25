import withNuxt from './.playground/.nuxt/eslint.config.mjs'

export default withNuxt({
  // Pages, layouts and components of the layer are named by their path (e.g. DashboardSparkline), as in an application.
  files: ['app/**/*.vue'],
  rules: { 'vue/multi-word-component-names': 'off' },
})
