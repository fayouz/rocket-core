<?php

namespace Rocket\Core\Command;

use Rocket\Core\Secrets\SecretsKeyring;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rocket:secrets:generate-key', description: 'Print a new master key for ROCKET_SECRETS_KEY (base64 of 32 random bytes).')]
final class SecretsGenerateKeyCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $output->writeln(SecretsKeyring::generateKey());

        return Command::SUCCESS;
    }
}
