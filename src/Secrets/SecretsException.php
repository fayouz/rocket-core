<?php

namespace Rocket\Core\Secrets;

/** The vault cannot give a secret: unknown key, altered ciphertext… */
class SecretsException extends \RuntimeException
{
}
