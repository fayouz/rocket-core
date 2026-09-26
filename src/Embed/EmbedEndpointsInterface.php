<?php

namespace Rocket\Core\Embed;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Endpoints an embedded page (iframe opened by an application for one of its users) may call,
 * besides GET /api/me and GET /api/embed/context: an embed session never reaches anything else.
 */
#[AutoconfigureTag('rocket.embed_endpoints')]
interface EmbedEndpointsInterface
{
    /** @return iterable<array{0: string, 1: string}> [HTTP method, regular expression on the path] */
    public function embedEndpoints(): iterable;
}
