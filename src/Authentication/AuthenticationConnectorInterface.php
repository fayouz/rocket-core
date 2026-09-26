<?php

namespace Rocket\Core\Authentication;

use Rocket\Core\Enum\AuthenticationServerType;
use Rocket\Core\Ldap\UserDirectoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.authentication_connector')]
interface AuthenticationConnectorInterface extends UserDirectoryInterface
{
    public function supports(AuthenticationServerType $type): bool;
}
