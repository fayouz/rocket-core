# Rocket Core

Le socle commun des applications Rocket (Rocket Mailer, Rocket Auth, Rocket Cloud, Rocket Print, Rocket Doc Fusion) :

| Partie | Paquet | Contenu |
|---|---|---|
| `src/`, `config/`, `migrations/` | bundle Symfony `rocket/core-bundle` (Composer) | configuration initiale, comptes locaux, LDAP et OpenID Connect, serveurs d'authentification, applications externes et impersonation, tableau de bord extensible, sondes de santé, versions et mises à jour, démo, modes autonome et suite |
| `nuxt/` | layer Nuxt `@rocket/core` (npm) | layout et menu, tableau de bord, connexion, configuration initiale, utilisateurs, LDAP, serveurs d'authentification, applications, mises à jour, documentation et changelog |

Une application Rocket ne contient que son métier : elle installe les deux paquets et les complète.

## Utiliser rocket-core dans une application

### Backend (Symfony 8.1)

```json
// composer.json
"repositories": [{ "type": "vcs", "url": "https://github.com/fayouz/rocket-core" }],
"require": { "rocket/core-bundle": "^0.1" }
```

```php
// config/bundles.php
Rocket\Core\RocketCoreBundle::class => ['all' => true],
```

```yaml
# config/packages/rocket_core.yaml
rocket_core:
    app_name: Rocket Print     # nom affiché, client OpenID Connect par défaut « rocket-<app_id> »
    app_id: print
    token_prefix: rpa_         # jetons des applications externes

# config/routes.yaml
rocket_core:
    resource: '@RocketCoreBundle/config/routes.php'
```

Le bundle déclare ses entités (Doctrine et API Platform), ses routes, ses migrations (exécutées avec celles de l'application, dans l'ordre de leurs dates) et envoie les messages `Rocket\Core\Message\AsyncMessageInterface` sur le transport `async`. L'application garde son `security.yaml`, qui utilise les classes du bundle (`Rocket\Core\Entity\User`, `Rocket\Core\Security\LoginAuthenticator`, `ApplicationTokenAuthenticator`, `EmbedTokenAuthenticator`, `UserChecker`) et rend publiques `^/api/auth/login$`, `^/api/(setup|suite)$`, `^/api/embed/frame-policy$` et `^/api/auth/(providers|oidc/callback|oidc/backchannel-logout)$` (voir `tests/App/config/packages/security.yaml`).

Points d'extension, par simple implémentation d'une interface (autoconfiguration) :

| Interface | Rôle |
|---|---|
| `Dashboard\DashboardSectionInterface` | indicateurs, séries, activité, actions rapides du tableau de bord |
| `Health\ServiceProbeInterface` | dépendance réseau vérifiée toutes les 5 minutes et affichée dans l'état des services |
| `Command\DemoSeederInterface` | données de démo, chargées par `app:demo:seed` |
| `Scheduler\RecurringTaskProviderInterface` | tâches planifiées, exécutées par le worker |
| `Embed\EmbedEndpointsInterface` | endpoints accessibles aux pages embarquées (voir ci-dessous) |

**Pages embarquées (iframe).** Une application externe autorisée à agir en tant qu'utilisateur obtient, côté serveur, un jeton court (`POST /api/embed/token`, avec `X-Impersonate-User`, durée `EMBED_TOKEN_TTL`, 900 s par défaut) qu'elle transmet à une page `/embed/…?app=<id>` de la brique. Seules les origines déclarées sur l'application (`allowedOrigins`) peuvent afficher ces pages (`frame-ancestors`, `GET /api/embed/frame-policy`) et leur parler (`postMessage`). La session obtenue (`Authorization: Embed <jeton>`, rôle `ROLE_EMBED`, jamais administrateur) n'atteint que `GET /api/me`, `GET /api/embed/context` et les endpoints déclarés par la brique. Côté front, `useEmbedBridge().connect(appId)` ouvre la session et `rocket.embed: true` (app.config) affiche le champ des origines dans l'administration des applications.

