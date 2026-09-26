<?php

namespace Rocket\Core\Repository;

use Rocket\Core\Entity\ColorPalette;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ColorPalette> */
class ColorPaletteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ColorPalette::class);
    }
}
