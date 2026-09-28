<?php

namespace Rocket\Core\Secrets;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Rocket\Core\Entity\Secret;
use Rocket\Core\Repository\SecretRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The secrets vault: API keys, tokens and passwords of the integrations, encrypted in the database with
 * ROCKET_SECRETS_KEY instead of living in the .env.
 *
 *     $apiKey = $vault->get('lodgify.api_key');                         // throws when missing
 *     $apiKey = $vault->getOrEnv('lodgify.api_key', 'LODGIFY_API_KEY'); // transition: the .env as a fallback
 *
 * Scope: null for the instance (today's only use); an account identifier later.
 * Writes are logged (never the value); reads update Secret::$lastUsedAt.
 */
class SecretVault
{
    /** @var array<string, string> decrypted values of this request/message */
    private array $cache = [];

    public function __construct(
        private readonly SecretRepository $secrets,
        private readonly EntityManagerInterface $em,
        private readonly SecretsKeyring $keyring,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->keyring->isConfigured();
    }

    /** Why the vault cannot be used (missing or invalid ROCKET_SECRETS_KEY), null when it can. */
    public function configurationError(): ?string
    {
        return $this->keyring->error();
    }

    /** @throws SecretNotFoundException|SecretsException */
    public function get(string $name, ?string $scope = null): string
    {
        $key = ($scope ?? '').'|'.$name;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $secret = $this->secrets->findOne($name, $scope) ?? throw SecretNotFoundException::named($name, $scope);
        $plain = $this->keyring->decrypt($secret->getCiphertext(), $secret->getKeyId(), $secret->context());
        $this->secrets->touch($secret, $this->clock->now());

        return $this->cache[$key] = $plain;
    }

    /** Null when the secret does not exist (the vault must still be configured to read one that does). */
    public function find(string $name, ?string $scope = null): ?string
    {
        try {
            return $this->get($name, $scope);
        } catch (SecretNotFoundException) {
            return null;
        }
    }

    /**
     * Transition helper for the bricks: the secret of the vault, else the environment variable (logged as deprecated,
     * import it with `rocket:secrets:import-env`), else null. A vault without key falls back to the environment too.
     */
    public function getOrEnv(string $name, string $envVar, ?string $scope = null): ?string
    {
        if ($this->keyring->isConfigured() && null !== $value = $this->find($name, $scope)) {
            return $value;
        }
        $env = self::env($envVar);
        if (null !== $env && '' !== $env) {
            $this->logger->warning('Secret "{name}" read from the environment variable {env}: deprecated, move it to the vault (php bin/console rocket:secrets:import-env).', ['name' => $name, 'env' => $envVar]);

            return $env;
        }

        return null;
    }

    public function has(string $name, ?string $scope = null): bool
    {
        return null !== $this->secrets->findOne($name, $scope);
    }

    /** Creates or replaces a secret (flushed). */
    public function set(string $name, #[\SensitiveParameter] string $value, ?string $scope = null): Secret
    {
        self::assertName($name);
        if ('' === $value) {
            throw new \InvalidArgumentException('A secret cannot be empty.');
        }
        $secret = $this->secrets->findOne($name, $scope);
        $created = null === $secret;
        $secret ??= new Secret($name, $scope);
        [$ciphertext, $keyId] = $this->keyring->encrypt($value, $secret->context());
        $secret->setEncrypted($ciphertext, $keyId, mb_strlen($value) >= 12 ? mb_substr($value, -4) : null);
        $this->em->persist($secret);
        $this->em->flush();
        $this->cache[$secret->context()] = $value;
        $this->logger->notice($created ? 'Secret "{name}" created in the vault.' : 'Secret "{name}" replaced in the vault.', ['name' => $name, 'scope' => $scope]);

        return $secret;
    }

    /** @return bool false when there was no such secret */
    public function delete(string $name, ?string $scope = null): bool
    {
        $secret = $this->secrets->findOne($name, $scope);
        if (null === $secret) {
            return false;
        }
        $this->em->remove($secret);
        $this->em->flush();
        unset($this->cache[$secret->context()]);
        $this->logger->notice('Secret "{name}" deleted from the vault.', ['name' => $name, 'scope' => $scope]);

        return true;
    }

    /**
     * Names and masked previews, never the values.
     *
     * @return list<Secret> every scope when $scope is false
     */
    public function list(string|null|false $scope = null): array
    {
        return $this->secrets->findByScope($scope);
    }

    /**
     * Re-encrypts with the current key the secrets encrypted with ROCKET_SECRETS_KEY_PREVIOUS.
     *
     * @return array{rotated: int, current: int, failed: list<string>}
     */
    public function rotate(): array
    {
        $current = $this->keyring->currentKeyId();
        $report = ['rotated' => 0, 'current' => 0, 'failed' => []];
        foreach ($this->secrets->findByScope(false) as $secret) {
            if ($secret->getKeyId() === $current) {
                ++$report['current'];
                continue;
            }
            try {
                $plain = $this->keyring->decrypt($secret->getCiphertext(), $secret->getKeyId(), $secret->context());
            } catch (SecretsException $e) {
                $report['failed'][] = \sprintf('%s: %s', $secret->getName(), $e->getMessage());
                continue;
            }
            [$ciphertext, $keyId] = $this->keyring->encrypt($plain, $secret->context());
            $secret->setEncrypted($ciphertext, $keyId, mb_strlen($plain) >= 12 ? mb_substr($plain, -4) : null);
            ++$report['rotated'];
        }
        $this->em->flush();
        $this->logger->notice('Secrets vault rotated: {rotated} re-encrypted with the current key.', ['rotated' => $report['rotated']]);

        return $report;
    }

    /** @return list<Secret> secrets encrypted with a key that is neither the current nor the previous one */
    public function unreadable(): array
    {
        return array_values(array_filter($this->secrets->findByScope(false), fn (Secret $s) => !$this->keyring->knows($s->getKeyId())));
    }

    public static function assertName(string $name): void
    {
        if (!preg_match(Secret::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('Invalid secret name: letters, digits, ".", "_" and "-" (100 characters at most), e.g. "lodgify.api_key".');
        }
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) ? $value : null;
    }
}
