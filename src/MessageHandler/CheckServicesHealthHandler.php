<?php

namespace Rocket\Core\MessageHandler;

use Rocket\Core\Health\HealthChecker;
use Rocket\Core\Message\CheckServicesHealth;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckServicesHealthHandler
{
    public function __construct(private readonly HealthChecker $checker)
    {
    }

    public function __invoke(CheckServicesHealth $message): void
    {
        $this->checker->checkAll();
    }
}
