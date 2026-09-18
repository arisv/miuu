<?php

namespace App\Entity;

use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-device API credential issued by the native app login. Only the sha256 of the token is stored;
 * the plaintext is shown once. Revocation is soft so the device list keeps its history readable.
 */
#[ORM\Table(name: 'device_tokens')]
#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
class DeviceToken
{
    public const PLATFORMS = ['ios', 'android', 'macos', 'other'];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20)]
    private string $platform = 'other';

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'string', length: 8)]
    private string $tokenPrefix;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $name, string $platform, string $tokenHash, string $tokenPrefix)
    {
        $this->user = $user;
        $this->name = $name;
        $this->platform = in_array($platform, self::PLATFORMS, true) ? $platform : 'other';
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function touch(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }
}
