<?php

namespace Rocket\Core\I18n;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Language of the API messages of the core (dashboard, health, updates): the "_rocket_locale" attribute of the
 * current request, set by LocaleListener (or by the brick), else French.
 */
final class Locale
{
    public const ATTRIBUTE = '_rocket_locale';
    public const SUPPORTED = ['fr', 'en'];
    public const DEFAULT = 'fr';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function current(): string
    {
        $locale = $this->requests->getCurrentRequest()?->attributes->get(self::ATTRIBUTE);

        return \is_string($locale) && \in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT;
    }

    /**
     * First of $available in an Accept-Language header ("en-US,en;q=0.9,fr;q=0.8" → "en"), or null.
     *
     * @param list<string> $available
     */
    public static function negotiate(?string $header, array $available = self::SUPPORTED): ?string
    {
        if (null === $header || '' === trim($header)) {
            return null;
        }
        $candidates = [];
        foreach (explode(',', $header) as $index => $part) {
            [$tag, $params] = array_pad(explode(';', trim($part), 2), 2, '');
            $quality = preg_match('/q=([\d.]+)/', $params, $m) ? (float) $m[1] : 1.0;
            $candidates[] = [strtolower(substr(trim($tag), 0, 2)), $quality, $index];
        }
        usort($candidates, static fn (array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        foreach ($candidates as [$language]) {
            if (\in_array($language, $available, true)) {
                return $language;
            }
        }

        return null;
    }
}
