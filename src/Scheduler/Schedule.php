<?php

namespace Rocket\Core\Scheduler;

use Rocket\Core\Message\CheckServicesHealth;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring tasks, run by the worker: `messenger:consume async scheduler_default`.
 * Domain modules add theirs with a RecurringTaskProviderInterface.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    /** @param iterable<RecurringTaskProviderInterface> $providers */
    public function __construct(
        private readonly CacheInterface $cache,
        #[AutowireIterator('rocket.recurring_task_provider')] private readonly iterable $providers = [],
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            // Network checks of the LDAP server, the OpenID Connect providers and the domain dependencies, shown on the dashboard.
            ->add(RecurringMessage::every('5 minutes', new CheckServicesHealth()));
        foreach ($this->providers as $provider) {
            foreach ($provider->recurringMessages() as $message) {
                $schedule->add($message);
            }
        }

        return $schedule;
    }
}