### Front (Nuxt 4)

```json
// package.json
"dependencies": { "@rocket/core": "github:fayouz/rocket-core#semver:^0.1.0" }
```

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  extends: [process.env.ROCKET_CORE_LAYER || fileURLToPath(new URL('./node_modules/@rocket/core/nuxt', import.meta.url))],
})
```

L'identité et les menus se règlent dans `app.config.ts` (`rocket: { id, name, icon, tagline, navigation, adminNavigation, shortcuts, quotes, extensions }`). Les types partagés s'importent de `#rocket/types/api`. `ROCKET_CORE_LAYER=../../rocket-core/nuxt` permet de travailler sur le layer et l'application en même temps.

### Points d'extension des pages du layer

Une brique qui a besoin de quelques champs, colonnes ou actions de plus sur une page du layer **l'étend au lieu de la copier** : elle nomme ses propres composants dans `app.config.ts` (`rocket.extensions`), et la page les affiche à leur place. Ces composants doivent être **globaux** (dossier `app/components/global/` ou nom `*.global.vue`) pour être résolus par leur nom ; un nom introuvable est ignoré, avec un avertissement en développement. Contrat typé : `#rocket/types/extensions`.

| Page | Clé | Composant(s) | Props |
|---|---|---|---|
| Applications | `applications.columns` | `{ id, header, component }[]` : colonnes insérées avant les actions | `application` |
| | `applications.rowActions` | actions de chaque ligne (un bouton et sa propre fenêtre), avant celles du layer ; événement `refresh` pour recharger la liste | `application` |
| | `applications.formSections` | sections ajoutées à la fin du formulaire de création / modification ; `defineExpose({ save(application) })` est attendu une fois l'application enregistrée (une erreur garde la fenêtre ouverte en modification) | `application` (`null` à la création) |
| | `applications.help` | texte de l'encadré « Comment ça marche » (chaîne ; vide : celui du layer) | |
| | `applications.tokenExample` | remplace l'exemple d'appel de la fenêtre du nouveau jeton | `application`, `token` |
| Utilisateurs | `users.columns`, `users.rowActions` | comme pour les applications | `user` |
| Tableau de bord | `dashboard.sections` | blocs affichés après les indicateurs ; les chiffres du domaine passent d'abord par les sections du backend (`DashboardSectionInterface` : KPIs, séries, éléments récents, activité, actions rapides), affichés génériquement | `dashboard` |

Exemple : Rocket Mailer ajoute l'expéditeur de chaque application (colonne et section du formulaire, ressource `/api/application_senders/{id}` de la brique) et une action « Code d'intégration ».

```ts
// frontend/app/app.config.ts
export default defineAppConfig({
  rocket: {
    embed: true,
    extensions: {
      applications: {
        columns: [{ id: 'sender', header: 'Expéditeur', component: 'MailerApplicationSenderCell' }],
        rowActions: ['MailerApplicationEmbedAction'],
        formSections: ['MailerApplicationSender'],
        tokenExample: 'MailerApplicationTokenExample',
      },
    },
  },
})
```

```vue
<!-- frontend/app/components/global/MailerApplicationSender.vue : section du formulaire -->
<script setup lang="ts">
import type { Application } from '#rocket/types/api'
import type { RocketApplicationFormExtension } from '#rocket/types/extensions'
import type { ApplicationSender } from '~/types/api'

const props = defineProps<{ application: Application | null }>()
const api = useApi()
const form = reactive({ senderName: '', senderEmail: '', allowedSenders: [] as string[] })
// Monté à l'ouverture du formulaire : les réglages de l'application modifiée.
onMounted(async () => {
  if (!props.application) return
  const sender = await api<ApplicationSender>(`/api/application_senders/${props.application.id}`)
  Object.assign(form, { senderName: sender.senderName ?? '', senderEmail: sender.senderEmail ?? '', allowedSenders: [...sender.allowedSenders] })
})

// Appelé par la page une fois l'application créée ou modifiée (elle a alors un id).
defineExpose<RocketApplicationFormExtension>({
  async save(application) {
    await api(`/api/application_senders/${application.id}`, {
      method: 'PATCH',
      body: { senderName: form.senderName || null, senderEmail: form.senderEmail || null, allowedSenders: form.allowedSenders },
    })
    await refreshNuxtData('application-senders') // la colonne
  },
})
</script>

<template>
  <UFormField label="Adresse d'expédition" required>
    <UInput v-model="form.senderEmail" type="email" class="w-full" />
  </UFormField>
  <UFormField label="Nom affiché">
    <UInput v-model="form.senderName" class="w-full" />
  </UFormField>
  <UFormField label="Adresses d'expédition qu'elle peut imposer">
    <UInputTags v-model="form.allowedSenders" add-on-blur add-on-paste class="w-full" />
  </UFormField>
</template>
```

