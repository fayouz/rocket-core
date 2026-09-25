<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/** Routes of the core API, imported by the application: `rocket_core: { resource: '@RocketCoreBundle/config/routes.php' }`. */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../src/Controller/', 'attribute');
};
