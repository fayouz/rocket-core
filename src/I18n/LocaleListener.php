<?php

namespace Rocket\Core\I18n;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The interface sends its language (Accept-Language, see useApi): API messages follow it among the application's
 * languages (rocket_core.locales). Otherwise the first of them applies: French for applications that did not add any.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
final class LocaleListener
{
    /** @param list<string> $locales */
    public function __construct(#[Autowire(param: 'rocket_core.locales')] private readonly array $locales = ['fr'])
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }
        $locale = Locale::negotiate($request->headers->get('Accept-Language'), $this->locales) ?? $this->locales[0] ?? Locale::DEFAULT;
        $request->attributes->set(Locale::ATTRIBUTE, $locale);
        $request->setLocale($locale);
    }
}
