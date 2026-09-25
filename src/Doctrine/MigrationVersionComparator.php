<?php

namespace Rocket\Core\Doctrine;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * Orders migrations by their timestamp (VersionYYYYMMDDHHMMSS), whatever their namespace: the core tables are created
 * before the application's, and later core migrations interleave with the application's by date.
 */
final class MigrationVersionComparator implements Comparator
{
    public function compare(Version $a, Version $b): int
    {
        return [self::timestamp($a), (string) $a] <=> [self::timestamp($b), (string) $b];
    }

    private static function timestamp(Version $version): string
    {
        return preg_match('/Version(\d+)/', (string) $version, $m) ? $m[1] : (string) $version;
    }
}
