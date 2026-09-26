<?php

namespace Rocket\Core\I18n;

/**
 * API messages of the core in French and English (no dependency on the Translation component, which bricks
 * may not have). trans('health.database', ['count' => 2]): %count% is replaced.
 */
final class CoreMessages
{
    private const MESSAGES = [
        'fr' => [
            'dashboard.users' => 'Utilisateurs',
            'dashboard.users_enabled' => '%count% actif(s)',
            'dashboard.users_local' => '%count% locaux',
            'dashboard.new_user' => 'Nouvel utilisateur',
            'dashboard.new_application' => 'Nouvelle application',
            'health.database' => 'Base de données',
            'health.unreachable' => 'Injoignable',
            'health.queue' => 'Tâches de fond',
            'health.queue_late' => 'Tâches en attente depuis plus de 5 minutes : le worker est-il démarré ?',
            'health.queue_running' => '%count% tâche(s) en cours',
            'health.queue_empty' => 'Aucune tâche en attente',
            'health.ldap' => 'Annuaire LDAP',
            'health.not_configured' => 'Non configuré',
            'health.sso' => 'Authentification unique (OpenID Connect)',
            'health.no_provider' => 'Aucun fournisseur actif',
            'health.failing' => '%failing% sur %total% en échec : %names%',
            'health.not_checked' => 'Pas encore vérifié',
            'health.storage' => 'Stockage',
            'health.writable' => 'Accessible en écriture',
            'health.not_writable' => 'Dossier non accessible en écriture',
            'health.ldap_ok' => 'Connexion et authentification réussies',
            'ldap.found' => '%count% utilisateur(s) trouvé(s) avec une adresse email.',
            'oidc.found' => 'Fournisseur OpenID Connect trouvé : %issuer%.',
            'update.running' => 'Une mise à jour est déjà en cours.',
            'update.manual' => 'La méthode de mise à jour est « manuelle » : lancez les commandes sur le serveur.',
            'update.script_missing' => 'Le script de mise à jour « %script% » est introuvable ou n’est pas exécutable.',
            'update.invalid_version' => 'Version à installer invalide : « %version% ».',
            'update.nothing' => 'Aucune mise à jour en attente.',
            'update.repository_missing' => 'Le dépôt « %repository% » est introuvable sur GitHub (UPDATE_REPOSITORY).',
            'update.rate_limited' => 'GitHub limite le nombre de vérifications : réessayez dans une heure.',
            'update.not_configured' => 'La mise à jour automatique n’est pas configurée (UPDATER_URL, UPDATER_TOKEN).',
            'update.unreachable' => 'Le service de mise à jour est injoignable : %error%',
            'update.token_refused' => 'Le service de mise à jour refuse le jeton (UPDATER_TOKEN).',
            'update.status' => 'Le service de mise à jour a répondu %status%.',
        ],
        'en' => [
            'dashboard.users' => 'Users',
            'dashboard.users_enabled' => '%count% active',
            'dashboard.users_local' => '%count% local',
            'dashboard.new_user' => 'New user',
            'dashboard.new_application' => 'New application',
            'health.database' => 'Database',
            'health.unreachable' => 'Unreachable',
            'health.queue' => 'Background jobs',
            'health.queue_late' => 'Jobs waiting for more than 5 minutes: is the worker running?',
            'health.queue_running' => '%count% job(s) running',
            'health.queue_empty' => 'No job waiting',
            'health.ldap' => 'LDAP directory',
            'health.not_configured' => 'Not configured',
            'health.sso' => 'Single sign-on (OpenID Connect)',
            'health.no_provider' => 'No active provider',
            'health.failing' => '%failing% of %total% failing: %names%',
            'health.not_checked' => 'Not checked yet',
            'health.storage' => 'Storage',
            'health.writable' => 'Writable',
            'health.not_writable' => 'Directory not writable',
            'health.ldap_ok' => 'Connection and authentication succeeded',
            'ldap.found' => '%count% user(s) found with an email address.',
            'oidc.found' => 'OpenID Connect provider found: %issuer%.',
            'update.running' => 'An update is already running.',
            'update.manual' => 'The update method is “manual”: run the commands on the server.',
            'update.script_missing' => 'The update script “%script%” is missing or not executable.',
            'update.invalid_version' => 'Invalid version to install: “%version%”.',
            'update.nothing' => 'No pending update.',
            'update.repository_missing' => 'The repository “%repository%” does not exist on GitHub (UPDATE_REPOSITORY).',
            'update.rate_limited' => 'GitHub limits the number of checks: try again in an hour.',
            'update.not_configured' => 'Automatic updates are not configured (UPDATER_URL, UPDATER_TOKEN).',
            'update.unreachable' => 'The update service cannot be reached: %error%',
            'update.token_refused' => 'The update service refuses the token (UPDATER_TOKEN).',
            'update.status' => 'The update service answered %status%.',
        ],
    ];

    public function __construct(private readonly Locale $locale)
    {
    }

    /** Outside the container (tests, classes built by hand): French. */
    public static function french(): self
    {
        return new self(new Locale(new \Symfony\Component\HttpFoundation\RequestStack()));
    }

    /** @param array<string, string|int> $parameters */
    public function trans(string $key, array $parameters = []): string
    {
        $text = self::MESSAGES[$this->locale->current()][$key] ?? self::MESSAGES[Locale::DEFAULT][$key] ?? $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('%'.$name.'%', (string) $value, $text);
        }

        return $text;
    }
}
