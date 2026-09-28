<?php

namespace Rocket\Core\Command;

use Rocket\Core\Secrets\SecretVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'rocket:secrets:rotate',
    description: 'Re-encrypt the vault with ROCKET_SECRETS_KEY. Rotation: move the old key to ROCKET_SECRETS_KEY_PREVIOUS, set a new ROCKET_SECRETS_KEY, run this command, then remove ROCKET_SECRETS_KEY_PREVIOUS.',
)]
final class SecretsRotateCommand
{
    public function __construct(private readonly SecretVault $vault)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->vault->isConfigured()) {
            $io->error((string) $this->vault->configurationError());

            return Command::FAILURE;
        }
        $report = $this->vault->rotate();
        $io->success(\sprintf('%d secret(s) re-encrypted, %d already with the current key.', $report['rotated'], $report['current']));
        if ($report['failed']) {
            $io->error(['Not re-encrypted:', ...$report['failed']]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
