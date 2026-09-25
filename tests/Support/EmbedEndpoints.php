<?php

namespace Rocket\Core\Tests\Support;

use Rocket\Core\Embed\EmbedEndpointsInterface;

/** The embedded page of the test application: reads the version, nothing else. */
final class EmbedEndpoints implements EmbedEndpointsInterface
{
    public function embedEndpoints(): iterable
    {
        yield ['GET', '#^/api/system/version$#'];
    }
}
