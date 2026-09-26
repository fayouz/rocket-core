<?php

namespace Rocket\Core\Suite;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Before the sign-in endpoints, the Rocket Auth provider matches the suite configuration. */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class SuiteRequestListener
{
    private const PATHS = '#^/api/(auth/providers|auth/oidc/callback|suite|setup)$#';

    public function __construct(private readonly SuiteProvisioner $provisioner)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() && preg_match(self::PATHS, $event->getRequest()->getPathInfo())) {
            $this->provisioner->syncOnce();
        }
    }
}
