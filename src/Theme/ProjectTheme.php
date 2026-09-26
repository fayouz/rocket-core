<?php

namespace Rocket\Core\Theme;

use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\ColorPalette;
use Rocket\Core\Repository\ColorPaletteRepository;
use Rocket\Core\Settings\Settings;
use Symfony\Component\Uid\Uuid;

/**
 * Palette in use: the application's one for the pages it embeds, else the project's one, else none (the brick's
 * default colors, app.config.ts).
 */
class ProjectTheme
{
    /** Setting holding the id of the project's palette (same name as in Rocket Mailer before rocket-core 0.2). */
    public const PROJECT_PALETTE = 'theme.palette';

    public function __construct(
        private readonly Settings $settings,
        private readonly ColorPaletteRepository $palettes,
    ) {
    }

    public function projectPalette(): ?ColorPalette
    {
        $id = $this->settings->get(self::PROJECT_PALETTE);

        // A deleted palette falls back to the default colors.
        return \is_string($id) && Uuid::isValid($id) ? $this->palettes->find($id) : null;
    }

    /** Persists the choice; the caller flushes. */
    public function setProjectPalette(?ColorPalette $palette): void
    {
        null === $palette ? $this->settings->remove(self::PROJECT_PALETTE) : $this->settings->set(self::PROJECT_PALETTE, (string) $palette->getId());
    }

    /**
     * The palette an IRI ("/api/color_palettes/<id>") or an id designates; null for "" (the default colors).
     *
     * @throws \InvalidArgumentException unknown palette
     */
    public function resolve(string $reference): ?ColorPalette
    {
        $id = basename($reference);
        if ('' === $id) {
            return null;
        }

        return (Uuid::isValid($id) ? $this->palettes->find($id) : null) ?? throw new \InvalidArgumentException('Unknown color palette.');
    }

    /** @return array{source: 'application'|'project'|'default', palette: array{id: string, name: string, colors: array<string, string>, neutral: string}|null} */
    public function for(?Application $application): array
    {
        if (null !== $palette = $application?->getPalette()) {
            return ['source' => 'application', 'palette' => $palette->toTheme()];
        }
        if (null !== $palette = $this->projectPalette()) {
            return ['source' => 'project', 'palette' => $palette->toTheme()];
        }

        return ['source' => 'default', 'palette' => null];
    }
}
