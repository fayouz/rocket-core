<?php

namespace Rocket\Core;

use Doctrine\Migrations\Version\Comparator;
use Rocket\Core\Doctrine\JsonText;
use Rocket\Core\Entity\Application;
use Rocket\Core\Doctrine\MigrationVersionComparator;
use Rocket\Core\Message\AsyncMessageInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The platform shared by every Rocket application: first-run setup, local, LDAP and OpenID Connect accounts,
 * authentication servers, external applications and impersonation, dashboard, health checks, updates, demo,
 * and the standalone / suite modes (see Rocket\Core\Suite).
 *
 * An application registers the bundle, keeps its own security.yaml (firewalls use the authenticators of the bundle)
 * and adds its domain: dashboard sections, service probes, demo seeders, recurring tasks.
 */
final class RocketCoreBundle extends AbstractBundle
{
    protected string $extensionAlias = 'rocket_core';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('app_name')->defaultValue('Rocket')->info('Name of the application, e.g. "Rocket Print".')->end()
                ->scalarNode('app_id')->defaultValue('rocket')->info('Identifier of the application in the suite, e.g. "print".')->end()
                ->scalarNode('token_prefix')->defaultValue('rka_')->info('Prefix of the application tokens, e.g. "rpa_" for Rocket Print.')->end()
                ->arrayNode('locales')
                    ->info('Languages of the API messages, the first being the default; the interface\'s language (Accept-Language) is followed among them. Same list as rocket.locales in app.config.ts.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['fr'])
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'RocketCore' => ['type' => 'attribute', 'is_bundle' => false, 'dir' => __DIR__.'/Entity', 'prefix' => 'Rocket\Core\Entity', 'alias' => 'RocketCore'],
                ],
                'dql' => ['string_functions' => ['JSON_TEXT' => JsonText::class]],
            ],
        ]);
        $appEntities = $builder->getParameter('kernel.project_dir').'/src/Entity';
        $builder->prependExtensionConfig('api_platform', [
            'mapping' => ['paths' => array_values(array_filter([__DIR__.'/Entity', is_dir($appEntities) ? $appEntities : null]))],
        ]);
        $builder->prependExtensionConfig('doctrine_migrations', [
            // The application's namespace first: new migrations (doctrine:migrations:diff) are written there.
            'migrations_paths' => [
                'DoctrineMigrations' => '%kernel.project_dir%/migrations',
                'Rocket\Core\Migrations' => \dirname(__DIR__).'/migrations',
            ],
            // Core and application migrations run in timestamp order, whatever their namespace.
            'services' => [Comparator::class => MigrationVersionComparator::class],
        ]);
        $builder->prependExtensionConfig('framework', [
            'messenger' => ['routing' => [AsyncMessageInterface::class => 'async']],
        ]);
    }

    public function boot(): void
    {
        Application::setTokenPrefix((string) $this->container->getParameter('rocket_core.token_prefix'));
    }

    /** @param array{app_name: string, app_id: string, token_prefix: string, locales: list<string>} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('rocket_core.app_name', $config['app_name'])
            ->set('rocket_core.app_id', $config['app_id'])
            ->set('rocket_core.token_prefix', $config['token_prefix'])
            ->set('rocket_core.locales', array_values(array_intersect($config['locales'] ?: ['fr'], \Rocket\Core\I18n\Locale::SUPPORTED)) ?: ['fr']);
        foreach (self::ENV_DEFAULTS as $name => $value) {
            if (!$builder->hasParameter("env($name)")) {
                $container->parameters()->set("env($name)", $value);
            }
        }
        $container->import('../config/services.php');
    }

    /** Defaults of the environment variables read by the core. */
    private const ENV_DEFAULTS = [
        'EMBED_TOKEN_TTL' => '900',
        'LDAP_ENABLED' => '0',
        'LDAP_URL' => 'ldap://localhost:389',
        'LDAP_BASE_DN' => '',
        'LDAP_SEARCH_DN' => '',
        'LDAP_SEARCH_PASSWORD' => '',
        'LDAP_USER_FILTER' => '(objectClass=inetOrgPerson)',
        'LDAP_ADMIN_GROUP_DN' => '',
        'LDAP_START_TLS' => '0',
        'LDAP_ATTRIBUTE_EMAIL' => 'mail',
        'LDAP_ATTRIBUTE_FIRST_NAME' => 'givenName',
        'LDAP_ATTRIBUTE_LAST_NAME' => 'sn',
        'LDAP_ATTRIBUTE_GROUPS' => 'memberOf',
        'SETUP_TOKEN' => '',
        'SECRETS_ENCRYPTION_KEY' => '',
        'DATA_DIR' => '%kernel.project_dir%/var/data',
        'APP_VERSION' => '',
        'UPDATE_REPOSITORY' => '',
        'UPDATER_URL' => '',
        'UPDATER_TOKEN' => '',
        'UPDATE_METHOD' => '',
        'UPDATE_SCRIPT' => '%kernel.project_dir%/../deploy/update.sh',
        'UPDATE_RESTART_COMMAND' => '',
        'UPDATE_SCRIPT_TIMEOUT' => '1800',
        // Suite mode (see Rocket\Core\Suite\SuiteSettings): empty ROCKET_AUTH_URL means standalone.
        'ROCKET_AUTH_URL' => '',
        'ROCKET_AUTH_INTERNAL_URL' => '',
        'ROCKET_AUTH_CLIENT_ID' => '',
        'ROCKET_AUTH_CLIENT_SECRET' => '',
        'ROCKET_AUTH_ADMIN_GROUP' => 'rocket-admins',
        'ROCKET_LOCAL_LOGIN' => '0',
        // Address of the brick (public, and as Rocket Auth reaches it): back-channel logout endpoint registered in Rocket Auth.
        'ROCKET_PUBLIC_URL' => '',
        'ROCKET_INTERNAL_URL' => '',
        'DEMO_MODE' => '0',
        'DEMO_APP_TOKEN' => '',
        'DEMO_SSO_ISSUER' => '',
        'DEMO_SSO_INTERNAL_URL' => '',
        'DEMO_SSO_CLIENT_ID' => '',
        'DEMO_SSO_CLIENT_SECRET' => '',
        'DEMO_SSO_ADMIN_GROUP' => '',
    ];
}
