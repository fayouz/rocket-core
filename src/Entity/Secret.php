<?php

namespace Rocket\Core\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Repository\SecretRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A secret of the vault (API key, token, password of an integration), encrypted with ROCKET_SECRETS_KEY
 * (see Rocket\Core\Secrets\SecretVault). Never exposed: the API gives the name, a masked preview and the dates.
 *
 * Scope: null for the instance; later the identifier of an account (multi-account), a name being unique per scope
 * (for the null scope, PostgreSQL does not enforce it: SecretVault::set() replaces the existing secret).
 */
#[ORM\Entity(repositoryClass: SecretRepository::class)]
#[ORM\UniqueConstraint(name: 'secret_scope_name', columns: ['scope', 'name'])]
class Secret
{
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,99}$/';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $scope;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $ciphertext = '';

    /** Identifier of the master key that encrypted the value (rotation). */
    #[ORM\Column(length: 16)]
    private string $keyId = '';

    /** Last 4 characters of long values (12 characters or more), for the masked preview. */
    #[ORM\Column(length: 4, nullable: true)]
    private ?string $hint = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    use TrackedTrait;

    public function __construct(string $name, ?string $scope = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->scope = $scope;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCiphertext(): string
    {
        return $this->ciphertext;
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    public function setEncrypted(string $ciphertext, string $keyId, ?string $hint): static
    {
        $this->ciphertext = $ciphertext;
        $this->keyId = $keyId;
        $this->hint = $hint;

        return $this;
    }

    /** "••••1234" for a long value, "••••••••" otherwise. */
    public function getMasked(): string
    {
        return null === $this->hint ? '••••••••' : '••••'.$this->hint;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /** Associated data of the encryption: binds the ciphertext to this secret. */
    public function context(): string
    {
        return ($this->scope ?? '').'|'.$this->name;
    }
}
