<?php

namespace Rocket\Core\Theme;

final readonly class ProjectThemeInput
{
    public function __construct(
        /** IRI or id of the project's color palette; "" or null goes back to the default colors. */
        public ?string $palette = null,
    ) {
    }
}
