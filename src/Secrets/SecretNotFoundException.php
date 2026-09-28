<?php

namespace Rocket\Core\Secrets;

final class SecretNotFoundException extends SecretsException
{
    public static function named(string $name, ?string $scope): self
    {
        return new self(\sprintf('No secret "%s"%s in the vault.', $name, null === $scope ? '' : \sprintf(' (scope %s)', $scope)));
    }
}
