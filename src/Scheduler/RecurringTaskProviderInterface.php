<?php

namespace Rocket\Core\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Scheduler\RecurringMessage;

/** Recurring tasks of a domain module, added to the schedule run by the worker (`messenger:consume async scheduler_default`). */
#[AutoconfigureTag('rocket.recurring_task_provider')]
interface RecurringTaskProviderInterface
{
    /** @return iterable<RecurringMessage> */
    public function recurringMessages(): iterable;
}
