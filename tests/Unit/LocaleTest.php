<?php

namespace Rocket\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rocket\Core\I18n\CoreMessages;
use Rocket\Core\I18n\Locale;
use Rocket\Core\I18n\LocaleListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LocaleTest extends TestCase
{
    public function testNegotiatesTheFirstAvailableLanguageByQuality(): void
    {
        self::assertSame('en', Locale::negotiate('en-US,en;q=0.9,fr;q=0.8'));
        self::assertSame('fr', Locale::negotiate('de-DE,fr;q=0.7,en;q=0.5'));
        self::assertSame('fr', Locale::negotiate('en;q=0.4,fr-CA;q=0.9'));
        self::assertNull(Locale::negotiate('de,es'));
        self::assertNull(Locale::negotiate(null));
        self::assertNull(Locale::negotiate('en-US', ['fr']));
    }

    public function testApplicationsOnlyFollowTheLanguagesTheyOffer(): void
    {
        $messages = static function (array $locales, string $header): string {
            $request = Request::create('/api/dashboard', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);
            (new LocaleListener($locales))(new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
            $stack = new RequestStack();
            $stack->push($request);

            return (new CoreMessages(new Locale($stack)))->trans('health.queue_running', ['count' => 2]);
        };

        self::assertSame('2 tâche(s) en cours', $messages(['fr'], 'en-US,en;q=0.9'));
        self::assertSame('2 job(s) running', $messages(['fr', 'en'], 'en-US,en;q=0.9'));
        self::assertSame('2 tâche(s) en cours', $messages(['fr', 'en'], 'de-DE'));
        self::assertSame('Base de données', CoreMessages::french()->trans('health.database'));
    }
}