```vue
<!-- frontend/app/components/global/MailerApplicationEmbedAction.vue : action de chaque ligne -->
<script setup lang="ts">
import type { Application } from '#rocket/types/api'

defineProps<{ application: Application }>()
const open = ref(false)
</script>

<template>
  <UButton icon="i-lucide-code-xml" color="neutral" variant="ghost" aria-label="Code d'intégration" @click="open = true" />
  <EmbedCodeModal v-if="open" v-model:open="open" :application-id="application.id" />
</template>
```

La colonne (`MailerApplicationSenderCell`, prop `application`) lit la liste `useAsyncData('application-senders', …)`, partagée par toutes ses cellules ; `MailerApplicationTokenExample` (props `application`, `token`) montre l'intégration du composeur au lieu de l'exemple `curl`. Le playground du layer (`nuxt/.playground/app`) en donne un exemple complet et exécutable : colonne, action, section de formulaire, action sur les utilisateurs et section du tableau de bord.

### Intégration continue (workflows réutilisables)

Les briques appellent les workflows de `.github/workflows/` (`workflow_call`) au lieu de recopier leurs jobs ; ce qui leur est propre reste dans des scripts de la brique.

| Workflow | Rôle | Entrées (défaut) |
|---|---|---|
| `brick-backend.yml` | PHP 8.4, PostgreSQL 16, Composer (authentifié par le `GITHUB_TOKEN` de la brique), clés JWT, `lint:container`, `lint:yaml`, migrations, `schema:validate`, PHPUnit | `working-directory` (`backend`), `php-version` (`8.4`), `php-extensions` (`pdo_pgsql, intl, ldap, zip`), `setup-script` : script de la brique lancé avant `composer install` (services supplémentaires avec `docker run`, variables dans `$GITHUB_ENV`) |
| `brick-frontend.yml` | Application Nuxt : `npm ci`, lint, typecheck, puis un script de build | `working-directory` (`frontend`), `node-version` (`22`), `build-script` (`build` ; `generate` pour la documentation, vide pour aucun) |
| `brick-images.yml` | Images Docker (cible `prod`), publiées sur ghcr.io pour `main`, `develop` et les tags `v*`, sinon chargées et testées : `about`, OpenAPI et 401 de l'API, CSP de `/login` du front | `image-prefix` (nom du dépôt), `components` (JSON, api/backend et front/frontend), `api-resource` (`/api/users`), `smoke-script` : script de la brique appelé avec le composant (`IMAGE`, `API`, `FRONT` dans l'environnement). L'appelant accorde `contents: read` et `packages: write`. |
| `brick-demo.yml` | Démo `compose.yaml` + `compose.demo.yaml` : démarrage, seed, attente du front et de son proxy `/api`, puis les scénarios de la brique ; journaux en cas d'échec | `front-url`, `docs-url`, `ready-urls` (autres adresses à attendre), `scenario-script` (`.github/demo-scenarios.sh`, lancé avec `bash -e` et `COMPOSE`, `FRONT`, `DOCS`, `APP_VERSION`) |

