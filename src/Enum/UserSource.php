<?php

namespace Rocket\Core\Enum;

enum UserSource: string
{
    case Local = 'local';
    case Ldap = 'ldap';
    case Oidc = 'oidc';
}
