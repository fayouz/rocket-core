<?php

use Rocket\Core\Doctrine\MigrationVersionComparator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load('Rocket\\Core\\', '../src/')
        ->exclude(['../src/Entity/', '../src/RocketCoreBundle.php']);

    $services->set(MigrationVersionComparator::class);
};