```yaml
# .github/workflows/ci.yml d'une brique (la référence deviendra un tag de version de rocket-core)
jobs:
  backend:
    name: Backend (PHP)
    uses: fayouz/rocket-core/.github/workflows/brick-backend.yml@v0.1.0
  frontend:
    name: Frontend (Nuxt)
    uses: fayouz/rocket-core/.github/workflows/brick-frontend.yml@v0.1.0
  docs:
    name: Docs (Nuxt Content)
    uses: fayouz/rocket-core/.github/workflows/brick-frontend.yml@v0.1.0
    with: { working-directory: docs, build-script: generate }
  images:
    name: Docker images
    needs: [backend, frontend]
    permissions: { contents: read, packages: write }
    uses: fayouz/rocket-core/.github/workflows/brick-images.yml@v0.1.0
  demo:
    name: Demo environment (compose)
    needs: [backend, frontend]
    uses: fayouz/rocket-core/.github/workflows/brick-demo.yml@v0.1.0
    with: { front-url: 'http://localhost:3100', docs-url: 'http://localhost:3101' }
```

## Langues (français, anglais)

Le layer et les messages de l'API du socle existent en **français** et en **anglais**. Par défaut, une application reste en français uniquement : rien ne change tant qu'elle n'ajoute pas l'anglais.

