<?php

namespace Rocket\Core\Suite;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The applications of the suite, for the application switcher: published by Rocket Auth (GET /api/suite/apps,
 * its OpenID Connect clients shown in the switcher), cached 5 minutes. Empty when Rocket Auth cannot be reached.
 */
final class SuiteApps
{
    public function __construct(
        private readonly SuiteSettings $suite,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<array{id: string, name: string, url: string, icon: string|null, description: string|null}> */
    public function all(): array
    {
        if (!$this->suite->isSuite()) {
            return [];
        }

        return $this->cache->get('rocket_suite_apps_'.md5($this->suite->authUrl()), function (ItemInterface $item): array {
            $item->expiresAfter(300);
            $base = '' !== $this->suite->authInternalUrl() ? $this->suite->authInternalUrl() : $this->suite->authUrl();
            try {
                $data = $this->httpClient->request('GET', $base.'/api/suite/apps', ['timeout' => 5, 'headers' => ['Accept' => 'application/json']])->toArray();
            } catch (\Throwable $e) {
                $this->logger->warning('Rocket Auth: applications of the suite unavailable ({message}).', ['message' => $e->getMessage()]);
                $item->expiresAfter(30);

                return [];
            }
            $apps = [];
            foreach ((array) ($data['apps'] ?? $data) as $app) {
                if (\is_array($app) && \is_string($app['name'] ?? null) && \is_string($app['url'] ?? null) && preg_match('#^https?://#i', $app['url'])) {
                    $apps[] = [
                        'id' => (string) ($app['id'] ?? $app['url']),
                        'name' => $app['name'],
                        'url' => $app['url'],
                        'icon' => \is_string($app['icon'] ?? null) ? $app['icon'] : null,
                        'description' => \is_string($app['description'] ?? null) ? $app['description'] : null,
                    ];
                }
            }

            return $apps;
        });
    }
}
