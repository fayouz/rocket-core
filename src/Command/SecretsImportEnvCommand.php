<?php

namespace Rocket\Core\Command;

use Rocket\Core\Secrets\SecretVault;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves integration keys from the environment into the vault: every non-empty variable whose name starts with the
 * prefix (CONNECTOR_, or an exact name such as LODGIFY_API_KEY) becomes a secret of the same name, lowercase
 * (CONNECTOR_NUKI_TOKEN → connector_nuki_token) unless --keep-case. Existing secrets are kept unless --overwrite.
 * The variables are then to be removed from the .env by hand.
 */
#[AsCommand(name: 'rocket:secrets:import-env', description: 'Import environment variables (by prefix) into the secrets vault.')]
final class SecretsImportEnvCommand
{
    public function __construct(private readonly SecretVault $vault)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Prefix of the variables, e.g. CONNECTOR_ (or a full name)')] string $prefix,
        #[Option(description: 'Replace the secrets that already exist')] bool $overwrite = false,
        #[Option(description: 'Keep the variable name as is instead of lowercasing it')] bool $keepCase = false,
        #[Option(description: 'Show what would be imported, without importing')] bool $dryRun = false,
        #[Option(description: 'Scope of the secrets (empty: the instance)')] ?string $scope = null,
    ): int {
        if ('' === $prefix) {
            $io->error('A prefix is required.');

            return Command::FAILURE;
        }
        if (!$dryRun && !$this->vault->isConfigured()) {
            $io->error((string) $this->vault->configurationError());

            return Command::FAILURE;
        }
        $scope = $scope ?: null;

        $variables = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                if (\is_string($name) && \is_string($value) && '' !== $value && str_starts_with($name, $prefix) && !str_starts_with($name, 'ROCKET_SECRETS_KEY')) {
                    $variables[$name] ??= $value;
                }
            }
        }
        ksort($variables);

        $rows = [];
        $imported = 0;
        foreach ($variables as $variable => $value) {
            $name = $keepCase ? $variable : strtolower($variable);
            $exists = $this->vault->has($name, $scope);
            if ($exists && !$overwrite) {
                $rows[] = [$variable, $name, 'kept (exists)'];
                continue;
            }
            if (!$dryRun) {
                $this->vault->set($name, $value, $scope);
            }
            ++$imported;
            $rows[] = [$variable, $name, $dryRun ? 'would be imported' : ($exists ? 'replaced' : 'imported')];
        }

        if (!$rows) {
            $io->warning(\sprintf('No non-empty environment variable starts with "%s".', $prefix));

            return Command::SUCCESS;
        }
        $io->table(['Variable', 'Secret', 'Result'], $rows);
        $io->success(\sprintf('%d secret(s) %s.%s', $imported, $dryRun ? 'to import' : 'imported', $dryRun ? '' : ' Remove the variables from the .env once the application reads them from the vault.'));

        return Command::SUCCESS;
    }
}