- **Front** (`app.config.ts`) : `rocket.locales: ['fr', 'en']` affiche un choix de langue dans le menu utilisateur (cookie `rocket_locale`) ; `rocket.defaultLocale` donne la langue par défaut, qu'une brique peut aussi fixer à l'exécution (`useRocketI18n().setDefaultLocale('en')`, par exemple d'après un réglage).
- **Textes** : `const { t, locale, languageTag } = useRocketI18n()` ; `t('users.title')`, `t('dashboard.greeting', { name })`. Les textes du layer sont dans `nuxt/app/locales/parts/<espace>.ts` (`{ fr: {...}, en: {...} }`, chargés automatiquement). Une brique fournit les siens dans `rocket.messages` (`{ fr: {...}, en: {...} }`), lus avant ceux du layer ; les libellés de `rocket.navigation`, `rocket.adminNavigation` et `rocket.tagline` peuvent être des clés de ces textes.
- `formatDate`, `timeAgo`, `formatNumber`, `formatPercent`, `formatSize` suivent la langue ; `rocket.quotes` vide : citations du layer dans la langue courante.
- **API** : `useApi` envoie `Accept-Language` ; le bundle suit cette langue parmi `rocket_core.locales` (défaut `[fr]`, même liste que le front) pour le tableau de bord, l'état des services et les mises à jour (`Rocket\Core\I18n\CoreMessages`, sans dépendance au composant Translation). La langue retenue est dans l'attribut de requête `_rocket_locale` et `Request::getLocale()`.

## Thèmes et palettes

- **Clair, sombre ou système** : bouton (`ColorModeSwitch`) en bas du menu et en haut à droite des pages de connexion et de configuration initiale ; choix gardé dans le navigateur. Les pages embarquées (`/embed/…`) n'ont pas de bouton et suivent le système.
- **Palettes** (Administration → Palettes, `pages/palettes.vue`) : couleur principale obligatoire, secondaire, succès, information, avertissement et erreur facultatives (`#rrggbb`), et le ton des gris (`slate`, `gray`, `zinc`, `neutral`, `stone`). Les nuances 50 à 950 sont calculées par le front (`utils/palette.ts`, OKLCH) ; aperçu avant d'enregistrer.
- **Palette du projet** : bouton « Utiliser pour le projet » de la page Palettes ; « Couleurs par défaut » revient à celles de `app.config.ts` (`ui.colors`). Elle habille toute la brique, page de connexion comprise.
- **Palette d'une application** : champ de la page Applications (briques avec `rocket.embed`), appliquée aux pages qu'elle embarque ; vide ou supprimée, celle du projet.
- Le plugin `theme.client.ts` lit `GET /api/theme` (public ; `?app=<id>` sur `/embed/…`) avant la première page ; `useTheme()` recharge (`load()`) après un changement.

| Méthode | Endpoint | Rôle |
|---|---|---|
| `GET`, `POST`, `PATCH`, `DELETE` | `/api/color_palettes[/{id}]` | Palettes (administrateurs) |
| `GET`, `PUT` | `/api/theme/project` | Palette du projet `{ "palette": "/api/color_palettes/{id}" }`, `""` pour les couleurs par défaut |
| `PATCH` | `/api/applications/{id}` | `{ "palette": "/api/color_palettes/{id}" }`, `null` pour celle du projet |
| `GET` | `/api/theme?app={id}` | Public : `{ source: application\|project\|default, palette }` |

La brique rend `/api/theme` public dans son `security.yaml` (avant la règle `^/api`) : `- { path: ^/api/theme$, roles: PUBLIC_ACCESS }`. Sans cette règle, le front garde ses couleurs par défaut.

## Modes autonome et suite

Chaque application Rocket fonctionne **seule** ou **dans la suite**, selon sa configuration. Le mécanisme est ici, le choix appartient à chaque application.

| | Autonome (par défaut) | Suite (`ROCKET_AUTH_URL` renseignée) |
|---|---|---|
| Connexion | comptes locaux, LDAP, fournisseurs OpenID Connect déclarés par les administrateurs | par **Rocket Auth** uniquement, directement depuis la page de connexion |
| Comptes | créés dans l'application, synchronisés depuis l'annuaire | créés à la première connexion ; utilisateurs, groupes et annuaire gérés dans Rocket Auth |
| Administrateurs | configuration initiale, groupe LDAP | groupe `ROCKET_AUTH_ADMIN_GROUP` de Rocket Auth |
| Menu | | sélecteur des applications de la suite, lien « Mon compte » |
| Déconnexion | locale | locale et Rocket Auth (RP-initiated logout) ; Rocket Auth ferme les sessions des applications (back-channel logout) |

| Variable | Rôle | Défaut |
|---|---|---|
| `ROCKET_AUTH_URL` | Adresse publique de Rocket Auth (son émetteur). Vide : mode autonome. | — |
| `ROCKET_AUTH_INTERNAL_URL` | Adresse de Rocket Auth vue par ce serveur (ex. `http://auth-api` dans Docker) | l'adresse publique |
| `ROCKET_AUTH_CLIENT_ID`, `ROCKET_AUTH_CLIENT_SECRET` | Client OpenID Connect de l'application dans Rocket Auth | `rocket-<app_id>` |
| `ROCKET_AUTH_ADMIN_GROUP` | Groupe Rocket Auth dont les membres sont administrateurs | `rocket-admins` |
| `ROCKET_LOCAL_LOGIN` | `1` : accès de secours par mot de passe local (comptes créés avec `app:user:create`) | `0` |
| `ROCKET_PUBLIC_URL` | Adresse publique de l'application (son interface, qui relaie `/api`) | `FRONTEND_URL` si l'application la définit |
| `ROCKET_INTERNAL_URL` | Adresse de l'application vue par le serveur de Rocket Auth (ex. `http://print-api` dans Docker) : Rocket Auth y envoie les déconnexions | l'adresse publique |

Rocket Auth est déclaré automatiquement comme serveur d'authentification **géré** (en lecture seule dans l'administration), à la première connexion ou avec `php bin/console rocket:suite:sync`. Revenir en mode autonome le désactive et le rend aux administrateurs. `GET /api/suite` (public) décrit le mode, pour la page de connexion et le menu. La liste des applications vient de Rocket Auth (`GET /api/suite/apps`).

### Déconnexion côté serveur (Back-Channel Logout)

