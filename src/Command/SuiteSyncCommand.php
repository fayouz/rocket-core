<?php

namespace Rocket\Core\Command;

use Rocket\Core\Suite\SuiteProvisioner;
use Rocket\Core\Suite\SuiteSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Applies the suite configuration (ROCKET_AUTH_*) now, e.g. at deployment; sign-in does it on its own anyway. */
#[AsCommand(name: 'rocket:suite:sync', description: 'Declare (suite mode) or release (standalone mode) the Rocket Auth authentication server.')]
final class SuiteSyncCommand
{
    public function __construct(
        private readonly SuiteSettings $suite,
        private readonly SuiteProvisioner $provisioner,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $server = $this->provisioner->sync();
        if (null === $server) {
            $io->success('Standalone mode: accounts are managed by this application.');
        } else {
            $io->success(\sprintf('Suite mode: sign-in through %s (%s, client "%s").', $server->getName(), $server->getUrl(), $server->getClientId()));
            $uri = $this->provisioner->register(true);
            if (null !== $uri) {
                $io->success(\sprintf('Back-channel logout endpoint declared to Rocket Auth: %s', $uri));
            } elseif (null === $this->suite->backchannelLogoutUri()) {
                $io->warning('No address for this application (ROCKET_PUBLIC_URL, ROCKET_INTERNAL_URL or FRONTEND_URL): Rocket Auth cannot sign users out of it (back-channel logout).');
            } else {
                $io->warning('The back-channel logout endpoint could not be declared to Rocket Auth: see the logs.');
            }
        }

        return Command::SUCCESS;
    }
}
