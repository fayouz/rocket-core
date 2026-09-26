<?php

namespace Rocket\Core\Dashboard;

use Rocket\Core\I18n\CoreMessages;

use Rocket\Core\Entity\ServiceCheck;
use Rocket\Core\Health\HealthChecker;
use Rocket\Core\Health\ServiceProbeInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Rocket\Core\Ldap\LdapSettings;
use Rocket\Core\Repository\AuthenticationServerRepository;
use Rocket\Core\Repository\ServiceCheckRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Status of the services the platform depends on, for the dashboard.
 * Only cheap checks: nothing here opens a network connection besides the database queries. The LDAP server and the
 * OpenID Connect providers are checked over the network in the background (Rocket\Core\Health\HealthChecker): their last results are read here.
 */
final class PlatformHealth
{
    public const OPERATIONAL = 'operational';
    public const DEGRADED = 'degraded';
    public const DOWN = 'down';
    /** Not checked yet (the scheduler runs every 5 minutes). */
    public const UNKNOWN = 'unknown';

    /** A message waiting longer than this means the worker is stopped. */
    private const QUEUE_DELAY_WARNING = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly LdapSettings $ldapSettings,
        #[Autowire(env: 'resolve:DATA_DIR')] private readonly string $dataDir,
        private readonly ServiceCheckRepository $checks,
        private readonly AuthenticationServerRepository $servers,
        /** @var iterable<ServiceProbeInterface> */
        #[AutowireIterator('app.service_probe')]
        private readonly iterable $probes = [],
        private readonly ?CoreMessages $messages = null,
    ) {
    }

    /** @return array{status: string, services: list<array<string, mixed>>} */
    public function check(): array
    {
        $services = [$this->database()];
        $databaseUp = self::DOWN !== $services[0]['status'];
        $checks = $databaseUp ? $this->checks->allById() : [];
        if ($databaseUp) {
            $services[] = $this->queue();
        }
        $services[] = $this->ldap($databaseUp, $checks['ldap'] ?? null);
        if ($databaseUp) {
            $services[] = $this->sso($checks);
            foreach ($this->probes as $probe) {
                $services[] = $this->probe($probe, $checks);
            }
        }
        $services[] = $this->storage();

        $statuses = array_column($services, 'status');

        return [
            'status' => match (true) {
                \in_array(self::DOWN, $statuses, true) => self::DOWN,
                \in_array(self::DEGRADED, $statuses, true) => self::DEGRADED,
                default => self::OPERATIONAL,
            },
            'services' => $services,
        ];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $start = hrtime(true);
        try {
            $this->db->fetchOne('SELECT 1');
            $version = $this->db->fetchOne('SHOW server_version');
        } catch (\Throwable) {
            return ['id' => 'database', 'label' => $this->trans('health.database'), 'status' => self::DOWN, 'detail' => $this->trans('health.unreachable')];
        }

        return [
            'id' => 'database',
            'label' => $this->trans('health.database'),
            'status' => self::OPERATIONAL,
            'detail' => 'PostgreSQL '.explode(' ', (string) $version)[0],
            'latencyMs' => round((hrtime(true) - $start) / 1e6, 1),
        ];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        // Waiting since it became available: a delayed message (retry of a print job…) is not late before its time.
        $row = $this->db->fetchAssociative("SELECT COUNT(*) AS queued, MIN(available_at) AS oldest FROM messenger_messages WHERE queue_name = 'default' AND delivered_at IS NULL");
        $failed = (int) $this->db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
        $delay = null === $row['oldest'] ? 0 : max(0, $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($row['oldest']))->getTimestamp());

        return [
            'id' => 'queue',
            'label' => $this->trans('health.queue'),
            'status' => $delay > self::QUEUE_DELAY_WARNING ? self::DEGRADED : self::OPERATIONAL,
            'detail' => match (true) {
                $delay > self::QUEUE_DELAY_WARNING => $this->trans('health.queue_late'),
                (int) $row['queued'] > 0 => $this->trans('health.queue_running', ['count' => (int) $row['queued']]),
                default => $this->trans('health.queue_empty'),
            },
            'queued' => (int) $row['queued'],
            'failedMessages' => $failed,
        ];
    }

    /** @return array<string, mixed> */
    private function ldap(bool $databaseUp, ?ServiceCheck $check): array
    {
        $config = $this->ldapSettings->get();
        if (!$config->enabled) {
            return ['id' => 'ldap', 'label' => $this->trans('health.ldap'), 'status' => 'disabled', 'detail' => $this->trans('health.not_configured')];
        }

        $row = $databaseUp
            ? $this->db->fetchAssociative("SELECT COUNT(*) AS users, MAX(ldap_synced_at) AS synced FROM \"user\" WHERE source = 'ldap'")
            : ['users' => 0, 'synced' => null];
        $url = preg_replace('#//[^@/]*@#', '//', $config->url);

        return [
            'id' => 'ldap',
            'label' => $this->trans('health.ldap'),
            'status' => null === $check ? self::UNKNOWN : ($check->isOk() ? self::OPERATIONAL : self::DOWN),
            'detail' => null === $check || $check->isOk() ? $url : $url.' · '.$check->getDetail(),
            'users' => (int) $row['users'],
            'lastSyncAt' => null === $row['synced'] ? null : (new \DateTimeImmutable($row['synced']))->format(\DATE_ATOM),
            'latencyMs' => $check?->getLatencyMs(),
            'check' => $check?->toArray(),
        ];
    }

    /**
     * OpenID Connect providers: last check of each enabled one.
     *
     * @param array<string, ServiceCheck> $checks
     *
     * @return array<string, mixed>
     */
    private function sso(array $checks): array
    {
        $items = [];
        $failing = [];
        foreach ($this->servers->findEnabledOidc() as $server) {
            $check = $checks[HealthChecker::oidcCheckId($server)] ?? null;
            if (null !== $check && !$check->isOk()) {
                $failing[] = $server->getName().' ('.$check->getDetail().')';
            }
            $items[] = [
                'id' => (string) $server->getId(),
                'name' => $server->getName(),
                'url' => $server->getUrl(),
                'status' => null === $check ? self::UNKNOWN : ($check->isOk() ? self::OPERATIONAL : self::DOWN),
                'check' => $check?->toArray(),
            ];
        }

        $total = \count($items);
        if (0 === $total) {
            return ['id' => 'sso', 'label' => $this->trans('health.sso'), 'status' => 'disabled', 'detail' => $this->trans('health.no_provider'), 'items' => []];
        }
        $unchecked = \count(array_filter($items, static fn (array $item) => self::UNKNOWN === $item['status']));

        return [
            'id' => 'sso',
            'label' => $this->trans('health.sso'),
            'status' => match (true) {
                \count($failing) === $total => self::DOWN,
                [] !== $failing => self::DEGRADED,
                $unchecked === $total => self::UNKNOWN,
                default => self::OPERATIONAL,
            },
            'detail' => match (true) {
                [] !== $failing => $this->trans('health.failing', ['failing' => \count($failing), 'total' => $total, 'names' => implode(', ', $failing)]),
                $unchecked === $total => $this->trans('health.not_checked'),
                default => implode(', ', array_column($items, 'name')),
            },
            'total' => $total,
            'failing' => \count($failing),
            'items' => $items,
        ];
    }

    /**
     * A dependency declared by a domain module: last check of each of its targets.
     *
     * @param array<string, ServiceCheck> $checks
     *
     * @return array<string, mixed>
     */
    private function probe(ServiceProbeInterface $probe, array $checks): array
    {
        $items = [];
        $failing = [];
        foreach ($probe->targets() as $item => $target) {
            $check = $checks[HealthChecker::probeCheckId($probe, (string) $item)] ?? null;
            if (null !== $check && !$check->isOk()) {
                $failing[] = $target['name'].' ('.$check->getDetail().')';
            }
            $items[] = [
                'id' => (string) $item,
                'name' => $target['name'],
                'status' => null === $check ? self::UNKNOWN : ($check->isOk() ? self::OPERATIONAL : self::DOWN),
                'check' => $check?->toArray(),
            ];
        }

        $total = \count($items);
        if (0 === $total) {
            return ['id' => $probe->id(), 'label' => $probe->label(), 'status' => 'disabled', 'detail' => $this->trans('health.not_configured'), 'items' => []];
        }
        $unchecked = \count(array_filter($items, static fn (array $item) => self::UNKNOWN === $item['status']));

        return [
            'id' => $probe->id(),
            'label' => $probe->label(),
            'status' => match (true) {
                \count($failing) === $total => self::DOWN,
                [] !== $failing => self::DEGRADED,
                $unchecked === $total => self::UNKNOWN,
                default => self::OPERATIONAL,
            },
            'detail' => match (true) {
                [] !== $failing => $this->trans('health.failing', ['failing' => \count($failing), 'total' => $total, 'names' => implode(', ', $failing)]),
                $unchecked === $total => $this->trans('health.not_checked'),
                1 === $total => (string) ($items[0]['check']['detail'] ?? $items[0]['name']),
                default => implode(', ', array_column($items, 'name')),
            },
            'total' => $total,
            'failing' => \count($failing),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0o775, true);
        }
        $directory = is_dir($this->dataDir) ? $this->dataDir : \dirname($this->dataDir);
        $total = @disk_total_space($directory) ?: null;
        $free = @disk_free_space($directory) ?: null;
        $usage = $total && null !== $free ? round(100 * ($total - $free) / $total, 1) : null;
        $writable = is_writable($directory);

        return [
            'id' => 'storage',
            'label' => $this->trans('health.storage'),
            'status' => match (true) {
                !$writable => self::DOWN,
                null !== $usage && $usage >= 90 => self::DEGRADED,
                default => self::OPERATIONAL,
            },
            'detail' => $writable ? $this->trans('health.writable') : $this->trans('health.not_writable'),
            'usagePercent' => $usage,
            'freeBytes' => $free ? (int) $free : null,
        ];
    }

    /** @param array<string, string|int> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return ($this->messages ?? CoreMessages::french())->trans($key, $parameters);
    }
}
