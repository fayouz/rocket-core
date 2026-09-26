<?php

namespace Rocket\Core\Enum;

enum AuthenticationServerType: string
{
    case Ldap = 'ldap';
    case Oidc = 'oidc';
}
