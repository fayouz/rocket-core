<?php

namespace Rocket\Core\Secrets;

use Rocket\Core\Health\ServiceProbeInterface;
use Rocket\Core\Repository\SecretRepository;

/**
 * Dashboard check of the vault: fails when secrets are stored but ROCKET_SECRETS_KEY is missing or invalid, or when
 * some were encrypted with a key that is no longer configured. Nothing is checked while the vault is empty and unused.
 */
final class SecretsProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly SecretsKeyring $keyring,
        private readonly SecretVault $vault,
        private readonly SecretRepository $secrets,
    ) {
    }

    public function id(): string
    {
        return 'secrets';
    }

    public function label(): string
    {
        return 'Coffre des secrets';
    }

    public function targets(): iterable
    {
        $count = $this->secrets->count([]);
        // Empty vault: only an invalid key is worth a warning (a missing one is shown on the Secrets page).
        if (0 === $count && ($this->keyring->isConfigured() || !$this->keyring->isProvided())) {
            return;
        }

        yield 'vault' => ['name' => 'Clé maîtresse (ROCKET_SECRETS_KEY)', 'check' => function () use ($count): string {
            if (!$this->keyring->isConfigured()) {
                throw new SecretsKeyMissingException((string) $this->keyring->error());
            }
            if ([] !== $unreadable = $this->vault->unreadable()) {
                throw new SecretsException(\sprintf('%d secret(s) chiffré(s) avec une clé inconnue : définir ROCKET_SECRETS_KEY_PREVIOUS puis lancer rocket:secrets:rotate.', \count($unreadable)));
            }

            return \sprintf('%d secret(s) déchiffrable(s)', $count);
        }];
    }
}
