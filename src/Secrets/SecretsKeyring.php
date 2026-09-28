<?php

namespace Rocket\Core\Secrets;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Master keys of the secrets vault: ROCKET_SECRETS_KEY (current, base64 of 32 random bytes) and, during a rotation,
 * ROCKET_SECRETS_KEY_PREVIOUS. Each key is known by a short identifier stored with the ciphertext, so a secret
 * encrypted with the previous key stays readable until `rocket:secrets:rotate` re-encrypts it.
 *
 * Encryption: XChaCha20-Poly1305 (libsodium AEAD, 24-byte random nonce), the scope and name of the secret as
 * associated data (a ciphertext copied onto another secret does not decrypt).
 */
final class SecretsKeyring
{
    /** @var array<string, string> key id => raw key; the current one first */
    private array $keys = [];
    private ?string $error = null;
    private bool $provided;

    public function __construct(
        #[Autowire(env: 'ROCKET_SECRETS_KEY')] #[\SensitiveParameter] string $key,
        #[Autowire(env: 'ROCKET_SECRETS_KEY_PREVIOUS')] #[\SensitiveParameter] string $previousKey = '',
    ) {
        $this->provided = '' !== trim($key);
        if (!$this->provided) {
            $this->error = 'ROCKET_SECRETS_KEY is not set: generate one with `php bin/console rocket:secrets:generate-key` and add it to the environment.';

            return;
        }
        foreach (['ROCKET_SECRETS_KEY' => $key, 'ROCKET_SECRETS_KEY_PREVIOUS' => $previousKey] as $variable => $value) {
            if ('' === trim($value)) {
                continue;
            }
            $raw = base64_decode(trim($value), true);
            if (false === $raw || \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($raw)) {
                $this->keys = [];
                $this->error = \sprintf('%s is invalid: base64 of exactly 32 bytes expected (`php bin/console rocket:secrets:generate-key`).', $variable);

                return;
            }
            $this->keys[self::keyId($raw)] ??= $raw;
        }
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
    }

    public function isConfigured(): bool
    {
        return null === $this->error;
    }

    /** ROCKET_SECRETS_KEY is set (maybe invalid). */
    public function isProvided(): bool
    {
        return $this->provided;
    }

    /** Why the vault cannot be used, null when it can. */
    public function error(): ?string
    {
        return $this->error;
    }

    public function currentKeyId(): string
    {
        $this->assertConfigured();

        return array_key_first($this->keys);
    }

    public function knows(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    /** @return array{0: string, 1: string} the ciphertext (base64 of nonce and box) and the id of the key used */
    public function encrypt(#[\SensitiveParameter] string $plain, string $context): array
    {
        $keyId = $this->currentKeyId();
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $box = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $context, $nonce, $this->keys[$keyId]);

        return [base64_encode($nonce.$box), $keyId];
    }

    public function decrypt(string $ciphertext, string $keyId, string $context): string
    {
        $this->assertConfigured();
        if (!isset($this->keys[$keyId])) {
            throw new SecretsException(\sprintf('A secret was encrypted with an unknown key (%s): set it as ROCKET_SECRETS_KEY_PREVIOUS, then run rocket:secrets:rotate.', $keyId));
        }
        $raw = base64_decode($ciphertext, true);
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plain = false === $raw || \strlen($raw) <= $nonceLength ? false : sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nonceLength), $context, substr($raw, 0, $nonceLength), $this->keys[$keyId],
        );
        if (false === $plain) {
            throw new SecretsException('A stored secret cannot be decrypted (altered ciphertext).');
        }

        return $plain;
    }

    private function assertConfigured(): void
    {
        if (null !== $this->error) {
            throw new SecretsKeyMissingException($this->error);
        }
    }

    private static function keyId(string $raw): string
    {
        return substr(bin2hex(sodium_crypto_generichash('rocket/secrets-key-id|'.$raw, '', 16)), 0, 12);
    }
}
