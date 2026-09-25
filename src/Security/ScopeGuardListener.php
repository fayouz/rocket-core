<?php

namespace Rocket\Core\Security;

use Rocket\Core\Embed\EmbedEndpointsInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restricts delegated sessions to an allow-list of endpoints:
 * - embed sessions (ROLE_EMBED) can only call the endpoints declared by the brick (EmbedEndpointsInterface);
 * - applications that do not impersonate anyone can only identify themselves.
 * Runs after the firewall (priority 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class ScopeGuardListener
{
    private const APPLICATION_ALLOWED = [
        ['GET', '#^/api/me$#'],
    ];

    private const EMBED_ALLOWED = [
        ['GET', '#^/api/me$#'],
        ['GET', '#^/api/embed/context$#'],
    ];

    /** @param iterable<EmbedEndpointsInterface> $embedEndpoints */
    public function __construct(
        private readonly Security $security,
        #[AutowireIterator('rocket.embed_endpoints')]
        private readonly iterable $embedEndpoints = [],
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api') || $request->isMethod('OPTIONS')) {
            return;
        }

        $user = $this->security->getUser();
        $allowed = match (true) {
            $user instanceof ApplicationUser => self::APPLICATION_ALLOWED,
            null !== $user && $this->security->isGranted(Roles::EMBED) => $this->embedAllowed(),
            default => null,
        };

        if (null === $allowed) {
            return;
        }

        foreach ($allowed as [$method, $pattern]) {
            if ($request->isMethod($method) && preg_match($pattern, $request->getPathInfo())) {
                return;
            }
        }

        throw new AccessDeniedHttpException('This endpoint is not available with the current credentials.');
    }

    /** @return list<array{0: string, 1: string}> */
    private function embedAllowed(): array
    {
        $allowed = self::EMBED_ALLOWED;
        foreach ($this->embedEndpoints as $provider) {
            foreach ($provider->embedEndpoints() as $endpoint) {
                $allowed[] = $endpoint;
            }
        }

        return $allowed;
    }
}