Quand un utilisateur se déconnecte de Rocket Auth, ou qu'un administrateur l'y désactive ou le supprime, Rocket Auth envoie à chaque application un jeton de déconnexion signé ([OpenID Connect Back-Channel Logout 1.0](https://openid.net/specs/openid-connect-backchannel-1_0.html)) sur `POST /api/auth/oidc/backchannel-logout` (public, paramètre `logout_token`). L'application le vérifie : signature (JWKS du serveur géré, en cache), `iss`, `aud` = son client ID, événement `http://schemas.openid.net/event/backchannel-logout`, pas de `nonce`, `iat` de moins de 5 minutes, `jti` jamais vu (cache). Elle retrouve le compte lié au sujet (`sub`) et **ferme toutes ses sessions** : `User::$sessionsRevokedAt` est renseigné et les JWT de session émis avant (`iat`) sont refusés (`SessionRevocationListener`). Les sessions ouvertes ensuite fonctionnent ; une session encore ouverte dans Rocket Auth reconnecte l'utilisateur sans qu'il ressaisisse son mot de passe. Les sessions de Rocket Auth (`sid`) ne sont pas suivies ici : toute déconnexion reçue ferme toutes les sessions du compte dans l'application.

L'adresse est déclarée à Rocket Auth par l'application elle-même (`SuiteProvisioner`, à la première connexion et avec `rocket:suite:sync`) : `POST <ROCKET_AUTH_INTERNAL_URL>/oauth/suite/register`, authentifié par le secret du client, avec `backchannel_logout_uri` = `<ROCKET_INTERNAL_URL>/api/auth/oidc/backchannel-logout`. Sans adresse connue (`ROCKET_PUBLIC_URL`, `ROCKET_INTERNAL_URL`, `FRONTEND_URL`), rien n'est déclaré ; un administrateur de Rocket Auth peut la saisir sur la fiche du client. Un échec est journalisé et retenté 5 minutes plus tard.

La déconnexion locale (`POST /api/auth/logout`, appelée par l'interface avant d'oublier le jeton) ne ferme que la session courante : elle émet seulement `Rocket\Core\Event\UserLoggedOutEvent`, que Rocket Auth écoute.

### Appels entre applications (client credentials)

En mode suite, une application en appelle une autre avec un jeton d'accès de Rocket Auth plutôt qu'avec un jeton statique :

```php
// Rocket\Core\Suite\ServiceTokenProvider : jeton obtenu par client credentials (ROCKET_AUTH_CLIENT_ID / _SECRET),
// pour l'audience « rocket-mailer », en cache jusqu'à 30 s avant son expiration (5 minutes).
$http->request('POST', $mailerUrl.'/api/emails', ['auth_bearer' => $tokens->tokenFor('mailer'), 'headers' => ['X-Impersonate-User' => $email], …]);
```

L'application appelée accepte `Authorization: Bearer <jeton Rocket Auth>` (`ApplicationTokenAuthenticator`, via `SuiteAccessTokens`) : signature (JWKS), `iss`, `aud` = son client ID (`rocket-<app_id>`), `exp`, et un jeton d'application seulement (`sub` = `azp` = client appelant). Le client appelant (ex. `rocket-cloud`) doit être **lié à une application** par un administrateur (champ « Client Rocket Auth » de la page Applications, `Application::$oauthClientId`) : ses droits sont ceux de cette application (impersonation avec `X-Impersonate-User`, jamais administrateur, `lastUsedAt`). Les jetons statiques restent valables (mode autonome, transition).

## Versions

rocket-core suit le versionnage sémantique : chaque version est un tag `vX.Y.Z`. Les briques dépendent de `^0.1` (Composer), `github:fayouz/rocket-core#semver:^0.1.0` (npm) et des workflows `@v0.1.0`. Renovate (`renovate.json` de chaque brique) ouvre une seule pull request pour les trois quand une nouvelle version est taguée. Tant que la version est en 0.x, une version mineure (0.2.0) peut casser la compatibilité : les briques la reçoivent par une pull request à valider.

## Développement

```bash
composer install
php tests/App/bin/console lexik:jwt:generate-keypair --skip-if-exists
php tests/App/bin/console doctrine:database:create && php tests/App/bin/console doctrine:migrations:migrate -n
vendor/bin/phpunit                                   # tests du bundle, sur l'application minimale tests/App

cd nuxt && npm install && npm run lint && npm run typecheck   # layer ; npm run dev pour le lancer seul
```
