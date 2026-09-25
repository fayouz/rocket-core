<?php

namespace Rocket\Core\Command;

use Rocket\Core\Health\HealthChecker;
use Rocket\Core\Repository\ServiceCheckRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:health:check', description: 'Check the LDAP server and the sending mailboxes now (the worker also does it every 5 minutes).')]
final class HealthCheckCommand
{
    public function __construct(
        private readonly HealthChecker $checker,
        private readonly ServiceCheckRepository $checks,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        // Waits for checks already running (the worker's), then checks again.
        $this->checker->checkAll(wait: true);

        $rows = [];
        $failing = false;
        foreach ($this->checks->findBy([], ['id' => 'ASC']) as $check) {
            $rows[] = [$check->getId(), $check->isOk() ? 'OK' : 'FAILING', $check->getDetail()];
            $failing = $failing || !$check->isOk();
        }
        $rows ? $io->table(['Check', 'Status', 'Detail'], $rows) : $io->note('Nothing to check: LDAP is off, and no OpenID Connect provider nor domain service to check.');

        return $failing ? Command::FAILURE : Command::SUCCESS;
    }
}
