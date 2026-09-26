# rocket-core

Socle commun des briques du Middleware Rocket (Mailer, Auth, Cloud, Print, bientôt Doc Fusion) : bundle Symfony `rocket/core-bundle` (`src/`, `config/`, `migrations/`, namespace `Rocket\Core`) et layer Nuxt `@rocket/core` (`nuxt/`). Chaque changement touche toutes les briques : rester additif et rétrocompatible dans une version corrective.

## Repères
- Extension par interfaces : `Dashboard\DashboardSectionInterface`, `Health\ServiceProbeInterface`, `Command\DemoSeederInterface`, `Scheduler\RecurringTaskProviderInterface`, `Embed\EmbedEndpointsInterface`. Front : `rocket.extensions` (`nuxt/app/types/extensions.ts`, `useRocketExtensions`).
- Modes autonome / suite (`ROCKET_AUTH_URL`) : `src/Suite`, `src/Oidc`, back-channel logout, jetons client credentials (`ServiceTokenProvider`, `SuiteAccessTokens`).
- Application de test : `tests/App` (Kernel, config, `bin/console`) ; tests `tests/Functional`, `tests/Integration`.
- Thèmes : `ColorPalette`, `Theme\ProjectTheme` (réglage `theme.palette`), `Application::$palette`, `GET /api/theme` public ; front `plugins/theme.client.ts`, `useTheme`, `utils/palette.ts`, `pages/palettes.vue`, `ColorModeSwitch`.
- Les briques ensemble (compose, e2e Playwright, Codespace) : dépôt [rocket-suite](https://github.com/fayouz/rocket-suite).
- `.github/workflows/brick-*.yml` : workflows réutilisables appelés par les briques (`@vX.Y.Z`).

## Vérifier avant de pousser
```bash
php tests/App/bin/console lint:container
php tests/App/bin/console doctrine:migrations:migrate -n && php tests/App/bin/console doctrine:schema:validate
vendor/bin/phpunit
cd nuxt && npm run lint && npm run typecheck
```
Avant une version : vérifier aussi le typecheck d'une brique avec `ROCKET_CORE_LAYER=/chemin/rocket-core/nuxt`.

## Versions
Semver par tags `vX.Y.Z` (créés depuis GitHub : la session ne peut pas pousser de tag). En 0.x, une mineure peut casser ; Renovate ouvre les mises à jour dans les briques et fusionne seul les correctifs quand la CI passe.

## Pièges connus
- Migrations du socle : idempotentes et enregistrées (`if (...) { $this->write(...); return; }`), jamais `skipIf` (une migration sautée n'est pas enregistrée). Elles tournent aussi sur des bases de briques qui avaient déjà les tables.
- API Platform répond en JSON-LD par défaut : `Accept: application/json` pour un tableau.
- symfony/ldap 8.1 : option `maxItems` (plus `sizeLimit`).
- Local sans `ext-ldap` : `--ignore-platform-req=ext-ldap` ; `vendor/*/*/.git` pèse plusieurs Go.
