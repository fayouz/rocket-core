<?php

namespace Rocket\Core\Secrets;

/** ROCKET_SECRETS_KEY is missing or invalid: nothing can be stored nor read (never in clear). */
final class SecretsKeyMissingException extends SecretsException
{
}
