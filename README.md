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
"require": { "rocket/core-bundle": "dev-main" }
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

Le bundle déclare ses entités (Doctrine et API Platform), ses routes, ses migrations (exécutées avec celles de l'application, dans l'ordre de leurs dates) et envoie les messages `Rocket\Core\Message\AsyncMessageInterface` sur le transport `async`. L'application garde son `security.yaml`, qui utilise les classes du bundle (`Rocket\Core\Entity\User`, `Rocket\Core\Security\LoginAuthenticator`, `ApplicationTokenAuthenticator`, `EmbedTokenAuthenticator`, `UserChecker`) et rend publiques `^/api/auth/login$`, `^/api/(setup|suite)$`, `^/api/embed/frame-policy$` et `^/api/auth/(providers|oidc/callback)$` (voir `tests/App/config/packages/security.yaml`).

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
"dependencies": { "@rocket/core": "github:fayouz/rocket-core#main" }
```

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  extends: [process.env.ROCKET_CORE_LAYER || fileURLToPath(new URL('./node_modules/@rocket/core/nuxt', import.meta.url))],
})
```

L'identité et les menus se règlent dans `app.config.ts` (`rocket: { id, name, icon, tagline, navigation, adminNavigation, shortcuts, quotes }`). Les types partagés s'importent de `#rocket/types/api`. `ROCKET_CORE_LAYER=../../rocket-core/nuxt` permet de travailler sur le layer et l'application en même temps.

## Modes autonome et suite

Chaque application Rocket fonctionne **seule** ou **dans la suite**, selon sa configuration. Le mécanisme est ici, le choix appartient à chaque application.

| | Autonome (par défaut) | Suite (`ROCKET_AUTH_URL` renseignée) |
|---|---|---|
| Connexion | comptes locaux, LDAP, fournisseurs OpenID Connect déclarés par les administrateurs | par **Rocket Auth** uniquement, directement depuis la page de connexion |
| Comptes | créés dans l'application, synchronisés depuis l'annuaire | créés à la première connexion ; utilisateurs, groupes et annuaire gérés dans Rocket Auth |
| Administrateurs | configuration initiale, groupe LDAP | groupe `ROCKET_AUTH_ADMIN_GROUP` de Rocket Auth |
| Menu | | sélecteur des applications de la suite, lien « Mon compte » |
| Déconnexion | locale | locale et Rocket Auth (RP-initiated logout) |

| Variable | Rôle | Défaut |
|---|---|---|
| `ROCKET_AUTH_URL` | Adresse publique de Rocket Auth (son émetteur). Vide : mode autonome. | — |
| `ROCKET_AUTH_INTERNAL_URL` | Adresse de Rocket Auth vue par ce serveur (ex. `http://auth-api` dans Docker) | l'adresse publique |
| `ROCKET_AUTH_CLIENT_ID`, `ROCKET_AUTH_CLIENT_SECRET` | Client OpenID Connect de l'application dans Rocket Auth | `rocket-<app_id>` |
| `ROCKET_AUTH_ADMIN_GROUP` | Groupe Rocket Auth dont les membres sont administrateurs | `rocket-admins` |
| `ROCKET_LOCAL_LOGIN` | `1` : accès de secours par mot de passe local (comptes créés avec `app:user:create`) | `0` |

Rocket Auth est déclaré automatiquement comme serveur d'authentification **géré** (en lecture seule dans l'administration), à la première connexion ou avec `php bin/console rocket:suite:sync`. Revenir en mode autonome le désactive et le rend aux administrateurs. `GET /api/suite` (public) décrit le mode, pour la page de connexion et le menu. La liste des applications vient de Rocket Auth (`GET /api/suite/apps`).

## Développement

```bash
composer install
php tests/App/bin/console lexik:jwt:generate-keypair --skip-if-exists
php tests/App/bin/console doctrine:database:create && php tests/App/bin/console doctrine:migrations:migrate -n
vendor/bin/phpunit                                   # tests du bundle, sur l'application minimale tests/App

cd nuxt && npm install && npm run lint && npm run typecheck   # layer ; npm run dev pour le lancer seul
```
